<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Facade;

use Codelot\AddressParser\AddressParserInterface;
use Codelot\AddressParser\Config\ParserFactory;
use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\RuleBasedParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Drop-in replacement for a legacy static address-parsing function.
 *
 * Made for the case where the old parser is called from many places at once and none of them can
 * be changed: the old function becomes one line delegating here, and every call site keeps working.
 *
 *     public static function parseAddressFormat($address = '', bool $spaceInPostCode = false): array
 *     {
 *         return LegacyArrayParser::parse($address, $spaceInPostCode);
 *     }
 *
 * Two properties make that safe to do in one step.
 *
 * **It never throws.** Legacy call sites sit inside event handlers and business processes that have
 * no error handling — an exception there does not surface as a parsing problem, it stops a lead
 * from saving. Any failure falls back to the rule engine, and a failure of *that* returns an empty
 * result of the right shape.
 *
 * **It accepts what the old signature accepted.** The legacy function was untyped, so null, numbers
 * and even an already-parsed array reach it in practice.
 */
final class LegacyArrayParser
{
    private static ?AddressParserInterface $parser = null;

    private static LoggerInterface $logger;

    /** Prevents an unusable configuration from being retried on every single call. */
    private static bool $configurationFailed = false;

    /**
     * Wire in the parser the application built — with its services, cache and logger. Call once,
     * during application setup.
     */
    public static function configure(AddressParserInterface $parser, ?LoggerInterface $logger = null): void
    {
        self::$parser = $parser;
        self::$logger = $logger ?? new NullLogger();
        self::$configurationFailed = false;
    }

    /**
     * Configure straight from a configuration file, for an application with nowhere convenient to
     * hold the built parser.
     */
    public static function configureFromFile(string $path, ?LoggerInterface $logger = null): void
    {
        self::configure((new ParserFactory(logger: $logger ?? new NullLogger()))->createFromFile($path), $logger);
    }

    public static function reset(): void
    {
        self::$parser = null;
        self::$configurationFailed = false;
    }

    /**
     * @return array{line1: string, line2: string, city: string, postcode: string, country: string, country_code: string}
     */
    public static function parse(mixed $address, bool $spaceInPostCode = false): array
    {
        $string = self::toStringInput($address);

        if ('' === $string) {
            return (new ParsedAddress())->toLegacyArray();
        }

        try {
            return self::parser()->parse($string, $spaceInPostCode)->toLegacyArray();
        } catch (\Throwable $e) {
            self::logger()->error('address parsing failed, falling back to rules', [
                'address' => $string,
                'error' => $e->getMessage(),
            ]);
        }

        // The configured parser failed as a whole — a bad service, a broken config. The rule engine
        // has no dependencies and cannot be taken down by any of that.
        try {
            return (new RuleBasedParser())->parse($string, $spaceInPostCode)->toLegacyArray();
        } catch (\Throwable $e) {
            self::logger()->critical('address parsing failed entirely', [
                'address' => $string,
                'error' => $e->getMessage(),
            ]);

            return (new ParsedAddress())->toLegacyArray();
        }
    }

    /**
     * The full result, for a call site that can use more than the six legacy keys — the trace of
     * which rule filled which field, the issues found, which service produced it.
     */
    public static function parseToObject(mixed $address, bool $spaceInPostCode = false): ParsedAddress
    {
        $string = self::toStringInput($address);

        if ('' === $string) {
            return new ParsedAddress();
        }

        try {
            return self::parser()->parse($string, $spaceInPostCode);
        } catch (\Throwable $e) {
            self::logger()->error('address parsing failed, falling back to rules', [
                'address' => $string,
                'error' => $e->getMessage(),
            ]);

            return (new RuleBasedParser())->parse($string, $spaceInPostCode);
        }
    }

    /**
     * The legacy signature was untyped, so anything can arrive. An already-parsed array is passed
     * through as its own line1 rather than being mangled by a second parse.
     */
    private static function toStringInput(mixed $address): string
    {
        if (is_string($address)) {
            return trim($address);
        }

        if (null === $address || is_bool($address)) {
            return '';
        }

        if (is_int($address) || is_float($address)) {
            return (string) $address;
        }

        if ($address instanceof \Stringable) {
            return trim((string) $address);
        }

        if (is_array($address)) {
            // Legacy code sometimes hands over a result of a previous parse; re-joining its parts
            // is the closest thing to a faithful round trip.
            $parts = array_filter(
                array_map(
                    static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                    $address,
                ),
                static fn (string $v): bool => '' !== $v,
            );

            return trim(implode(', ', $parts));
        }

        return '';
    }

    private static function parser(): AddressParserInterface
    {
        if (null !== self::$parser) {
            return self::$parser;
        }

        // Unconfigured is a valid state: rules alone are useful, and a host that never calls
        // configure() should still get a working parser rather than an exception.
        return self::$parser = new RuleBasedParser();
    }

    private static function logger(): LoggerInterface
    {
        return self::$logger ??= new NullLogger();
    }
}
