<?php

declare(strict_types=1);

namespace Address\Parser;

use Address\Parser\Country\Country;
use Address\Parser\Country\CountryResolverInterface;
use Address\Parser\Country\Iso3166CountryResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Splits a free-form address into components by rule.
 *
 * The rules are ordered, and the order is load-bearing: the country is taken out first, then the
 * postcode, and only what is left is laid out across line1 / line2 / city. Each rule records what
 * it did in the result's `trace`, because "it parsed my address wrongly" is otherwise unanswerable.
 *
 * The governing invariant: nothing is discarded. Every meaningful token of the input survives
 * somewhere in the output — the parser redistributes an address, it never deletes part of it. The
 * one exception is a word naming the country that was resolved ("England" becomes
 * "United Kingdom").
 */
final class RuleBasedParser implements AddressParserInterface
{
    /** A UK postcode: outward code, then a digit and two letters. Rejects "M5V 2T6" (Canadian). */
    private const UK_POSTCODE = '[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}';

    /** Irish Eircode: routing key, then four characters. */
    private const EIRCODE = '[A-Z]\d{2}\s?[A-Z\d]{4}';

    /** US ZIP, optionally preceded by the state code that addresses glue to it. */
    private const US_ZIP = '(?:[A-Z]{2}\s)?\d{5}(?:-\d{4})?';

    /** Four or more digits, possibly grouped — the continental shape. Never a house number. */
    private const NUMERIC_POSTCODE = '\d[\d\s-]{2,}\d';

    /**
     * Countries whose name really is also a settlement, so keeping the component as the city is
     * correct. Everything else — "United Kingdom", "England", "Germany" — is never a city, and a
     * component naming one is removed even when nothing else is left to call the city.
     */
    private const CITY_STATES = [
        'LU', 'MC', 'SG', 'PA', 'HK', 'MO', 'SM', 'AD', 'DJ', 'KW', 'MX', 'GT', 'VA', 'BN', 'QA',
    ];

    /** Components that carry no information and must not become the city. */
    private const PLACEHOLDERS = ['-', '--', '.', 'n/a', 'na', 'none', 'null', 'tbc'];

    public function __construct(
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function parse(string $address, bool $spaceInPostCode = false): ParsedAddress
    {
        $trace = [];
        $components = $this->split($address);

        if ([] === $components) {
            return new ParsedAddress(source: 'rules');
        }

        [$components, $country, $trace] = $this->extractCountry($components, $trace);
        [$components, $postcode, $country, $trace] = $this->extractPostcode(
            $components,
            $country,
            $spaceInPostCode,
            $trace,
        );

        // A component naming the country we already resolved is a duplicate, not a city.
        [$components, $trace] = $this->dropRedundantCountryComponent($components, $country, $trace);

        $result = $this->layout($components, '' !== $postcode, $trace)->with([
            'postcode' => $postcode,
            'country' => $country?->name ?? '',
            'country_code' => $country?->alpha2 ?? '',
            'source' => 'rules',
        ]);

        $this->logger->debug('address parsed', [
            'input' => $address,
            'result' => $result->toLegacyArray(),
            'trace' => $result->trace,
        ]);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function split(string $address): array
    {
        // "…, W1K 3DE|;|386474" — the trailing pipe section is a location id, not part of the address.
        $address = explode('|', $address)[0];

        $components = [];
        foreach (explode(',', $address) as $component) {
            $component = $this->trimEdges($component);

            if ('' === $component || in_array(mb_strtolower($component), self::PLACEHOLDERS, true)) {
                continue;
            }

            $components[] = $component;
        }

        return $components;
    }

    /**
     * Strips whitespace and edge punctuation without breaking UTF-8 — a byte-wise trim() with a
     * multibyte character in its list cuts that character in half.
     */
    private function trimEdges(string $value): string
    {
        return (string) preg_replace('/^[\s\-–—,.;:\x{200B}]+|[\s\-–—,.;:\x{200B}]+$/u', '', $value);
    }

    /**
     * The country is whichever of these matches first:
     *   1. the last component, resolved whole;
     *   2. the second-to-last component, but only as a full name — a two-letter code in that
     *      position is far more often a US state (CA, IL) than a country;
     *   3. the tail of the last component: two words always, one word or a bare code only when a
     *      recognisable postcode stands in front of it ("Bromsgrove B603DJ GB"). Without that
     *      guard "Newark, New Jersey" resolves to Jersey and a US lead is filed against it.
     *
     * @param list<string>          $components
     * @param array<string, string> $trace
     *
     * @return array{0: list<string>, 1: Country|null, 2: array<string, string>}
     */
    private function extractCountry(array $components, array $trace): array
    {
        $lastIndex = count($components) - 1;
        $last = $components[$lastIndex];

        if (null !== $country = $this->countries->resolve($last)) {
            $trace['country'] = 'last component';

            return [$this->removeCountryComponent($components, $lastIndex), $country, $trace];
        }

        if ($lastIndex >= 1) {
            $candidate = $components[$lastIndex - 1];

            if (mb_strlen($candidate) > 3 && null !== $country = $this->countries->resolve($candidate)) {
                $trace['country'] = 'second-to-last component';

                return [$this->removeCountryComponent($components, $lastIndex - 1), $country, $trace];
            }
        }

        $words = preg_split('/\s+/u', $last, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            $twoWordTail = implode(' ', array_slice($words, -2));

            if (null !== $country = $this->countries->resolve($twoWordTail)) {
                $trace['country'] = 'two-word tail of the last component';
                $components[$lastIndex] = $this->trimEdges(implode(' ', array_slice($words, 0, -2)));

                return [$this->compact($components), $country, $trace];
            }

            $oneWordTail = end($words);
            // The postcode in front may itself be two words ("London NW2 7HF GB"), so look at both
            // the last word before the candidate and the last two.
            $preceding = [
                $words[count($words) - 2],
                implode(' ', array_slice($words, -3, 2)),
            ];

            $vouched = false;
            foreach ($preceding as $candidatePostcode) {
                if ($this->looksLikePostcode($candidatePostcode)) {
                    $vouched = true;

                    break;
                }
            }

            if ($vouched && null !== $country = $this->countries->resolve($oneWordTail)) {
                $trace['country'] = 'one-word tail, vouched for by the postcode before it';
                $components[$lastIndex] = $this->trimEdges(implode(' ', array_slice($words, 0, -1)));

                return [$this->compact($components), $country, $trace];
            }
        }

        return [$components, null, $trace];
    }

    /**
     * Removing the country component is only safe while something else is left to call the city.
     * Keep it otherwise: a town named after its country ("Rue Glesener 21, Luxembourg, 1631") is a
     * town, and deleting it moves the street into `city`.
     *
     * @param list<string> $components
     *
     * @return list<string>
     */
    private function removeCountryComponent(array $components, int $index): array
    {
        $remaining = [];
        foreach ($components as $i => $component) {
            if ($i !== $index && !$this->isStandalonePostcode($component)) {
                $remaining[] = $component;
            }
        }

        // Keeping the component is only ever right when its name is genuinely a settlement too.
        // "Luxembourg" is a city; "United Kingdom" is not, and filing it as the city is worse than
        // leaving the city empty — that value goes into a CITY column and onto correspondence.
        if (count($remaining) < 2 && $this->namesACityState($components[$index])) {
            return $components;
        }

        unset($components[$index]);

        return array_values($components);
    }

    private function namesACityState(string $component): bool
    {
        $country = $this->countries->resolve($component);

        return null !== $country && in_array($country->alpha2, self::CITY_STATES, true);
    }

    /**
     * @param list<string>          $components
     * @param array<string, string> $trace
     *
     * @return array{0: list<string>, 1: string, 2: Country|null, 3: array<string, string>}
     */
    private function extractPostcode(
        array $components,
        ?Country $country,
        bool $spaceInPostCode,
        array $trace,
    ): array {
        $lastIndex = count($components) - 1;

        // A middle component is only a postcode when the whole component is one. Anything looser
        // eats house and unit numbers — "Flat 3, 45A, London" lost its building number that way.
        for ($i = $lastIndex; $i >= 1; $i--) {
            if ($this->isStandalonePostcode($components[$i])) {
                $postcode = $this->normalisePostcode($components[$i], $spaceInPostCode);
                unset($components[$i]);
                $trace['postcode'] = 0 === $i ? 'the only component' : 'a component of its own';

                return [
                    array_values($components),
                    $postcode,
                    $country ?? $this->countryFromPostcodeShape($postcode, $trace),
                    $trace,
                ];
            }
        }

        // In the last component the postcode may be glued to the town ("… West Sussex BN43 5HZ").
        // A single-component address is scanned too — there is no first component to protect.
        if ($lastIndex >= 0 && (0 !== $lastIndex || 1 === count($components))) {
            $tail = $this->matchPostcodeTail($components[$lastIndex]);

            if (null !== $tail) {
                [$remainder, $raw] = $tail;
                $postcode = $this->normalisePostcode($raw, $spaceInPostCode);
                $components[$lastIndex] = $remainder;
                $trace['postcode'] = 'tail of the last component';

                $components = $this->compact($components);
                $resolved = $country ?? $this->countryFromPostcodeShape($postcode, $trace);

                // "…, United Kingdom DN14 0HR": cutting the postcode out leaves the country name
                // standing alone, where it would otherwise be filed as the city.
                if (null === $country && null === $resolved && isset($components[$lastIndex])) {
                    $resolved = $this->countries->resolve($components[$lastIndex]);

                    if (null !== $resolved) {
                        $trace['country'] = 'left behind by the postcode';
                        $components = $this->removeCountryComponent($components, $lastIndex);
                    }
                }

                return [$components, $postcode, $resolved, $trace];
            }
        }

        // Last resort: a full UK postcode or Eircode anywhere in the address. Some sources write
        // the postcode first ("E14 6JG United Kingdom London 11 Canton Street"), which the
        // tail-first rules above never see. Safe to scan anywhere because these patterns carry an
        // inward code — "9A 9AA" — and no house or unit number looks like that.
        foreach ($components as $i => $component) {
            if (1 !== preg_match('/(?:^|\s)(' . self::UK_POSTCODE . '|' . self::EIRCODE . ')(?:\s|$)/ui', $component, $match)) {
                continue;
            }

            $postcode = $this->normalisePostcode($match[1], $spaceInPostCode);
            $components[$i] = $this->trimEdges(str_replace($match[1], ' ', $component));
            $trace['postcode'] = 'a full postcode found mid-component';

            return [
                $this->compact($components),
                $postcode,
                $country ?? $this->countryFromPostcodeShape($postcode, $trace),
                $trace,
            ];
        }

        return [$components, '', $country, $trace];
    }

    /**
     * @param list<string>          $components
     * @param array<string, string> $trace
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function dropRedundantCountryComponent(array $components, ?Country $country, array $trace): array
    {
        if (null === $country || count($components) < 2) {
            return [$components, $trace];
        }

        $lastIndex = count($components) - 1;
        $last = $components[$lastIndex];
        $resolved = $this->countries->resolve($last);

        if (null === $resolved || $resolved->alpha2 !== $country->alpha2) {
            return [$components, $trace];
        }

        // A bare code ("…, MC, 98000 Monaco") is always a duplicate — no town is named "MC". A
        // full name may well be the town itself, so it only goes if a city remains without it.
        $isBareCode = mb_strlen($last) <= 3;

        if ($isBareCode || $lastIndex >= 2) {
            array_pop($components);
            $trace['country_duplicate'] = 'a later component repeated the country';
        }

        return [$components, $trace];
    }

    /**
     * line1 keeps reading order; the last remaining component is the city. line2 is only used when
     * no postcode was found — that is the legacy shape, and twelve call sites depend on it.
     *
     * @param list<string>          $components
     * @param array<string, string> $trace
     */
    private function layout(array $components, bool $hasPostcode, array $trace): ParsedAddress
    {
        $count = count($components);

        if (0 === $count) {
            return new ParsedAddress(trace: $trace);
        }

        // A lone component is the street. Filing it as the city leaves line1 empty, and line1 is
        // what downstream systems read as the client's address.
        if (1 === $count) {
            $trace['line1'] = 'the only component';

            return new ParsedAddress(line1: $components[0], trace: $trace);
        }

        $city = array_pop($components);
        $line2 = '';

        if (!$hasPostcode && count($components) >= 2) {
            $line2 = array_pop($components);
            $trace['line2'] = 'no postcode found, so the component before the city stays on its own line';
        }

        $trace['city'] = 'last component';
        $trace['line1'] = 'everything before the city, in reading order';

        return new ParsedAddress(
            line1: implode(' ', $components),
            line2: $line2,
            city: $city,
            trace: $trace,
        );
    }

    /**
     * @param array<string, string> $trace
     */
    private function countryFromPostcodeShape(string $postcode, array &$trace): ?Country
    {
        $candidate = mb_strtoupper(str_replace([' ', '-'], '', $postcode));

        if (1 === preg_match('/^' . self::EIRCODE . '$/', $candidate)
            && 1 !== preg_match('/^' . self::UK_POSTCODE . '$/', $candidate)) {
            $trace['country'] = 'inferred from the Eircode';

            return $this->countries->resolve('IE');
        }

        if (1 === preg_match('/^' . self::UK_POSTCODE . '$/', $candidate)) {
            $trace['country'] = 'inferred from the UK postcode';

            return $this->countries->resolve('GB');
        }

        return null;
    }

    /**
     * True when the whole component is a postcode. Deliberately strict: this decides whether a
     * middle component is removed from the address.
     */
    private function isStandalonePostcode(string $component): bool
    {
        $value = mb_strtoupper($this->trimEdges($component));

        foreach ([self::UK_POSTCODE, self::EIRCODE, self::US_ZIP] as $pattern) {
            if (1 === preg_match('/^' . $pattern . '$/u', $value)) {
                return true;
            }
        }

        // Digits only, four or more of them — a house number never reaches that length.
        return 1 === preg_match('/^' . self::NUMERIC_POSTCODE . '$/u', $value)
            && mb_strlen((string) preg_replace('/\D/', '', $value)) >= 4;
    }

    /**
     * @return array{0: string, 1: string}|null the component without the postcode, and the postcode
     */
    private function matchPostcodeTail(string $component): ?array
    {
        $value = $this->trimEdges($component);

        foreach ([self::UK_POSTCODE, self::EIRCODE, self::US_ZIP, self::NUMERIC_POSTCODE] as $pattern) {
            if (1 !== preg_match('/(?:^|\s)(' . $pattern . ')$/ui', $value, $match)) {
                continue;
            }

            $candidate = mb_strtoupper($match[1]);

            if (self::NUMERIC_POSTCODE === $pattern
                && mb_strlen((string) preg_replace('/\D/', '', $candidate)) < 4) {
                continue;
            }

            $remainder = $this->trimEdges(mb_substr($value, 0, mb_strlen($value) - mb_strlen($match[1])));

            // A postcode that swallowed the entire component is fine; one that leaves a fragment
            // of a word behind is a mis-match.
            return [$remainder, $candidate];
        }

        return null;
    }

    private function looksLikePostcode(string $word): bool
    {
        $value = mb_strtoupper($this->trimEdges($word));

        return 1 === preg_match('/^(?:' . self::UK_POSTCODE . '|' . self::EIRCODE . '|\d{4,})$/u', $value);
    }

    private function normalisePostcode(string $postcode, bool $spaceInPostCode): string
    {
        $value = mb_strtoupper($this->trimEdges($postcode));
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        if (!$spaceInPostCode) {
            return str_replace([' ', '-'], '', $value);
        }

        $value = str_replace('-', '', $value);

        // A UK postcode is always written as outward + space + inward, and the inward code is
        // always the last three characters. Restore the space when the input ran them together.
        $squashed = str_replace(' ', '', $value);

        if (1 === preg_match('/^' . self::UK_POSTCODE . '$/u', $squashed)) {
            return mb_substr($squashed, 0, -3) . ' ' . mb_substr($squashed, -3);
        }

        return $value;
    }

    /**
     * @param array<int, string> $components
     *
     * @return list<string>
     */
    private function compact(array $components): array
    {
        return array_values(array_filter(
            array_map(fn (string $c): string => $this->trimEdges($c), $components),
            static fn (string $c): bool => '' !== $c,
        ));
    }
}
