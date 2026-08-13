<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\AddressParserInterface;
use Codelot\AddressParser\Batch\BatchParser;
use Codelot\AddressParser\Facade\LegacyArrayParser;
use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\RuleBasedParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The legacy facade replaces a static function called from code that has no error handling, so its
 * contract is unusually strict: the right shape, always, and never an exception.
 */
#[CoversClass(LegacyArrayParser::class)]
#[CoversClass(BatchParser::class)]
final class FacadeTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyArrayParser::reset();
    }

    protected function tearDown(): void
    {
        LegacyArrayParser::reset();
    }

    public function testItReturnsTheLegacyKeysAndNothingElse(): void
    {
        $result = LegacyArrayParser::parse('221B Baker Street, London, NW1 6XE, United Kingdom');

        self::assertSame(
            ['line1', 'line2', 'city', 'postcode', 'country', 'country_code'],
            array_keys($result),
            'the key set is the contract twelve call sites depend on',
        );
        self::assertSame('221B Baker Street', $result['line1']);
        self::assertSame('GB', $result['country_code']);
    }

    public function testEveryValueIsAStringEvenWhenNothingWasFound(): void
    {
        foreach (LegacyArrayParser::parse('') as $field => $value) {
            self::assertIsString($value, "{$field} must be a string — callers pass these to mb_substr()");
        }
    }

    /**
     * The legacy signature was untyped, so these really do arrive.
     */
    #[DataProvider('untypedInputProvider')]
    public function testItSurvivesWhateverTheOldSignatureAccepted(mixed $input): void
    {
        $result = LegacyArrayParser::parse($input);

        self::assertCount(6, $result);
        self::assertIsString($result['line1']);
    }

    public static function untypedInputProvider(): array
    {
        return [
            'null' => [null],
            'false' => [false],
            'integer' => [42],
            'float' => [1.5],
            'empty string' => [''],
            'whitespace' => ['   '],
            'already parsed array' => [['line1' => '15 Davies Street', 'city' => 'London', 'postcode' => 'W1K 3DE']],
            'stringable' => [new class implements \Stringable {
                public function __toString(): string
                {
                    return '15 Davies Street, London, W1K 3DE';
                }
            }],
        ];
    }

    public function testAnAlreadyParsedArrayIsReassembledRatherThanMangled(): void
    {
        $result = LegacyArrayParser::parse([
            'line1' => '15 Davies Street',
            'line2' => '',
            'city' => 'London',
            'postcode' => 'W1K 3DE',
            'country' => 'United Kingdom',
        ]);

        self::assertSame('GB', $result['country_code']);
        self::assertSame('London', $result['city']);
    }

    public function testAFailingParserFallsBackToRulesInsteadOfThrowing(): void
    {
        LegacyArrayParser::configure(new class implements AddressParserInterface {
            public function parse(string $address, bool $spaceInPostCode = false): ParsedAddress
            {
                throw new \RuntimeException('the configured pipeline is broken');
            }
        });

        $result = LegacyArrayParser::parse('15 Davies Street, London, W1K 3DE');

        self::assertSame('15 Davies Street', $result['line1'], 'rules must still answer');
        self::assertSame('GB', $result['country_code']);
    }

    public function testTheConfiguredParserIsUsedWhenItWorks(): void
    {
        LegacyArrayParser::configure(new class implements AddressParserInterface {
            public function parse(string $address, bool $spaceInPostCode = false): ParsedAddress
            {
                return new ParsedAddress(line1: 'from the configured parser', source: 'test');
            }
        });

        self::assertSame('from the configured parser', LegacyArrayParser::parse('anything')['line1']);
    }

    public function testTheSpacedPostcodeFlagReachesTheParser(): void
    {
        self::assertSame('W1K3DE', LegacyArrayParser::parse('15 Davies Street, London, W1K 3DE')['postcode']);
        self::assertSame('W1K 3DE', LegacyArrayParser::parse('15 Davies Street, London, W1K 3DE', true)['postcode']);
    }

    public function testTheObjectFormIsAvailableForCallersThatCanUseIt(): void
    {
        $result = LegacyArrayParser::parseToObject('15 Davies Street, London, W1K 3DE');

        self::assertInstanceOf(ParsedAddress::class, $result);
        self::assertNotSame([], $result->trace, 'the trace is the reason to reach for the object form');
    }

    public function testABatchReportsWhatHappened(): void
    {
        $summary = (new BatchParser(new RuleBasedParser()))->run([
            '221B Baker Street, London, NW1 6XE, United Kingdom',
            '15 Davies Street, London, W1K 3DE',
            'Friedrichstrasse 43, Berlin, 10117',
        ]);

        self::assertSame(3, $summary->total);
        self::assertSame(3, $summary->bySource['rules']);
        self::assertSame(0, $summary->escalated());
        self::assertSame(1, $summary->withIssues(), 'the German address resolves no country');
        self::assertSame(1, $summary->byIssue['country_missing']);
        self::assertSame(2, $summary->clean());
    }

    public function testABatchCountsEscalationSeparately(): void
    {
        $parser = new class implements AddressParserInterface {
            public function parse(string $address, bool $spaceInPostCode = false): ParsedAddress
            {
                return new ParsedAddress(line1: $address, source: 'gemini');
            }
        };

        $summary = (new BatchParser($parser))->run(['a', 'b']);

        self::assertSame(2, $summary->escalated(), 'anything not produced by rules was paid for');
        self::assertSame(2, $summary->bySource['gemini']);
    }

    public function testOneBadAddressDoesNotStopTheRun(): void
    {
        $parser = new class implements AddressParserInterface {
            public function parse(string $address, bool $spaceInPostCode = false): ParsedAddress
            {
                if ('poison' === $address) {
                    throw new \RuntimeException('cannot parse this one');
                }

                return new ParsedAddress(line1: $address, source: 'rules');
            }
        };

        $summary = (new BatchParser($parser))->run(['first', 'poison', 'third']);

        self::assertSame(3, $summary->total);
        self::assertSame(2, $summary->bySource['rules']);
        self::assertSame(['poison'], $summary->failures);
    }

    public function testTheCallbackSeesEveryResult(): void
    {
        $seen = [];

        (new BatchParser())->run(
            ['15 Davies Street, London, W1K 3DE', 'Orchard Cottage, Holmbury St. Mary, Dorking'],
            static function (string $address, ParsedAddress $result) use (&$seen): void {
                $seen[$address] = $result->city;
            },
        );

        self::assertSame('London', $seen['15 Davies Street, London, W1K 3DE']);
        self::assertSame('Dorking', $seen['Orchard Cottage, Holmbury St. Mary, Dorking']);
    }

    public function testMapIsLazySoALargeExportNeedNotFitInMemory(): void
    {
        $parsed = 0;

        $addresses = (function () use (&$parsed): \Generator {
            foreach (['15 Davies Street, London, W1K 3DE', 'Calle 50, Panama, 0819'] as $address) {
                ++$parsed;

                yield $address;
            }
        })();

        $results = (new BatchParser())->map($addresses);

        self::assertSame(0, $parsed, 'nothing is read before the generator is consumed');

        $first = $results->current();

        self::assertSame('London', $first->city);
        self::assertSame(1, $parsed, 'only the first address has been read');
    }

    public function testTheSummarySerialisesForReportingAndAlerting(): void
    {
        $summary = (new BatchParser())->run(['Friedrichstrasse 43, Berlin, 10117']);
        $array = $summary->toArray();

        self::assertSame(1, $array['total']);
        self::assertArrayNotHasKey('__any__', $array['by_issue'], 'the internal counter must not leak into a report');
        self::assertSame(1, $array['by_issue']['country_missing']);
    }
}
