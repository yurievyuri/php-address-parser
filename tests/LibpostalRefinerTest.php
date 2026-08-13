<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\Refiner\LibpostalRefiner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * libpostal returns its own label/value pairs, and the mapping of those labels onto our five
 * fields is the whole of this class. These pin the mapping and the ways the service can let us
 * down without taking a parse with it.
 */
final class LibpostalRefinerTest extends TestCase
{
    public function testLabelsAreMappedOntoTheFields(): void
    {
        $http = $this->http(200, json_encode([
            ['label' => 'house_number', 'value' => '221B'],
            ['label' => 'road', 'value' => 'Baker Street'],
            ['label' => 'suburb', 'value' => 'Marylebone'],
            ['label' => 'city', 'value' => 'London'],
            ['label' => 'postcode', 'value' => 'NW1 6XE'],
            ['label' => 'country', 'value' => 'United Kingdom'],
        ]) ?: '');

        $result = (new LibpostalRefiner('http://libpostal.internal/parse', $http))
            ->refine('221B Baker Street, Marylebone, London, NW1 6XE, United Kingdom', new ParsedAddress(), []);

        self::assertSame('221B Baker Street', $result->line1, 'house number and road make the first line');
        self::assertSame('Marylebone', $result->line2);
        self::assertSame('London', $result->city);
        self::assertSame('NW16XE', $result->postcode);
        self::assertSame('GB', $result->countryCode, 'the country name is resolved to a code, not copied');
        self::assertSame('libpostal', $result->source);
    }

    public function testTheAddressIsSentAsAQueryAndTheLocationIdIsStripped(): void
    {
        $http = $this->http(200, '[]');

        (new LibpostalRefiner('http://libpostal.internal/parse', $http))
            ->refine('15 Davies Street, London, W1K 3DE|;|386474', new ParsedAddress(), []);

        $body = json_decode((string) $http->requests[0]->getBody(), true);

        self::assertSame('15 Davies Street, London, W1K 3DE', $body['query'], 'the pipe section is metadata');
        self::assertSame('application/json', $http->requests[0]->getHeaderLine('Content-Type'));
    }

    public function testTheSpacedPostcodeFlagIsHonoured(): void
    {
        $answer = json_encode([['label' => 'postcode', 'value' => 'W1K 3DE'], ['label' => 'road', 'value' => 'Davies Street']]) ?: '';

        $squashed = (new LibpostalRefiner('http://x/parse', $this->http(200, $answer)))
            ->refine('Davies Street W1K 3DE', new ParsedAddress(), [], false);
        $spaced = (new LibpostalRefiner('http://x/parse', $this->http(200, $answer)))
            ->refine('Davies Street W1K 3DE', new ParsedAddress(), [], true);

        self::assertSame('W1K3DE', $squashed->postcode);
        self::assertSame('W1K 3DE', $spaced->postcode);
    }

    public function testAnEmptyParseLeavesTheDraftAlone(): void
    {
        $draft = new ParsedAddress(line1: 'kept by the rules', city: 'Dorking');

        $result = (new LibpostalRefiner('http://x/parse', $this->http(200, '[]')))
            ->refine('something', $draft, []);

        self::assertSame($draft->toLegacyArray(), $result->toLegacyArray());
    }

    public function testANonJsonAnswerLeavesTheDraftAlone(): void
    {
        $draft = new ParsedAddress(line1: 'kept by the rules');

        $result = (new LibpostalRefiner('http://x/parse', $this->http(200, '<html>gateway</html>')))
            ->refine('something', $draft, []);

        self::assertSame($draft->toLegacyArray(), $result->toLegacyArray());
    }

    public function testAServiceErrorIsRaisedSoThePipelineCanRecordIt(): void
    {
        // The pipeline catches this, logs it and keeps the rule-based result — but the refiner
        // must not pretend a 500 was an answer.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/answered HTTP 500/');

        (new LibpostalRefiner('http://x/parse', $this->http(500, 'boom')))
            ->refine('something', new ParsedAddress(), []);
    }

    public function testAnUnknownCountryNameIsNotForcedIntoTheResult(): void
    {
        $http = $this->http(200, json_encode([
            ['label' => 'road', 'value' => 'Some Street'],
            ['label' => 'country', 'value' => 'Freedonia'],
        ]) ?: '');

        $result = (new LibpostalRefiner('http://x/parse', $http))->refine('Some Street, Freedonia', new ParsedAddress(), []);

        self::assertSame('', $result->countryCode);
        self::assertSame('', $result->country);
    }

    private function http(int $status, string $body): ClientInterface
    {
        return new class($status, $body) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function __construct(private readonly int $status, private readonly string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new class($this->status, $this->body) implements ResponseInterface {
                    public function __construct(private readonly int $status, private readonly string $body)
                    {
                    }

                    public function getStatusCode(): int { return $this->status; }
                    public function getBody(): StreamInterface
                    {
                        return \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory()->createStream($this->body);
                    }
                    public function getHeaderLine(string $name): string { return ''; }
                    public function getProtocolVersion(): string { return '1.1'; }
                    public function withProtocolVersion(string $version): static { return $this; }
                    public function getHeaders(): array { return []; }
                    public function hasHeader(string $name): bool { return false; }
                    public function getHeader(string $name): array { return []; }
                    public function withHeader(string $name, $value): static { return $this; }
                    public function withAddedHeader(string $name, $value): static { return $this; }
                    public function withoutHeader(string $name): static { return $this; }
                    public function withBody(StreamInterface $body): static { return $this; }
                    public function withStatus(int $code, string $reasonPhrase = ''): static { return $this; }
                    public function getReasonPhrase(): string { return ''; }
                };
            }
        };
    }
}
