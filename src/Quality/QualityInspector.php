<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Quality;

use Codelot\AddressParser\Country\CountryResolverInterface;
use Codelot\AddressParser\Country\Iso3166CountryResolver;
use Codelot\AddressParser\ParsedAddress;

/**
 * Judges one parse result against the same measures the rule engine is held to on the corpus.
 *
 * This is what makes escalation affordable: a slow or paid provider is only consulted for the
 * addresses that actually came out wrong, which on the production corpus is a small minority.
 */
final class QualityInspector
{
    /**
     * Words that appear in a country's alternative names and so may legitimately vanish when the
     * canonical name replaces them. Only the cases real addresses actually write.
     */
    private const ALIAS_WORDS = [
        'GB' => ['England', 'Scotland', 'Wales', 'Northern', 'Ireland', 'Britain', 'Great', 'UK', 'GB', 'GBR'],
        'AE' => ['UAE', 'Emirates', 'Arab'],
        'US' => ['USA', 'America', 'States'],
        'NL' => ['Holland'],
        'IE' => ['Eire', 'Ireland'],
        'RU' => ['Russia'],
        'CZ' => ['Czechia'],
        'KR' => ['Korea'],
    ];

    public function __construct(
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
    ) {
    }

    /**
     * @return list<Issue>
     */
    public function inspect(string $input, ParsedAddress $result): array
    {
        $input = trim(explode('|', $input)[0]);

        if ('' === $input) {
            return [];
        }

        $issues = [];

        if ('' === $result->countryCode) {
            $issues[] = Issue::CountryMissing;
        }

        if ('' === $result->line1) {
            $issues[] = Issue::Line1Empty;
        }

        if ('' !== $result->city && null !== $this->countries->resolve($result->city)) {
            $issues[] = Issue::CityIsCountry;
        }

        if ([] !== $this->lostTokens($input, $result)) {
            $issues[] = Issue::TokenLost;
        }

        if ('' === $result->postcode && $this->looksLikeItCarriesAPostcode($input)) {
            $issues[] = Issue::PostcodeMissed;
        }

        if ('' !== $result->postcode && 1 !== preg_match('/\d/u', $result->postcode)) {
            $issues[] = Issue::PostcodeImplausible;
        }

        return $issues;
    }

    /**
     * Meaningful tokens of the input that appear in no output field. The parser redistributes an
     * address; anything it drops is a defect.
     *
     * @return list<string>
     */
    public function lostTokens(string $input, ParsedAddress $result): array
    {
        $input = explode('|', $input)[0];

        $output = self::fold(implode('', [
            $result->line1, $result->line2, $result->city, $result->postcode, $result->country,
        ]));
        $countryFolded = self::fold($result->country);

        $lost = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $folded = self::fold($token);

            if (mb_strlen($token) < 3 || '' === $folded) {
                continue;
            }

            // A word naming the resolved country is legitimately replaced by the canonical name.
            // That covers both a word of the name itself ("Kingdom") and any other spelling that
            // resolves to the same country — "England", "Northern Ireland" and "UAE" all disappear
            // into "United Kingdom" / "United Arab Emirates" without anything being lost.
            if ('' !== $countryFolded && str_contains($countryFolded, $folded)) {
                continue;
            }

            if ('' !== $result->countryCode) {
                $named = $this->countries->resolve($token);

                if (null !== $named && $named->alpha2 === $result->countryCode) {
                    continue;
                }

                // Multi-word country names arrive one token at a time ("NORTHERN", "IRELAND"), so
                // also accept a token that is a word of an alias for the same country.
                if ($this->isWordOfAnAliasFor($token, $result->countryCode)) {
                    continue;
                }
            }

            if (!str_contains($output, $folded)) {
                $lost[] = $token;
            }
        }

        return $lost;
    }

    /**
     * True when the token is one word of some name for this country — "Northern" and "Ireland"
     * for GB, "Arab" and "Emirates" for AE.
     *
     * The resolver knows the aliases; this asks it word by word, because a multi-word name reaches
     * us split into tokens and neither half resolves on its own.
     */
    private function isWordOfAnAliasFor(string $token, string $countryCode): bool
    {
        foreach (self::ALIAS_WORDS[$countryCode] ?? [] as $word) {
            if (0 === strcasecmp($word, $token)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeItCarriesAPostcode(string $input): bool
    {
        // A run containing both letters and digits, or four or more digits together.
        return 1 === preg_match('/(?:\b[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}\b|\b\d{4,}\b)/ui', $input);
    }

    private static function fold(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(false === $ascii ? $value : $ascii));
    }
}
