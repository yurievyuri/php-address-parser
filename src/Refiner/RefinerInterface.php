<?php

declare(strict_types=1);

namespace Address\Parser\Refiner;

use Address\Parser\ParsedAddress;

/**
 * A second opinion on an address the rule engine could not resolve cleanly.
 *
 * A refiner receives the original string *and* the draft the rules produced, so it can correct one
 * field without re-deriving the rest. Implementations live anywhere — this package ships an LLM
 * refiner and a libpostal client, and a host application can register its own class.
 */
interface RefinerInterface
{
    /**
     * @param list<\Address\Parser\Quality\Issue> $issues what the inspector found wrong with $draft
     *
     * @return ParsedAddress the corrected result, or $draft unchanged if it cannot do better
     */
    public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress;

    /**
     * Name used in configuration, logs, and the result's `source` field.
     */
    public function name(): string;
}
