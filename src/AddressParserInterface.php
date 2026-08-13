<?php

declare(strict_types=1);

namespace Address\Parser;

/**
 * Every parsing strategy implements this: the rule engine, an LLM-backed refiner, a libpostal
 * service, or anything a host application writes itself.
 */
interface AddressParserInterface
{
    /**
     * @param bool $spaceInPostCode keep the space inside a postcode ("W1K 3DE" instead of "W1K3DE")
     */
    public function parse(string $address, bool $spaceInPostCode = false): ParsedAddress;
}
