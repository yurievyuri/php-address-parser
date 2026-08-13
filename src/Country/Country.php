<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Country;

final readonly class Country
{
    public function __construct(
        public string $name,
        public string $alpha2,
        public string $alpha3 = '',
    ) {
    }
}
