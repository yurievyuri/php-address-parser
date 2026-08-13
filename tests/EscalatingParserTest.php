<?php

declare(strict_types=1);

namespace Address\Parser\Tests;

use Address\Parser\EscalatingParser;
use Address\Parser\ParsedAddress;
use Address\Parser\Quality\Issue;
use Address\Parser\Refiner\RefinerInterface;
use Address\Parser\RuleBasedParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The escalation policy is what keeps a paid provider affordable, so its edges are pinned here:
 * when it fires, when it stays out of the way, and what happens when a provider misbehaves.
 */
#[CoversClass(EscalatingParser::class)]
final class EscalatingParserTest extends TestCase
{
    public function testACleanRuleBasedResultNeverReachesARefiner(): void
    {
        $refiner = $this->refiner('never', static fn (ParsedAddress $d): ParsedAddress => $d);

        $parser = new EscalatingParser(new RuleBasedParser(), [$refiner]);
        $result = $parser->parse('221B Baker Street, London, NW1 6XE, United Kingdom');

        self::assertSame('rules', $result->source);
        self::assertSame(0, $refiner->calls, 'a resolved address must not cost a provider call');
    }

    public function testAnUnresolvedAddressIsHandedToTheRefiner(): void
    {
        $refiner = $this->refiner('fixer', static fn (ParsedAddress $d): ParsedAddress => $d->with([
            'country' => 'Germany',
            'country_code' => 'DE',
        ]));

        $parser = new EscalatingParser(new RuleBasedParser(), [$refiner]);
        $result = $parser->parse('Friedrichstrasse 43, Berlin, 10117');

        self::assertSame(1, $refiner->calls);
        self::assertSame('DE', $result->countryCode);
        self::assertSame('fixer', $result->source, 'the result must say which provider produced it');
    }

    public function testARefinementThatMakesThingsWorseIsDiscarded(): void
    {
        // Resolves the country but throws the street away — a net loss.
        $vandal = $this->refiner('vandal', static fn (ParsedAddress $d): ParsedAddress => $d->with([
            'line1' => '',
            'city' => '',
            'country' => 'Germany',
            'country_code' => 'DE',
        ]));

        $parser = new EscalatingParser(new RuleBasedParser(), [$vandal]);
        $result = $parser->parse('Friedrichstrasse 43, Berlin, 10117');

        self::assertSame('rules', $result->source);
        self::assertSame('Friedrichstrasse 43', $result->line1, 'the good draft must survive a bad refinement');
    }

    public function testAFailingRefinerCannotBreakTheParse(): void
    {
        $broken = $this->refiner('broken', static function (): ParsedAddress {
            throw new \RuntimeException('service unavailable');
        });

        $parser = new EscalatingParser(new RuleBasedParser(), [$broken]);
        $result = $parser->parse('Friedrichstrasse 43, Berlin, 10117');

        self::assertSame('Friedrichstrasse 43', $result->line1);
        self::assertSame('10117', $result->postcode);
    }

    public function testEscalationIsLimitedToTheConfiguredIssues(): void
    {
        $refiner = $this->refiner('fixer', static fn (ParsedAddress $d): ParsedAddress => $d);

        // The address resolves its country but leaves line1 empty; only a missing country escalates.
        $parser = new EscalatingParser(
            base: new RuleBasedParser(),
            refiners: [$refiner],
            escalateOn: [Issue::CountryMissing],
        );
        $parser->parse('London, NW1 6XE, United Kingdom');

        self::assertSame(0, $refiner->calls);
    }

    public function testTheSecondRefinerIsSkippedOnceTheResultIsClean(): void
    {
        $first = $this->refiner('first', static fn (ParsedAddress $d): ParsedAddress => $d->with([
            'country' => 'Germany',
            'country_code' => 'DE',
        ]));
        $second = $this->refiner('second', static fn (ParsedAddress $d): ParsedAddress => $d);

        $parser = new EscalatingParser(new RuleBasedParser(), [$first, $second]);
        $parser->parse('Friedrichstrasse 43, Berlin, 10117');

        self::assertSame(1, $first->calls);
        self::assertSame(0, $second->calls, 'a clean result must not be paid for twice');
    }

    /**
     * @param callable(ParsedAddress): ParsedAddress $behaviour
     */
    private function refiner(string $name, callable $behaviour): RefinerInterface
    {
        return new class($name, $behaviour) implements RefinerInterface {
            public int $calls = 0;

            /** @param callable(ParsedAddress): ParsedAddress $behaviour */
            public function __construct(private readonly string $name, private readonly mixed $behaviour)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress
            {
                ++$this->calls;

                return ($this->behaviour)($draft);
            }
        };
    }
}
