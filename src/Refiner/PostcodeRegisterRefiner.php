<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Refiner;

use Codelot\AddressParser\Country\CountryResolverInterface;
use Codelot\AddressParser\Country\Iso3166CountryResolver;
use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\Postcode\PostcodeLookupInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves the country from a postcode register rather than from inference.
 *
 * Where a language model weighs evidence and can be wrong, a register answers from a record: this
 * postcode exists and belongs to this country. For an address that carries a postcode that is the
 * better answer, and it is the reason to put this service *before* any model in the pipeline —
 * every address it settles is one the model is never paid to guess at.
 *
 * What it deliberately does not do is fill in the city. A register of this kind returns an
 * administrative district, and that is not the postal town: EC4M is "City of London"
 * administratively and "London" on an envelope. Writing the former into a CITY column would be
 * quietly wrong, so it happens only when asked for explicitly.
 */
final class PostcodeRegisterRefiner implements RefinerInterface
{
    public function __construct(
        private readonly PostcodeLookupInterface $register,
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly bool $fillCity = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $name = 'postcode-register',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress
    {
        $postcode = '' !== $draft->postcode ? $draft->postcode : $this->findPostcode($address);

        if ('' === $postcode) {
            return $draft;
        }

        $location = $this->register->lookup($postcode);

        if (null === $location) {
            return $draft;
        }

        $country = $this->countries->resolve($location->countryCode);

        if (null === $country) {
            return $draft;
        }

        $this->logger->info('country resolved from the postcode register', [
            'postcode' => $postcode,
            'register' => $this->register->describe(),
            'country' => $country->alpha2,
        ]);

        $fields = [
            'country' => $country->name,
            'country_code' => $country->alpha2,
            'trace' => $draft->trace + ['country' => 'postcode register (' . $this->register->describe() . ')'],
            'source' => $this->name,
        ];

        // Only when asked, and only into an empty field: an administrative district is a worse
        // answer than nothing, and a much worse answer than a town the parser already found.
        if ($this->fillCity && '' === $draft->city && '' !== $location->district) {
            $fields['city'] = $location->district;
        }

        return $draft->with($fields);
    }

    /**
     * The draft usually carries the postcode already; this covers the case where the rules could
     * not place it but the string clearly contains one.
     */
    private function findPostcode(string $address): string
    {
        $address = explode('|', $address)[0];

        if (1 === preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2})\b/i', $address, $match)) {
            return $match[1];
        }

        return '';
    }
}
