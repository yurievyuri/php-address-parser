<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Quality;

/**
 * The ways a parse can come out wrong. These are the same six measures the rule engine is held to
 * on the production corpus, applied to a single address — which is what makes them usable as an
 * escalation trigger: a provider that costs money is only worth calling when one of them fires.
 */
enum Issue: string
{
    /** No country could be determined. Downstream registration rejects the address. */
    case CountryMissing = 'country_missing';

    /** Part of the input did not survive into any output field. */
    case TokenLost = 'token_lost';

    /** The city is the name of a country — almost always the country component misfiled. */
    case CityIsCountry = 'city_is_country';

    /** line1 is empty although the input was not. line1 is the street on payment instructions. */
    case Line1Empty = 'line1_empty';

    /** A postcode-shaped string is in the input but no postcode was extracted. */
    case PostcodeMissed = 'postcode_missed';

    /** What was taken for a postcode does not look like one. */
    case PostcodeImplausible = 'postcode_implausible';
}
