<?php

declare(strict_types=1);

namespace Address\Parser;

use Address\Parser\Quality\Issue;
use Address\Parser\Quality\QualityInspector;
use Address\Parser\Refiner\RefinerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the rule engine first, then escalates to the configured refiners — but only for addresses
 * that came out wrong, and only while they are still wrong.
 *
 * The economics are the point. Rules are free and handle the overwhelming majority; an LLM or a
 * geocoding service is worth its cost on the remainder, where a rule that generalises does not
 * exist. Escalation stops as soon as the result is clean, so the expensive path stays rare.
 */
final class EscalatingParser implements AddressParserInterface
{
    /**
     * @param list<RefinerInterface> $refiners      consulted in order, until the result is clean
     * @param list<Issue>            $escalateOn    which issues justify escalating; empty = any issue
     */
    public function __construct(
        private readonly AddressParserInterface $base = new RuleBasedParser(),
        private readonly array $refiners = [],
        private readonly QualityInspector $inspector = new QualityInspector(),
        private readonly array $escalateOn = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function parse(string $address, bool $spaceInPostCode = false): ParsedAddress
    {
        $result = $this->base->parse($address, $spaceInPostCode);
        $issues = $this->inspector->inspect($address, $result);
        $result = $result->with(['issues' => array_map(static fn (Issue $i): string => $i->value, $issues)]);

        foreach ($this->refiners as $refiner) {
            if (!$this->shouldEscalate($issues)) {
                return $result;
            }

            $candidate = $this->tryRefiner($refiner, $address, $result, $issues, $spaceInPostCode);

            if (null === $candidate) {
                continue;
            }

            $candidateIssues = $this->inspector->inspect($address, $candidate);

            // Only accept a refinement that is actually better — a provider that trades a missing
            // country for a lost street has made the address worse, not better.
            if (count($candidateIssues) >= count($issues)) {
                $this->logger->info('refinement rejected: no improvement', [
                    'refiner' => $refiner->name(),
                    'before' => array_map(static fn (Issue $i): string => $i->value, $issues),
                    'after' => array_map(static fn (Issue $i): string => $i->value, $candidateIssues),
                ]);

                continue;
            }

            $issues = $candidateIssues;
            $result = $candidate->with([
                'issues' => array_map(static fn (Issue $i): string => $i->value, $candidateIssues),
                'source' => $refiner->name(),
            ]);
        }

        return $result;
    }

    /**
     * @param list<Issue> $issues
     */
    private function shouldEscalate(array $issues): bool
    {
        if ([] === $issues) {
            return false;
        }

        if ([] === $this->escalateOn) {
            return true;
        }

        foreach ($issues as $issue) {
            if (in_array($issue, $this->escalateOn, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Issue> $issues
     */
    private function tryRefiner(
        RefinerInterface $refiner,
        string $address,
        ParsedAddress $draft,
        array $issues,
        bool $spaceInPostCode,
    ): ?ParsedAddress {
        try {
            return $refiner->refine($address, $draft, $issues, $spaceInPostCode);
        } catch (\Throwable $e) {
            // A refiner is an improvement, never a dependency: an address that parsed by rules
            // must not fail because a remote service was down.
            $this->logger->error('refiner failed', [
                'refiner' => $refiner->name(),
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
