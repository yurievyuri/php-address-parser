<?php

declare(strict_types=1);

namespace Address\Parser;

/**
 * The result of parsing one address string.
 *
 * `trace` records which rule produced each field. It is what turns "the address was parsed
 * wrongly" from a reverse-engineering session into a lookup.
 */
final readonly class ParsedAddress
{
    /**
     * @param list<string>          $issues quality problems detected in this result
     * @param array<string, string> $trace  field name => name of the rule that filled it
     */
    public function __construct(
        public string $line1 = '',
        public string $line2 = '',
        public string $city = '',
        public string $postcode = '',
        public string $country = '',
        public string $countryCode = '',
        public array $issues = [],
        public array $trace = [],
        public ?string $source = null,
    ) {
    }

    /**
     * The shape legacy callers expect. Keys and semantics must not drift: existing code reads
     * these exact keys and writes them straight into database columns.
     *
     * @return array{line1: string, line2: string, city: string, postcode: string, country: string, country_code: string}
     */
    public function toLegacyArray(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'country_code' => $this->countryCode,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function with(array $fields): self
    {
        return new self(
            line1: isset($fields['line1']) ? (string) $fields['line1'] : $this->line1,
            line2: isset($fields['line2']) ? (string) $fields['line2'] : $this->line2,
            city: isset($fields['city']) ? (string) $fields['city'] : $this->city,
            postcode: isset($fields['postcode']) ? (string) $fields['postcode'] : $this->postcode,
            country: isset($fields['country']) ? (string) $fields['country'] : $this->country,
            countryCode: isset($fields['country_code']) ? (string) $fields['country_code'] : $this->countryCode,
            issues: isset($fields['issues']) ? (array) $fields['issues'] : $this->issues,
            trace: isset($fields['trace']) ? (array) $fields['trace'] : $this->trace,
            source: isset($fields['source']) ? (string) $fields['source'] : $this->source,
        );
    }

    public function isEmpty(): bool
    {
        return '' === $this->line1
            && '' === $this->line2
            && '' === $this->city
            && '' === $this->postcode
            && '' === $this->country;
    }
}
