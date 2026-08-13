<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Postcode;

/**
 * Resolves a postcode against a register.
 *
 * The contract takes a postcode and nothing else — that is the point. A register lookup needs no
 * street, no name, no company, so the interface makes it impossible to send them by accident.
 */
interface PostcodeLookupInterface
{
    /**
     * @return PostcodeLocation|null null when the postcode is unknown to the register
     */
    public function lookup(string $postcode): ?PostcodeLocation;

    /** Identifier for logs — which register answered. */
    public function describe(): string;
}
