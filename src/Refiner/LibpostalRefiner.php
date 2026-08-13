<?php

declare(strict_types=1);

namespace Address\Parser\Refiner;

use Address\Parser\Country\CountryResolverInterface;
use Address\Parser\Country\Iso3166CountryResolver;
use Address\Parser\Http\CurlTransport;
use Address\Parser\Http\HttpTransportInterface;
use Address\Parser\ParsedAddress;

/**
 * A libpostal service, running as a sidecar.
 *
 * libpostal is a statistical parser trained on tens of millions of addresses — it is very good at
 * the component split and knows nothing about countries it was not trained on. It cannot be loaded
 * into a PHP process (a C library plus gigabytes of models), so it is reached over HTTP.
 *
 * The service is expected to answer with libpostal's own label/value pairs, which is what the
 * common REST wrappers return:
 *   [{"label": "road", "value": "baker street"}, {"label": "postcode", "value": "nw1 6xe"}]
 */
final class LibpostalRefiner implements RefinerInterface
{
    /** libpostal labels, grouped into the fields this package returns. */
    private const FIELD_LABELS = [
        'line1' => ['house_number', 'road', 'unit', 'level', 'entrance', 'staircase', 'po_box'],
        'line2' => ['house', 'building', 'suburb', 'city_district'],
        'city' => ['city', 'town', 'village', 'locality'],
        'postcode' => ['postcode'],
        'country' => ['country', 'country_region'],
    ];

    public function __construct(
        private readonly string $endpoint,
        private readonly HttpTransportInterface $http = new CurlTransport(),
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly string $name = 'libpostal',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress
    {
        $query = trim(explode('|', $address)[0]);

        $body = $this->http->request(
            'POST',
            $this->endpoint,
            json_encode(['query' => $query], JSON_UNESCAPED_UNICODE) ?: '{}',
            ['Content-Type' => 'application/json'],
        );

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return $draft;
        }

        $parts = array_fill_keys(array_keys(self::FIELD_LABELS), []);

        foreach ($decoded as $token) {
            if (!is_array($token)) {
                continue;
            }

            $label = (string) ($token['label'] ?? '');
            $value = trim((string) ($token['value'] ?? ''));

            if ('' === $value) {
                continue;
            }

            foreach (self::FIELD_LABELS as $field => $labels) {
                if (in_array($label, $labels, true)) {
                    $parts[$field][] = $value;

                    break;
                }
            }
        }

        if ([] === array_filter($parts)) {
            return $draft;
        }

        $country = [] === $parts['country'] ? null : $this->countries->resolve(implode(' ', $parts['country']));
        $postcode = mb_strtoupper(implode(' ', $parts['postcode']));

        if (!$spaceInPostCode) {
            $postcode = str_replace([' ', '-'], '', $postcode);
        }

        return $draft->with([
            'line1' => implode(' ', $parts['line1']),
            'line2' => implode(' ', $parts['line2']),
            'city' => implode(' ', $parts['city']),
            'postcode' => $postcode,
            'country' => $country?->name ?? '',
            'country_code' => $country?->alpha2 ?? '',
            'trace' => $draft->trace + ['refined_by' => 'libpostal'],
            'source' => $this->name,
        ]);
    }
}
