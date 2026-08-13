<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Batch;

use Codelot\AddressParser\AddressParserInterface;
use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\Quality\Issue;
use Codelot\AddressParser\Quality\QualityInspector;
use Codelot\AddressParser\RuleBasedParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs a parser over many addresses — a migration, a nightly re-parse, a one-off pass over an
 * export — and reports what happened.
 *
 * Two things it guarantees that a plain foreach does not: one bad address cannot stop the run, and
 * the run produces numbers rather than a feeling. Both matter at the scale this is used for, where
 * nobody is watching each result and a silent degradation is the failure mode.
 */
final class BatchParser
{
    public function __construct(
        private readonly AddressParserInterface $parser = new RuleBasedParser(),
        private readonly LoggerInterface $logger = new NullLogger(),
        /**
         * The batch inspects results itself rather than trusting `issues` on them: only the
         * escalating parser fills that field, so a batch over the bare rule engine would otherwise
         * report a clean run over addresses it never examined.
         */
        private readonly QualityInspector $inspector = new QualityInspector(),
        /** Failures kept in the summary; the rest are logged only, so a bad export cannot exhaust memory. */
        private readonly int $failureLimit = 100,
    ) {
    }

    /**
     * Parses everything and returns the tally.
     *
     * @param iterable<int|string, string>                      $addresses
     * @param (callable(string, ParsedAddress, int|string): void)|null $onResult receives every result as it is produced
     */
    public function run(iterable $addresses, ?callable $onResult = null, bool $spaceInPostCode = false): BatchSummary
    {
        $started = microtime(true);
        $total = 0;
        $bySource = [];
        $byIssue = [];
        $withIssues = 0;
        $failures = [];

        foreach ($addresses as $key => $address) {
            ++$total;

            try {
                $result = $this->parser->parse($address, $spaceInPostCode);
            } catch (\Throwable $e) {
                // One malformed row must not end a run of a hundred thousand.
                $this->logger->error('batch: address could not be parsed', [
                    'key' => $key,
                    'address' => $address,
                    'error' => $e->getMessage(),
                ]);

                if (count($failures) < $this->failureLimit) {
                    $failures[] = (string) $address;
                }

                continue;
            }

            $source = $result->source ?? 'unknown';
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;

            $issues = $this->inspector->inspect($address, $result);

            if ([] !== $issues) {
                ++$withIssues;

                foreach ($issues as $issue) {
                    $byIssue[$issue->value] = ($byIssue[$issue->value] ?? 0) + 1;
                }
            }

            if (null !== $onResult) {
                $onResult($address, $result, $key);
            }
        }

        $byIssue['__any__'] = $withIssues;

        $summary = new BatchSummary(
            total: $total,
            bySource: $bySource,
            byIssue: $byIssue,
            failures: $failures,
            seconds: microtime(true) - $started,
        );

        $this->logger->info('batch finished', $summary->toArray());

        return $summary;
    }

    /**
     * The same pass, lazily: yields each result as it is parsed, so a million-row export never
     * needs to fit in memory. Failures are logged and skipped.
     *
     * @param iterable<int|string, string> $addresses
     *
     * @return \Generator<int|string, ParsedAddress>
     */
    public function map(iterable $addresses, bool $spaceInPostCode = false): \Generator
    {
        foreach ($addresses as $key => $address) {
            try {
                yield $key => $this->parser->parse($address, $spaceInPostCode);
            } catch (\Throwable $e) {
                $this->logger->error('batch: address could not be parsed', [
                    'key' => $key,
                    'address' => $address,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
