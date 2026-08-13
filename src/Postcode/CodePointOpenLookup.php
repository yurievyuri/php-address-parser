<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Postcode;

/**
 * The same lookup, from a copy of the register held locally — no network, no third party.
 *
 * Answers the country and nothing else: Code-Point Open identifies administrative areas by GSS
 * code rather than by name, and a code is not something to write into an address field.
 *
 * This is the version to reach for when the addresses belong to customers: nothing leaves the
 * process, so there is no processor to contract with, no transfer to assess and no arrangement to
 * register. Ordnance Survey publish Code-Point Open free of charge; `tools/import-code-point-open.php`
 * turns the download into the SQLite file this class reads.
 *
 * Country comes from the GSS code in the dataset, which is exact — the register says which country
 * a postcode is in, it does not infer it.
 */
final class CodePointOpenLookup implements PostcodeLookupInterface
{
    /**
     * Country GSS codes used by Code-Point Open. Channel Islands and the Isle of Man are their own
     * jurisdictions and are not part of the United Kingdom.
     */
    private const COUNTRY_CODES = [
        'E92000001' => 'GB',  // England
        'S92000003' => 'GB',  // Scotland
        'W92000004' => 'GB',  // Wales
        'N92000002' => 'GB',  // Northern Ireland
        'L93000001' => 'JE',  // Channel Islands — Jersey
        'M83000003' => 'IM',  // Isle of Man
    ];

    private ?\PDO $pdo = null;

    private ?\PDOStatement $statement = null;

    public function __construct(
        private readonly string $databasePath,
    ) {
    }

    public function lookup(string $postcode): ?PostcodeLocation
    {
        $normalised = PostcodesIoLookup::normalise($postcode);

        if (!PostcodesIoLookup::looksLikeAUkPostcode($normalised)) {
            return null;
        }

        $statement = $this->statement();
        $statement->execute([$normalised]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        $countryCode = self::COUNTRY_CODES[(string) $row['country_code']] ?? null;

        if (null === $countryCode) {
            return null;
        }

        // The dataset holds a GSS code here ("E07000240"), not a name — the names live in a
        // separate code list. A code in a CITY column is worse than an empty one, so the district
        // is left out entirely: this register answers the country question, and only that.
        return new PostcodeLocation(countryCode: $countryCode);
    }

    public function describe(): string
    {
        return 'code-point-open';
    }

    private function statement(): \PDOStatement
    {
        if (null !== $this->statement) {
            return $this->statement;
        }

        if (!is_readable($this->databasePath)) {
            throw new \RuntimeException(sprintf(
                'the Code-Point Open database "%s" is not readable — build it with tools/import-code-point-open.php',
                $this->databasePath,
            ));
        }

        $this->pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        return $this->statement = $this->pdo->prepare(
            'SELECT country_code, district FROM postcodes WHERE postcode = ? LIMIT 1',
        );
    }
}
