<?php

declare(strict_types=1);

namespace Address\Parser\Tests;

use Address\Parser\RuleBasedParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The behavioural specification: every case is a real production address, and every assertion
 * records a defect that reached users. Cases are grouped by the failure they pin down.
 */
#[CoversClass(RuleBasedParser::class)]
final class RuleBasedParserSpecTest extends TestCase
{
    /**
     * @return array{line1: string, line2: string, city: string, postcode: string, country: string, country_code: string}
     */
    private function parse(string $address, bool $spaceInPostCode = false): array
    {
        return (new RuleBasedParser())->parse($address, $spaceInPostCode)->toLegacyArray();
    }

    /**
     * parseAddressFormat() unconditionally treats the last comma-separated component as the
     * country. UK company addresses in the CRM are stored as "street, city, postcode" — there
     * is no country at the end — so the postcode lands in `country`, the location lookup fails,
     * and country_code comes back empty. That empty country_code is what fails offline
     * registration's address validation for ~20% of corporate leads (BUG-1715).
     */
    #[DataProvider('ukCompanyAddressesWithoutTrailingCountryProvider')]
    public function testParseAddressFormatResolvesCountryForUkAddressesWithoutTrailingCountry(
        string $address,
        string $expectedCountryCode
    ): void {
        $result = $this->parse($address);

        self::assertNotSame(
            '',
            $result['country_code'],
            "country_code must not be empty for a real UK address without a trailing country component: {$address}"
        );
        self::assertSame($expectedCountryCode, $result['country_code']);
    }

    public static function ukCompanyAddressesWithoutTrailingCountryProvider(): array
    {
        return [
            // [address, expected country_code] — captured from prod, cursol_registration_activities_register / b_tasks, 2026-08-11
            'street, city, postcode + location id suffix' => [
                '15 Davies Street London, W1K 3DE|;|386474',
                'GB',
            ],
            'street, city, county, postcode + location id suffix' => [
                '12-14 Benhill Avenue, Sutton, Surrey, SM1 4DA|;|386444',
                'GB',
            ],
            'street, city, county, postcode' => [
                'Webbs Of Wychbold, Worcester, Road, Wychbold, Droitwich, Worcestershire, WR9 0DG|;|386320',
                'GB',
            ],
        ];
    }

    /**
     * Addresses that already end with an explicit country must keep working —
     * this pins the case the parser was actually designed for, so a fix for the defect
     * above cannot regress it.
     */
    public function testParseAddressFormatStillResolvesCountryWhenAddressEndsWithCountryName(): void
    {
        $result = $this->parse('221B Baker Street, London, NW1 6XE, United Kingdom');

        self::assertSame('GB', $result['country_code']);
    }

    /**
     * The second failure class measured over the 9759 distinct production company addresses:
     * the postcode is not a component of its own, it is glued to the town ("… West Sussex
     * BN43 5HZ") — often the whole address is a single comma-less string. Taking the last
     * component whole gives a nine-character slice of the town name, which is neither a
     * postcode nor a country.
     */
    #[DataProvider('postcodeGluedToTheTownProvider')]
    public function testParseAddressFormatFindsThePostcodeAtTheEndOfAComponent(
        string $address,
        string $expectedCountryCode,
        string $expectedPostcode
    ): void {
        $result = $this->parse($address);

        self::assertSame($expectedCountryCode, $result['country_code'], "country_code for: {$address}");
        self::assertSame($expectedPostcode, $result['postcode'], "postcode for: {$address}");
    }

    public static function postcodeGluedToTheTownProvider(): array
    {
        return [
            // captured from prod b_uts_crm_lead.UF_CRM_COMP_ADDRESS_EX, 2026-08-12
            'no commas at all' => [
                '1 Harbour House Harbour Way Shoreham-By-Sea West Sussex BN43 5HZ|;',
                'GB',
                'BN435HZ',
            ],
            'town and postcode in the last component' => [
                '1, 9 BURREL, St. Ives PE27 3LE|;',
                'GB',
                'PE273LE',
            ],
            'northern ireland, country word inside the string' => [
                '1 Springbank Road Springbank Industrial Estate Belfast Northern Ireland BT17 0QL|;',
                'GB',
                'BT170QL',
            ],
            'irish eircode as the last component' => [
                '1 THE CRESCENT, Limerick, IRELAND, V94 W9TF|;',
                'IE',
                'V94W9TF',
            ],
            'irish eircode, no country word' => [
                '13 SOCIETY STREET, BALLINASLOEGALWAY, H53N9X3|;',
                'IE',
                'H53N9X3',
            ],
        ];
    }

    /**
     * Third failure class: the last component carries town, postcode and country at once
     * ("Bromsgrove B603DJ GB"), so neither the whole component nor its tail is a bare postcode.
     * Splitting the country off first is what makes the postcode findable.
     */
    #[DataProvider('countryGluedToTheLastComponentProvider')]
    public function testParseAddressFormatSplitsACountryOffTheEndOfAComponent(
        string $address,
        string $expectedCountryCode,
        string $expectedPostcode
    ): void {
        $result = $this->parse($address);

        self::assertSame($expectedCountryCode, $result['country_code'], "country_code for: {$address}");
        self::assertSame($expectedPostcode, $result['postcode'], "postcode for: {$address}");
    }

    public static function countryGluedToTheLastComponentProvider(): array
    {
        return [
            // captured from prod b_uts_crm_lead.UF_CRM_COMP_ADDRESS_EX, 2026-08-12
            'town, postcode and two-letter code' => [
                '12 the courtyard, Buntsford drive, Bromsgrove B603DJ GB|;|317320',
                'GB',
                'B603DJ',
            ],
            'town, postcode and code, comma-led address' => [
                ',Shubette House, Apsley Way, London NW2 7HF GB|;|323754',
                'GB',
                'NW27HF',
            ],
            'no commas, full country name at the end' => [
                '1 Vulcan Way Coalville Leicestershire LE67 3AP United Kingdom|;',
                'GB',
                'LE673AP',
            ],
            'foreign country name glued to its postcode' => [
                '13 bd. Princesse Charlotte, MC, 98000 Monaco|;|304246',
                'MC',
                '98000',
            ],
        ];
    }

    /**
     * line1 used to be assembled from the reversed component list, so any address with four
     * or more components came out backwards — and that is what went to UserAPI as the
     * client's street address.
     */
    public function testParseAddressFormatKeepsLine1InReadingOrder(): void
    {
        $result = $this->parse(
            'Webbs Of Wychbold, Worcester Road, Wychbold, Droitwich, Worcestershire, WR9 0DG'
        );

        self::assertSame('Webbs Of Wychbold Worcester Road Wychbold Droitwich', $result['line1']);
        self::assertSame('Worcestershire', $result['city']);
    }

    /**
     * The street must stay in line1. Pulling the country and the postcode out before the
     * positional split left the canonical four-part address with an empty line1 and the street
     * sitting in line2 — and line1 is what goes to UserAPI as the client's address and onto
     * payment instructions via Cursol\BB\Entity\Address. Found in review, after the corpus
     * measurements had already passed.
     *
     * @param array<string, string> $expected subset of fields to check
     */
    #[DataProvider('fieldLayoutProvider')]
    public function testParseAddressFormatPutsEachPartWhereItBelongs(string $address, array $expected): void
    {
        $result = $this->parse($address);

        foreach ($expected as $field => $value) {
            self::assertSame($value, $result[$field], "{$field} for: {$address}");
        }
    }

    public static function fieldLayoutProvider(): array
    {
        return [
            'the shape the parser was designed for' => [
                '221B Baker Street, London, NW1 6XE, United Kingdom',
                ['line1' => '221B Baker Street', 'line2' => '', 'city' => 'London', 'postcode' => 'NW16XE'],
            ],
            'no country, postcode last' => [
                '15 Davies Street, London, W1K 3DE',
                ['line1' => '15 Davies Street', 'city' => 'London', 'postcode' => 'W1K3DE'],
            ],
            'postcode in the middle' => [
                '5 Eastfields Avenue, SW18 1JY, London',
                ['line1' => '5 Eastfields Avenue', 'city' => 'London', 'postcode' => 'SW181JY'],
            ],
            'no postcode at all — the second line keeps its place' => [
                'Orchard Cottage, Holmbury St. Mary, Dorking',
                ['line1' => 'Orchard Cottage', 'line2' => 'Holmbury St. Mary', 'city' => 'Dorking'],
            ],
        ];
    }

    /**
     * The postcode scan must not swallow a house or unit number: it walked every component
     * from the end to the first one and removed whatever matched, so "221B, Baker Street,
     * London" lost its house number entirely — it appeared in neither line, and 221B was
     * written into the lead's ADDRESS_POSTAL_CODE. Found in review.
     */
    #[DataProvider('houseNumberProvider')]
    public function testParseAddressFormatDoesNotEatTheHouseNumber(string $address, string $mustKeep): void
    {
        $result = $this->parse($address);

        self::assertSame('', $result['postcode'], "a house/unit number was taken for a postcode in: {$address}");
        self::assertStringContainsString(
            $mustKeep,
            $result['line1'] . ' ' . $result['line2'] . ' ' . $result['city'],
            "'{$mustKeep}' disappeared from: {$address}"
        );
    }

    public static function houseNumberProvider(): array
    {
        return [
            'house number as its own component' => ['221B, Baker Street, London', '221B'],
            'flat number first' => ['Apt 12, High Street, Hull', 'Apt 12'],
            'unit number in the middle' => ['5 Fifth Ave, Apt 12, New York', 'Apt 12'],
            'number with a letter' => ['12A, High Street, Hull', '12A'],
            'suite' => ['Ste 200, Market Street, Leeds', 'Ste 200'],
            // second review: a building number in a middle component was still eaten
            'building number between street and city' => ['Flat 3, 45A, London', '45A'],
            'building number, longer street' => ['12 High Street, 22B, Leeds', '22B'],
        ];
    }

    /**
     * A town that shares its name with a country must not be deleted from the address.
     * Accepting the second-to-last component as the country and removing it turned
     * "Rue Glesener 21, Luxembourg, 1631" into a country plus a street filed as the city.
     * Found in the second review.
     */
    #[DataProvider('cityNamedLikeACountryProvider')]
    public function testParseAddressFormatKeepsATownThatIsNamedAfterItsCountry(
        string $address,
        string $expectedCountryCode,
        string $expectedCity
    ): void {
        $result = $this->parse($address);

        self::assertSame($expectedCountryCode, $result['country_code'], "country_code for: {$address}");
        self::assertSame($expectedCity, $result['city'], "city for: {$address}");
    }

    public static function cityNamedLikeACountryProvider(): array
    {
        return [
            'luxembourg' => ['RUE GLESENER 21, LUXEMBOURG, 1631', 'LU', 'LUXEMBOURG'],
            'panama' => ['Calle 50, Panama, 0819', 'PA', 'Panama'],
            'monaco' => ['25 Rue Grimaldi, Monaco, 98000', 'MC', 'Monaco'],
            'singapore' => ['1 Raffles Place, Singapore, 079903', 'SG', 'Singapore'],
        ];
    }

    /**
     * The other half of the same rule: when there IS a town behind the country component, the
     * country must be taken out so the town becomes the city. Leaving it in filed "UNITED
     * KINGDOM" as the city of 10.6% of the corpus and pushed the real town into line1 — and
     * that value goes to b_crm_addr.CITY and into the registration payload. Third review.
     */
    #[DataProvider('countryBeforeThePostcodeProvider')]
    public function testParseAddressFormatDoesNotFileTheCountryAsTheCity(
        string $address,
        string $expectedCity,
        string $expectedLine1
    ): void {
        $result = $this->parse($address);

        self::assertSame($expectedCity, $result['city'], "city for: {$address}");
        self::assertSame($expectedLine1, $result['line1'], "line1 for: {$address}");
    }

    public static function countryBeforeThePostcodeProvider(): array
    {
        return [
            'town, country, postcode' => [
                '100 Barbirolli Square C/O Kjg, Manchester, UNITED KINGDOM, M2 3BD',
                'Manchester',
                '100 Barbirolli Square C/O Kjg',
            ],
            'country last, town before it' => [
                '221B Baker Street, London, NW1 6XE, United Kingdom',
                'London',
                '221B Baker Street',
            ],
        ];
    }

    /**
     * "New Jersey" must not be read as Jersey, "New Mexico" not as Mexico. The last word of the
     * final component was matched against the whole country list, so a US lead was registered
     * against a country it has nothing to do with — and processAddresses() only checks that
     * *some* country code came back, so nothing complained. Third review.
     */
    #[DataProvider('usStateProvider')]
    public function testParseAddressFormatDoesNotReadAUsStateAsACountry(string $address, string $expectedCity): void
    {
        $result = $this->parse($address);

        self::assertSame('', $result['country_code'], "country_code for: {$address}");
        self::assertSame($expectedCity, $result['city'], "city for: {$address}");
    }

    public static function usStateProvider(): array
    {
        return [
            'new jersey' => ['100 Main St, Newark, New Jersey', 'New Jersey'],
            'new mexico' => ['1600 Central Ave, Albuquerque, New Mexico', 'New Mexico'],
        ];
    }

    /**
     * A placeholder component ("-") must be dropped like an empty one, instead of becoming the
     * city and demoting the real town; and a bare country code left in the middle of the address
     * must not be filed as the city either. Third review.
     */
    #[DataProvider('leftoverComponentProvider')]
    public function testParseAddressFormatIgnoresPlaceholderAndDuplicateCountryComponents(
        string $address,
        string $expectedCity,
        string $expectedLine1
    ): void {
        $result = $this->parse($address);

        self::assertSame($expectedCity, $result['city'], "city for: {$address}");
        self::assertSame($expectedLine1, $result['line1'], "line1 for: {$address}");
    }

    public static function leftoverComponentProvider(): array
    {
        return [
            'dash placeholder as the last component' => [
                'ROSHINE ROAD, KILLYBEGSDONEGAL, -',
                'KILLYBEGSDONEGAL',
                'ROSHINE ROAD',
            ],
            'country code repeated in the middle' => [
                '13 bd. Princesse Charlotte, MC, 98000 Monaco',
                '',
                '13 bd. Princesse Charlotte',
            ],
        ];
    }

    /**
     * When country and postcode extraction leave a single component, that component is the
     * street — it must go to line1, which is what UserAPI and Cursol\BB\Entity\Address read.
     * It was being written to `city` instead, leaving line1 empty. Found in the second review.
     */
    public function testParseAddressFormatPutsALoneRemainingComponentOnLine1(): void
    {
        $result = $this->parse('Marble Hall Nightingale Road Derby England DE24 8BG');

        self::assertSame('GB', $result['country_code']);
        self::assertSame('DE248BG', $result['postcode']);
        self::assertSame('Marble Hall Nightingale Road Derby England', $result['line1']);
    }

    /**
     * The legacy GB re-extraction regex cannot cope with a space in the postcode: with the
     * country now inferred from the postcode's own shape, UK addresses reached that branch for
     * the first time and "W1K 3DE" came out as "W1K". $spaceInPostCode = true is what
     * Cursol\BB\Entity\Address (beneficiary bank addresses) uses. Found in the second review.
     */
    #[DataProvider('spacedPostcodeProvider')]
    public function testParseAddressFormatDoesNotTruncateASpacedUkPostcode(string $address, string $expected): void
    {
        self::assertSame($expected, $this->parse($address, true)['postcode'], $address);
    }

    public static function spacedPostcodeProvider(): array
    {
        return [
            'outward code ending in a letter' => ['10 Downing St, London, W1K 3DE', 'W1K 3DE'],
            'outward code ending in a digit' => ['5 Eastfields Avenue, London, SW18 1JY', 'SW18 1JY'],
            'with an explicit country' => ['1 High Street, Bromsgrove, B60 3DJ, United Kingdom', 'B60 3DJ'],
        ];
    }

    /**
     * A trailing comma pushed the country out of the last position, so the country name
     * landed in `city` and the address was rejected. Real prod shape (defect 3.1, BUG-1715).
     */
    public function testParseAddressFormatResolvesCountryDespiteATrailingComma(): void
    {
        $result = $this->parse('C/O Mgb Accountants, 12 High Street, Leeds, United Kingdom,');

        self::assertSame('GB', $result['country_code']);
    }

    /**
     * Hostile-input guard for the UK fallback: a non-UK address without a country component
     * must not be silently labelled GB. The postcode shape is the only evidence we accept.
     */
    #[DataProvider('nonUkAddressesWithoutCountryProvider')]
    public function testParseAddressFormatDoesNotInventACountry(string $address): void
    {
        $result = $this->parse($address);

        self::assertNotSame('GB', $result['country_code'], "must not be labelled GB: {$address}");
    }

    public static function nonUkAddressesWithoutCountryProvider(): array
    {
        return [
            'us zip' => ['1 Main Street, Springfield, 62704'],
            'us state and zip' => ['350 Fifth Avenue, New York, NY 10118'],
            'canadian postcode' => ['301 Front St W, Toronto, M5V 2T6'],
            'german plz' => ['Friedrichstrasse 43, Berlin, 10117'],
        ];
    }

    /**
     * A component only becomes the postcode when it can plausibly be one. Once the parser
     * stopped treating the trailing component as the country, a one-line address like
     * "13 Mink Court" started landing in `postcode` — and the lead listeners write that
     * straight into ADDRESS_POSTAL_CODE. Street text belongs in the address lines, not there.
     */
    #[DataProvider('postcodeShapeProvider')]
    public function testParseAddressFormatOnlyCallsSomethingAPostcodeWhenItLooksLikeOne(
        string $address,
        string $expectedPostcode
    ): void {
        self::assertSame($expectedPostcode, $this->parse($address)['postcode'], $address);
    }

    public static function postcodeShapeProvider(): array
    {
        return [
            // street text must not be mistaken for a postcode (real prod lead addresses)
            'single line street, no postcode' => ['13 Mink Court', ''],
            'single line street, lowercase' => ['121 lower road', ''],
            'flat number is not a postcode' => ['Flat 3, Unit 5', ''],
            'a bare house number is not a postcode' => ['45', ''],
            'non-latin street is not a postcode' => ['Руски 65', ''],
            // …while real postcodes of several countries still are
            'uk' => ['15 Davies Street, London, W1K 3DE', 'W1K3DE'],
            'saudi numeric' => ['NEOM Base Camp, NEOM, 9136 49643, Saudi Arabia', '913649643'],
            'german plz' => ['Friedrichstrasse 43, Berlin, 10117, Germany', '10117'],
            'us zip with state' => ['350 Fifth Avenue, New York, NY 10118, United States', 'NY10118'],
            'irish eircode' => ['1 The Crescent, Limerick, V94 W9TF', 'V94W9TF'],
        ];
    }

    /**
     * Property-based pass over a real corpus: every 24th distinct company address from prod
     * (b_uts_crm_lead.UF_CRM_COMP_ADDRESS_EX, 9759 distinct values on 2026-08-12), which is
     * far too wide an input space to enumerate by hand.
     *
     * Invariants, not expected values — the corpus is a sample, and its rows change over time.
     *
     * Baseline over the full 9759 rows: 45.1% resolved no country before the fix, 6.3% after;
     * no address changed its country code and none lost one.
     */
    public function testParseAddressFormatHoldsItsInvariantsOverTheProductionCorpus(): void
    {
        $corpus = self::companyAddressCorpus();
        self::assertGreaterThan(300, count($corpus), 'corpus fixture looks truncated');

        $resolved = 0;
        foreach ($corpus as $address) {
            $result = $this->parse($address);
            $code = (string)$result['country_code'];

            if ($code !== '') {
                $resolved++;
                self::assertMatchesRegularExpression(
                    '#^[A-Z]{2,3}$#',
                    $code,
                    "country_code must be an ISO code, got '{$code}' for: {$address}"
                );
            }

            $normalise = static fn(string $v): string => mb_strtoupper(str_replace([' ', '-'], '', $v));
            if ((string)$result['postcode'] !== '') {
                self::assertNotSame(
                    $normalise((string)$result['postcode']),
                    $normalise((string)$result['country']),
                    "the postcode was taken for the country in: {$address}"
                );
            }
        }

        // Regression guard, deliberately below the measured 93.7% so ordinary corpus drift
        // does not turn this red — it fires when a change undoes the fix, not when the data moves.
        $share = $resolved / count($corpus);
        self::assertGreaterThan(
            0.85,
            $share,
            sprintf('only %.1f%% of the corpus resolved to a country', $share * 100)
        );
    }

    /**
     * Found by running detectors over all 297 026 addresses on prod rather than by reading the
     * diff: a full stop at the end of a component hid both the postcode and the country from
     * every check ("GL54 4LZ." is not a postcode, "Ireland." is not a country). 468 addresses
     * carried a UK postcode the parser did not see, 479 ended up with a country for a city.
     */
    #[DataProvider('trailingPunctuationProvider')]
    public function testParseAddressFormatIgnoresTrailingPunctuationOnComponents(
        string $address,
        string $expectedCountryCode,
        string $expectedPostcode,
        string $expectedCity
    ): void {
        $result = $this->parse($address);

        self::assertSame($expectedCountryCode, $result['country_code'], "country_code for: {$address}");
        self::assertSame($expectedPostcode, $result['postcode'], "postcode for: {$address}");
        self::assertSame($expectedCity, $result['city'], "city for: {$address}");
    }

    public static function trailingPunctuationProvider(): array
    {
        return [
            // captured from prod, 2026-08-12
            'postcode with a full stop' => ['Andoversford, Cheltenham, GL54 4LZ.', 'GB', 'GL544LZ', 'Cheltenham'],
            'country with a full stop' => ['Unit 3, Little Island, Cork City, Ireland.', 'IE', '', 'Cork City'],
            'both, country last' => ['1 High St, Leeds, LS1 4AP, United Kingdom.', 'GB', 'LS14AP', 'Leeds'],
        ];
    }

    /**
     * Stripping the edges must not break the encoding: a byte-based trim() with an em dash in
     * its character list cuts multibyte characters in half. Caught by three prod addresses
     * (Hebrew, and one carrying a zero-width space) whose parse result stopped being valid
     * UTF-8 — json_encode returned false on them.
     */
    #[DataProvider('multibyteAddressProvider')]
    public function testParseAddressFormatKeepsTheResultValidUtf8(string $address): void
    {
        $result = $this->parse($address);

        self::assertNotFalse(json_encode($result), "result is not valid UTF-8 for: {$address}");
        foreach ($result as $field => $value) {
            self::assertTrue(mb_check_encoding((string)$value, 'UTF-8'), "field {$field} is not valid UTF-8");
        }
    }

    public static function multibyteAddressProvider(): array
    {
        return [
            // captured from prod, 2026-08-12
            'hebrew' => ['9338906 ירושלים  6 בית הערבה'],
            'zero-width space inside' => ["\u{200B}Leoforos Artemidos, 24\u{200B}, shop 5, Larnaka, 6030, Cyprus"],
            'em dash as a separator' => ['Flat 2 — Baker Street, London, W1K 3DE'],
            'cyrillic' => ['ул. Ленина 5, Москва, 101000'],
        ];
    }

    /**
     * Same measurement: "…, YORKSHIRE, United Kingdom DN14 0HR" — country and postcode share the
     * last component, so once the postcode is cut out the country is left standing there and
     * became the city. The country has to be looked for again after the postcode is removed.
     */
    public function testParseAddressFormatFindsACountryLeftBehindByThePostcode(): void
    {
        $result = $this->parse(
            'Goole  Whitley  Doncaster Road  Whitley Lodge, North Humberside, YORKSHIRE, United Kingdom DN14 0HR'
        );

        self::assertSame('GB', $result['country_code']);
        self::assertSame('DN140HR', $result['postcode']);
        self::assertSame('YORKSHIRE', $result['city']);
    }

    /**
     * The invariant that would have caught, without anyone reading the diff, both of the
     * defects the second review found and two of the third's: nothing may vanish. Every
     * meaningful token of the input has to survive somewhere in the output — the parser
     * redistributes an address, it never discards part of it.
     *
     * A deleted house number ("Flat 3, 45A, London"), a deleted town ("…, LUXEMBOURG, 1631")
     * and a town turned into "New" all break this immediately, on any corpus. Reviewers found
     * them one shape at a time; this states the rule once.
     *
     * The single allowed loss is a word that names the country that was resolved — "England"
     * legitimately becomes "United Kingdom". Measured over the corpus: 28 such rows, and zero
     * losses of any other kind.
     */
    public function testParseAddressFormatNeverDiscardsPartOfTheAddress(): void
    {
        // Words that name a country rather than a place in it: the parser replaces them with the
        // canonical country name, so they legitimately do not survive verbatim.
        $countryWords = [
            'ENGLAND', 'SCOTLAND', 'WALES', 'BRITAIN', 'GREAT', 'UNITED', 'KINGDOM', 'UK', 'GB', 'GBR',
            'STATES', 'AMERICA', 'USA', 'EIRE', 'IRELAND', 'IRL', 'HOLLAND',
            // "Northern Ireland" and "UAE" name countries too, and are likewise replaced by the
            // canonical name. Listing them keeps the invariant about places, not about spellings.
            'NORTHERN', 'UAE', 'EMIRATES', 'ARAB',
        ];

        $normalise = static function (string $value): string {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

            return (string)preg_replace('#[^A-Z0-9]#', '', mb_strtoupper($ascii === false ? $value : $ascii));
        };
        $offenders = [];

        foreach (self::companyAddressCorpus() as $address) {
            $result = $this->parse($address);

            // the "|;|<location id>" suffix is metadata, not part of the address
            $source = explode('|', $address)[0];
            $output = $normalise(implode('', [
                $result['line1'], $result['line2'], $result['city'], $result['postcode'], $result['country'],
            ]));

            $resolvedCountry = $normalise((string)$result['country']);

            foreach (preg_split('#[^A-Za-z0-9]+#', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (mb_strlen($token) < 3
                    || in_array(mb_strtoupper($token), $countryWords, true)
                    // a word of the country's own name, e.g. "Curacao" for "Curaçao"
                    || ($resolvedCountry !== '' && str_contains($resolvedCountry, $normalise($token)))
                ) {
                    continue;
                }
                if (!str_contains($output, $normalise($token))) {
                    $offenders[] = sprintf("'%s' lost from: %s", $token, $address);
                }
            }
        }

        self::assertSame([], $offenders, "the parser dropped parts of these addresses:\n" . implode("\n", array_slice($offenders, 0, 10)));
    }

    /**
     * A UK postcode is written outward + space + inward, always — the inward code is the last
     * three characters. Addresses stored without the space must still come back canonical when
     * the caller asks for the spaced form.
     */
    #[DataProvider('squashedUkPostcodeProvider')]
    public function testParseAddressFormatRestoresTheSpaceInAUkPostcode(string $address, string $expected): void
    {
        self::assertSame($expected, $this->parse($address, true)['postcode'], $address);
    }

    public static function squashedUkPostcodeProvider(): array
    {
        return [
            // captured from prod — the space is missing in the stored value
            'outward of three, no space' => ['12 the courtyard, Bromsgrove B603DJ GB', 'B60 3DJ'],
            'outward of four, no space' => ['1 Vulcan Way, Coalville, LE673AP', 'LE67 3AP'],
            'already spaced, left alone' => ['15 Davies Street, London, W1K 3DE', 'W1K 3DE'],
            'non-uk numeric is not touched' => ['Friedrichstrasse 43, Berlin, 10117, Germany', '10117'],
        ];
    }

    /** @return string[] */
    private static function companyAddressCorpus(): array
    {
        $file = __DIR__ . '/fixtures/prod-company-addresses.txt';

        // The corpus is real customer data and is deliberately not in the repository. Point this
        // path at your own export to run the property-based passes; without it they are skipped
        // and the case-by-case specification above still runs in full.
        if (!is_readable($file)) {
            self::markTestSkipped('no address corpus at tests/fixtures/prod-company-addresses.txt');
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_values(array_filter(array_map(
            static fn(string $line): string => html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $lines ?: []
        ), static fn(string $line): bool => trim($line) !== ''));
    }
}
