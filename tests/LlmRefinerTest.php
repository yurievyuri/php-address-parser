<?php

declare(strict_types=1);

namespace Address\Parser\Tests;

use Address\Parser\Llm\LlmClientInterface;
use Address\Parser\ParsedAddress;
use Address\Parser\Refiner\LlmRefiner;
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
        ];

        $squashed = (new LlmRefiner($this->client($payload)))
            ->refine('15 Davies Street, London, W1K 3DE', new ParsedAddress(), [], false);
        $spaced = (new LlmRefiner($this->client($payload)))
            ->refine('15 Davies Street, London, W1K 3DE', new ParsedAddress(), [], true);

        self::assertSame('W1K3DE', $squashed->postcode);
        self::assertSame('W1K 3DE', $spaced->postcode);
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
