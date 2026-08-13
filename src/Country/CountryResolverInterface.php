<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Country;

/**
 * Resolves a candidate string ("United Kingdom", "UK", "GBR", "gb") to a country.
 *
 * Behind an interface because the host application may already own the authoritative country
 * table, and because tests must run without one.
 */
interface CountryResolverInterface
{
    public function resolve(string $candidate): ?Country;
}
