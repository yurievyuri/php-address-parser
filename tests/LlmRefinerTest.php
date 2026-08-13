<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\Llm\LlmClientInterface;
use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\Refiner\LlmRefiner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The refiner is only allowed to redistribute the words it was given. A model that supplies a
 * postcode it "knows" for a street writes plausible, wrong data into customer records — which is
 * strictly worse than an address that failed to parse.
 */
#[CoversClass(LlmRefiner::class)]
final class LlmRefinerTest extends TestCase
{
    public function testItAcceptsASplitBuiltFromTheInputWords(): void
    {
        $refiner = new LlmRefiner($this->client([
            'line1' => 'Friedrichstrasse 43',
            'line2' => '',
            'city' => 'Berlin',
            'postcode' => '10117',
            'country_code' => 'DE',
            'country_evidence' => 'Berlin',
        ]));

        $result = $refiner->refine('Friedrichstrasse 43, Berlin, 10117', new ParsedAddress(), []);

        self::assertSame('DE', $result->countryCode);
        self::assertSame('Germany', $result->country, 'the country name comes from the table, not the model');
        self::assertSame('Berlin', $result->city);
    }

    public function testItRejectsAPostcodeTheModelInvented(): void
    {
        $draft = new ParsedAddress(line1: '221B Baker Street', city: 'London');

        $refiner = new LlmRefiner($this->client([
            'line1' => '221B Baker Street',
            'line2' => '',
            'city' => 'London',
            'postcode' => 'NW1 6XE', // never appeared in the input
            'country_code' => 'GB',
            'country_evidence' => 'London',
        ]));

        $result = $refiner->refine('221B Baker Street, London', $draft, []);

        self::assertSame($draft->toLegacyArray(), $result->toLegacyArray(), 'invented data must be discarded');
    }

    public function testItRejectsACityTheModelInvented(): void
    {
        $draft = new ParsedAddress(line1: 'Some Street 1');

        $refiner = new LlmRefiner($this->client([
            'line1' => 'Some Street 1',
            'line2' => '',
            'city' => 'Manchester',
            'postcode' => '',
            'country_code' => '',
        ]));

        $result = $refiner->refine('Some Street 1', $draft, []);

        self::assertSame('', $result->city);
    }

    public function testTheSecondCallForTheSameAddressIsServedFromCache(): void
    {
        $client = $this->client([
            'line1' => 'Friedrichstrasse 43',
            'line2' => '',
            'city' => 'Berlin',
            'postcode' => '10117',
            'country_code' => 'DE',
            'country_evidence' => 'Berlin',
        ]);

        $refiner = new LlmRefiner($client, cache: new InMemoryCache());

        $refiner->refine('Friedrichstrasse 43, Berlin, 10117', new ParsedAddress(), []);
        $refiner->refine('Friedrichstrasse 43, Berlin, 10117', new ParsedAddress(), []);

        self::assertSame(1, $client->calls, 'the same address must not be paid for twice');
    }

    public function testTheSpacedPostcodeFlagIsHonoured(): void
    {
        $payload = [
            'line1' => '15 Davies Street',
            'line2' => '',
            'city' => 'London',
            'postcode' => 'W1K 3DE',
            'country_code' => 'GB',
            'country_evidence' => 'London',
        ];

        $squashed = (new LlmRefiner($this->client($payload)))
            ->refine('15 Davies Street, London, W1K 3DE', new ParsedAddress(), [], false);
        $spaced = (new LlmRefiner($this->client($payload)))
            ->refine('15 Davies Street, London, W1K 3DE', new ParsedAddress(), [], true);

        self::assertSame('W1K3DE', $squashed->postcode);
        self::assertSame('W1K 3DE', $spaced->postcode);
    }

    /**
     * A model that recognises a street name and guesses the country around it produces a value
     * that reads as data everywhere downstream. Requiring it to quote the evidence makes the guess
     * checkable — and this one is not in the address.
     */
    public function testACountryTheModelCannotPointToIsDropped(): void
    {
        $refiner = new LlmRefiner($this->client([
            'line1' => '2 Addison Avenue Kj Food & Wine',
            'line2' => '',
            'city' => '',
            'postcode' => '',
            'country_code' => 'US',
            'country_evidence' => 'Addison Avenue is a common US street name',
        ]));

        $result = $refiner->refine('2 Addison Avenue  Kj Food & Wine', new ParsedAddress(), []);

        self::assertSame('', $result->countryCode, 'a guess must not survive as a country');
        self::assertSame('', $result->country);
    }

    public function testACountryQuotedFromTheAddressIsKept(): void
    {
        $refiner = new LlmRefiner($this->client([
            'line1' => 'Ballysimon Road Dfs Furniture',
            'line2' => '',
            'city' => '',
            'postcode' => '',
            'country_code' => 'IE',
            'country_evidence' => 'Ballysimon Road',
        ]));

        $result = $refiner->refine('Ballysimon Road  Dfs Furniture', new ParsedAddress(), []);

        self::assertSame('IE', $result->countryCode);
    }

    public function testEvidenceIsComparedLooselyEnoughForRealTyping(): void
    {
        // Different case and punctuation must not fail an otherwise honest quote.
        $refiner = new LlmRefiner($this->client([
            'line1' => 'Rue Glesener 21',
            'line2' => '',
            'city' => 'Luxembourg',
            'postcode' => '1631',
            'country_code' => 'LU',
            'country_evidence' => 'LUXEMBOURG,',
        ]));

        self::assertSame('LU', $refiner->refine('Rue Glesener 21, Luxembourg, 1631', new ParsedAddress(), [])->countryCode);
    }

    public function testAnEmptyEvidenceDropsTheCountry(): void
    {
        $refiner = new LlmRefiner($this->client([
            'line1' => 'Somewhere',
            'line2' => '',
            'city' => '',
            'postcode' => '',
            'country_code' => 'FR',
            'country_evidence' => '',
        ]));

        self::assertSame('', $refiner->refine('Somewhere', new ParsedAddress(), [])->countryCode);
    }

    public function testTheGuardCanBeTurnedOffForACallerThatWantsGuesses(): void
    {
        $refiner = new LlmRefiner(
            $this->client([
                'line1' => 'Somewhere',
                'line2' => '',
                'city' => '',
                'postcode' => '',
                'country_code' => 'FR',
                'country_evidence' => '',
            ]),
            requireCountryEvidence: false,
        );

        self::assertSame('FR', $refiner->refine('Somewhere', new ParsedAddress(), [])->countryCode);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function client(array $payload): LlmClientInterface
    {
        return new class($payload) implements LlmClientInterface {
            public int $calls = 0;

            /** @param array<string, mixed> $payload */
            public function __construct(private readonly array $payload)
            {
            }

            public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array
            {
                ++$this->calls;

                return $this->payload;
            }

            public function describe(): string
            {
                return 'test:stub';
            }
        };
    }
}
