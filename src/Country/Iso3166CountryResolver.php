<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Country;

/**
 * Resolves against a built-in ISO 3166-1 table, plus the aliases real addresses actually carry:
 * "England", "Great Britain", "USA", "Holland", "Eire".
 *
 * Matching is by normalised name (upper case, punctuation and spaces stripped), by alpha-2, or by
 * alpha-3 — the three forms an address component can take.
 */
final class Iso3166CountryResolver implements CountryResolverInterface
{
    /**
     * Alias (already normalised) => alpha-2. Constituent countries of the UK are listed because
     * addresses write them where the country belongs.
     */
    private const ALIASES = [
        'UK' => 'GB',
        'GREATBRITAIN' => 'GB',
        'ENGLAND' => 'GB',
        'ENGLANDANDWALES' => 'GB',
        'SCOTLAND' => 'GB',
        'WALES' => 'GB',
        'NORTHERNIRELAND' => 'GB',
        'UNITEDKINGDOMOFGREATBRITAINANDNORTHERNIRELAND' => 'GB',
        'USA' => 'US',
        'UNITEDSTATESOFAMERICA' => 'US',
        'HOLLAND' => 'NL',
        'THENETHERLANDS' => 'NL',
        'EIRE' => 'IE',
        'REPUBLICOFIRELAND' => 'IE',
        'SOUTHKOREA' => 'KR',
        'NORTHKOREA' => 'KP',
        'RUSSIA' => 'RU',
        'UAE' => 'AE',
        'CZECHREPUBLIC' => 'CZ',
        'IVORYCOAST' => 'CI',
        'VATICAN' => 'VA',
    ];

    /** @var array<string, Country> keyed by alpha-2 */
    private array $byAlpha2 = [];

    /** @var array<string, string> normalised name => alpha-2 */
    private array $byName = [];

    /** @var array<string, string> alpha-3 => alpha-2 */
    private array $byAlpha3 = [];

    /**
     * @param array<string, array{0: string, 1: string, 2: string}>|null $table alpha-2 => [name, normalised name, alpha-3]
     */
    public function __construct(?array $table = null)
    {
        $table ??= require __DIR__ . '/../../resources/countries.php';

        foreach ($table as $alpha2 => [$name, $normalised, $alpha3]) {
            $alpha2 = strtoupper((string) $alpha2);
            $this->byAlpha2[$alpha2] = new Country($name, $alpha2, strtoupper($alpha3));
            $this->byName[$normalised] = $alpha2;

            if ('' !== $alpha3) {
                $this->byAlpha3[strtoupper($alpha3)] = $alpha2;
            }
        }
    }

    public function resolve(string $candidate): ?Country
    {
        $normalised = self::normalise($candidate);

        if ('' === $normalised) {
            return null;
        }

        if (isset(self::ALIASES[$normalised])) {
            return $this->byAlpha2[self::ALIASES[$normalised]] ?? null;
        }

        if (isset($this->byName[$normalised])) {
            return $this->byAlpha2[$this->byName[$normalised]];
        }

        $length = mb_strlen($normalised);

        if (2 === $length && isset($this->byAlpha2[$normalised])) {
            return $this->byAlpha2[$normalised];
        }

        if (3 === $length && isset($this->byAlpha3[$normalised])) {
            return $this->byAlpha2[$this->byAlpha3[$normalised]];
        }

        return null;
    }

    /**
     * Same normalisation the reference country tables use: upper case, no spaces, no punctuation.
     * Multibyte-safe — a byte-wise strtoupper corrupts "Åland Islands".
     */
    public static function normalise(string $value): string
    {
        $value = (string) preg_replace('/[\s\'"‘’“”,.\-–—]/u', '', $value);

        return mb_strtoupper(trim($value));
    }
}
