<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\Postcode\PostcodeLocation;
use Codelot\AddressParser\Postcode\PostcodeLookupInterface;
use Codelot\AddressParser\Postcode\PostcodesIoLookup;
use Codelot\AddressParser\Refiner\PostcodeRegisterRefiner;
use Codelot\AddressParser\RuleBasedParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The register is the one service allowed to see customer addresses least: it needs a postcode and
 * nothing else. Most of what is pinned here is that promise, because it is the reason the service
 * is defensible at all.
 */
#[CoversClass(PostcodesIoLookup::class)]
#[CoversClass(PostcodeRegisterRefiner::class)]
final class PostcodeRegisterTest extends TestCase
{
    /**
     * The promise: whatever the address contains, only the postcode is ever put on the wire.
     */
    public function testNothingButThePostcodeLeavesTheProcess(): void
    {
        $http = $this->http($this->json(200, ['result' => ['country' => 'England', 'admin_district' => 'Westminster', 'region' => 'London']]));

        $refiner = new PostcodeRegisterRefiner(new PostcodesIoLookup(http: $http));
        $address = 'Flat 4, 221B Baker Street, Sherlock Holmes Ltd, London, NW1 6XE';

        $refiner->refine($address, (new RuleBasedParser())->parse($address), []);

        self::assertCount(1, $http->requests);
        $url = (string) $http->requests[0]->getUri();

        self::assertSame('https://api.postcodes.io/postcodes/NW16XE', $url);

        foreach (['Baker', 'Sherlock', 'Holmes', 'Flat', '221B', 'London'] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $url, "'{$secret}' must never reach a third party");
        }

        self::assertSame('', (string) $http->requests[0]->getBody(), 'a lookup has no request body');
    }

    public function testAnAddressWithoutAPostcodeMakesNoRequestAtAll(): void
    {
        $http = $this->http($this->json(200, ['result' => ['country' => 'England']]));

        $address = 'Orchard Cottage, Holmbury St. Mary, Dorking';
        (new PostcodeRegisterRefiner(new PostcodesIoLookup(http: $http)))
            ->refine($address, (new RuleBasedParser())->parse($address), []);

        self::assertCount(0, $http->requests, 'no postcode, no call — and nothing disclosed');
    }

    /**
     * Guards the URL against anything that is not postcode-shaped, whatever the caller passes.
     */
    #[DataProvider('nonPostcodeProvider')]
    public function testOnlyAPostcodeShapedStringIsEverLookedUp(string $value): void
    {
        $http = $this->http($this->json(200, ['result' => ['country' => 'England']]));

        self::assertNull((new PostcodesIoLookup(http: $http))->lookup($value));
        self::assertCount(0, $http->requests);
    }

    public static function nonPostcodeProvider(): array
    {
        return [
            'a street' => ['221B Baker Street'],
            'a company' => ['Sherlock Holmes Ltd'],
            'a US zip' => ['10118'],
            'a Canadian code' => ['M5V 2T6'],
            'empty' => [''],
            'an injection attempt' => ['../../secret'],
        ];
    }

    public function testTheCountryComesFromTheRegisterRatherThanFromInference(): void
    {
        $refiner = new PostcodeRegisterRefiner($this->register(new PostcodeLocation('GB', 'Westminster', 'London')));

        $result = $refiner->refine('15 Davies Street, W1K 3DE', new ParsedAddress(postcode: 'W1K3DE'), []);

        self::assertSame('GB', $result->countryCode);
        self::assertSame('United Kingdom', $result->country);
        self::assertStringContainsString('postcode register', $result->trace['country']);
    }

    /**
     * An administrative district is not the postal town — EC4M is "City of London" in the register
     * and "London" on an envelope — so it is never written unless the caller asks.
     */
    public function testTheDistrictIsNotWrittenIntoCityByDefault(): void
    {
        $refiner = new PostcodeRegisterRefiner($this->register(new PostcodeLocation('GB', 'City of London')));

        self::assertSame('', $refiner->refine('1 Poultry, EC4M 9BE', new ParsedAddress(postcode: 'EC4M9BE'), [])->city);
    }

    public function testTheDistrictIsWrittenWhenAskedForAndTheCityIsEmpty(): void
    {
        $refiner = new PostcodeRegisterRefiner($this->register(new PostcodeLocation('GB', 'City of London')), fillCity: true);

        self::assertSame('City of London', $refiner->refine('1 Poultry, EC4M 9BE', new ParsedAddress(postcode: 'EC4M9BE'), [])->city);
    }

    public function testACityTheParserAlreadyFoundIsNeverOverwritten(): void
    {
        $refiner = new PostcodeRegisterRefiner($this->register(new PostcodeLocation('GB', 'City of London')), fillCity: true);

        $result = $refiner->refine('1 Poultry, London, EC4M 9BE', new ParsedAddress(city: 'London', postcode: 'EC4M9BE'), []);

        self::assertSame('London', $result->city);
    }

    public function testAnUnknownPostcodeLeavesTheDraftAlone(): void
    {
        $http = $this->http($this->json(404, ['error' => 'Postcode not found']));
        $draft = new ParsedAddress(line1: '1 Nowhere', postcode: 'ZZ11ZZ');

        $result = (new PostcodeRegisterRefiner(new PostcodesIoLookup(http: $http)))->refine('1 Nowhere, ZZ1 1ZZ', $draft, []);

        self::assertSame($draft->toLegacyArray(), $result->toLegacyArray());
    }

    public function testTheSamePostcodeIsLookedUpOnce(): void
    {
        $http = $this->http(
            $this->json(200, ['result' => ['country' => 'England', 'admin_district' => 'Westminster']]),
            $this->json(200, ['result' => ['country' => 'England', 'admin_district' => 'Westminster']]),
        );

        $lookup = new PostcodesIoLookup(http: $http, cache: new InMemoryCache());
        $lookup->lookup('W1K 3DE');
        $lookup->lookup('W1K3DE');

        self::assertCount(1, $http->requests, 'postcodes repeat heavily; a register answer never changes');
    }

    public function testAnUnknownPostcodeIsAlsoRemembered(): void
    {
        $http = $this->http($this->json(404, []), $this->json(404, []));

        $lookup = new PostcodesIoLookup(http: $http, cache: new InMemoryCache());
        $lookup->lookup('ZZ1 1ZZ');
        $lookup->lookup('ZZ1 1ZZ');

        self::assertCount(1, $http->requests, 'a miss is an answer too');
    }

    /**
     * The local register is the answer where addresses are customer data: nothing leaves the
     * process at all.
     */
    public function testTheLocalRegisterAnswersTheCountryWithoutANetwork(): void
    {
        $path = sys_get_temp_dir() . '/cpo-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE postcodes (postcode TEXT PRIMARY KEY, country_code TEXT NOT NULL, district TEXT)');
        $pdo->exec("INSERT INTO postcodes VALUES ('NW16XE', 'E92000001', 'E07000240'), ('EH11YZ', 'S92000003', 'S12000036'), ('JE24WA', 'L93000001', '')");

        try {
            $lookup = new \Codelot\AddressParser\Postcode\CodePointOpenLookup($path);

            self::assertSame('GB', $lookup->lookup('NW1 6XE')?->countryCode);
            self::assertSame('GB', $lookup->lookup('EH1 1YZ')?->countryCode, 'Scotland is the United Kingdom');
            self::assertSame('JE', $lookup->lookup('JE2 4WA')?->countryCode, 'Jersey is not');
            self::assertNull($lookup->lookup('ZZ1 1ZZ'));

            self::assertSame(
                '',
                $lookup->lookup('NW1 6XE')?->district,
                'the dataset holds a GSS code, not a name — it must not reach an address field',
            );
        } finally {
            unlink($path);
        }
    }

    private function register(PostcodeLocation $location): PostcodeLookupInterface
    {
        return new class($location) implements PostcodeLookupInterface {
            public function __construct(private readonly PostcodeLocation $location)
            {
            }

            public function lookup(string $postcode): ?PostcodeLocation
            {
                return $this->location;
            }

            public function describe(): string
            {
                return 'test-register';
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: string}
     */
    private function json(int $status, array $body): array
    {
        return [$status, json_encode($body) ?: '{}'];
    }

    /**
     * @param array{0: int, 1: string} ...$responses
     */
    private function http(array ...$responses): ClientInterface
    {
        return new class($responses) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @param list<array{0: int, 1: string}> $responses */
            public function __construct(private array $responses)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                [$status, $body] = array_shift($this->responses) ?? [200, '{}'];

                return new class($status, $body) implements ResponseInterface {
                    public function __construct(private readonly int $status, private readonly string $body)
                    {
                    }

                    public function getStatusCode(): int
                    {
                        return $this->status;
                    }

                    public function getBody(): \Psr\Http\Message\StreamInterface
                    {
                        return \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory()->createStream($this->body);
                    }

                    public function getProtocolVersion(): string { return '1.1'; }
                    public function withProtocolVersion(string $version): static { return $this; }
                    public function getHeaders(): array { return []; }
                    public function hasHeader(string $name): bool { return false; }
                    public function getHeader(string $name): array { return []; }
                    public function getHeaderLine(string $name): string { return ''; }
                    public function withHeader(string $name, $value): static { return $this; }
                    public function withAddedHeader(string $name, $value): static { return $this; }
                    public function withoutHeader(string $name): static { return $this; }
                    public function withBody(\Psr\Http\Message\StreamInterface $body): static { return $this; }
                    public function withStatus(int $code, string $reasonPhrase = ''): static { return $this; }
                    public function getReasonPhrase(): string { return ''; }
                };
            }
        };
    }
}
