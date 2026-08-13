<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Postcode;

use Codelot\AddressParser\Http\HttpClientFactory;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * UK postcode register over HTTP (postcodes.io by default, or any service with the same shape).
 *
 * **Only the postcode ever leaves the process.** Not the street, not the company, not the rest of
 * the address — the URL is built from the postcode alone, and there is no code path that puts
 * anything else into a request. That is what makes this defensible when the addresses belong to
 * customers: a bare postcode covers about fifteen properties and identifies nobody on its own,
 * while the full string usually identifies someone exactly.
 *
 * Even so, a self-hosted register is the better answer where one is available — see
 * CodePointOpenLookup, which needs no network at all.
 */
final class PostcodesIoLookup implements PostcodeLookupInterface
{
    /** The register covers the UK, so a hit means one of these. */
    private const UK_COUNTRIES = ['England', 'Scotland', 'Wales', 'Northern Ireland'];

    private ClientInterface $http;

    private RequestFactoryInterface $requests;

    public function __construct(
        private readonly string $baseUrl = 'https://api.postcodes.io',
        ?ClientInterface $http = null,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 2_592_000,
        ?RequestFactoryInterface $requests = null,
    ) {
        $this->http = $http ?? HttpClientFactory::create();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    public function lookup(string $postcode): ?PostcodeLocation
    {
        $normalised = self::normalise($postcode);

        // Nothing recognisable, nothing sent. A request built from junk would leak whatever the
        // junk happens to be.
        if (!self::looksLikeAUkPostcode($normalised)) {
            return null;
        }

        $key = 'address.postcode.' . $normalised;
        $cached = $this->cache?->get($key);

        if (is_array($cached)) {
            return [] === $cached ? null : $this->toLocation($cached);
        }

        $request = $this->requests->createRequest(
            'GET',
            rtrim($this->baseUrl, '/') . '/postcodes/' . rawurlencode($normalised),
        );

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        // 404 is a real answer: the register does not know this postcode.
        if (404 === $status) {
            $this->cache?->set($key, [], $this->cacheTtl);

            return null;
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('%s answered HTTP %d', $this->describe(), $status));
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $result = is_array($decoded) ? ($decoded['result'] ?? null) : null;

        if (!is_array($result)) {
            return null;
        }

        $this->cache?->set($key, $result, $this->cacheTtl);

        return $this->toLocation($result);
    }

    public function describe(): string
    {
        return 'postcodes.io';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function toLocation(array $result): ?PostcodeLocation
    {
        $country = (string) ($result['country'] ?? '');

        if (!in_array($country, self::UK_COUNTRIES, true)) {
            return null;
        }

        return new PostcodeLocation(
            countryCode: 'GB',
            district: (string) ($result['admin_district'] ?? ''),
            region: (string) ($result['region'] ?? ''),
        );
    }

    public static function normalise(string $postcode): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', trim($postcode)));
    }

    /**
     * Guards the one thing that must never go wrong here: only a postcode-shaped string is ever
     * put into a URL.
     */
    public static function looksLikeAUkPostcode(string $value): bool
    {
        return 1 === preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', self::normalise($value));
    }
}
