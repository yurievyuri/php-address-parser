<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Postcode;

/**
 * What a postcode register knows about one postcode.
 *
 * Deliberately narrow. A register of this kind answers "which country and which administrative
 * area", not "what is the full address" — that is a different, licensed product.
 */
final readonly class PostcodeLocation
{
    public function __construct(
        public string $countryCode,
        /** Administrative district. NOT the postal town: for EC4M it is "City of London", not "London". */
        public string $district = '',
        public string $region = '',
    ) {
    }
}
