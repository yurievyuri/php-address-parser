<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Refiner;

use Codelot\AddressParser\Country\CountryResolverInterface;
use Codelot\AddressParser\Country\Iso3166CountryResolver;
use Codelot\AddressParser\Llm\LlmClientInterface;
use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\Quality\Issue;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Asks a language model to re-split an address the rules could not resolve.
 *
 * This is where an LLM genuinely beats a rule: an address is natural language written by a person,
 * and the long tail of how people write them does not compress into patterns. What the model is
 * *not* allowed to do is invent — it may only redistribute the words it was given, and the result
 * is checked against that before it is accepted. A model that adds a postcode it "knows" for a
 * street would quietly corrupt records, which is worse than an unparsed address.
 */
final class LlmRefiner implements RefinerInterface
{
    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
        You split postal addresses into structured components. The addresses come from a CRM, are
        written by people in many countries, and are frequently malformed.

        Rules:
        - Use only the words present in the input. Never add a postcode, city, or country that is
          not written there. Correcting obvious typos in a postcode is allowed; supplying a missing
          one is not.
        - Every meaningful word of the input must appear in exactly one output field. If you are
          unsure where a word belongs, put it in line1.
        - country_code is the ISO 3166-1 alpha-2 code, uppercase. Weigh the evidence in the address
          in this order, strongest first:
            1. the country is named outright, including as an adjective or a constituent country
               ("England" is GB);
            2. a city, region, or district that identifies one country ("Dubai", "Al Barsha");
            3. the language, script, or naming conventions of the street and place names
               ("Friedrichstrasse" is German, Hebrew script with an Israeli city is IL,
               "Rue"/"Bd." with a Monegasque district is MC);
            4. the postcode, and only as the last and weakest signal. Postcode formats collide
               across countries — five digits are German, French, Spanish, American and more — so a
               postcode alone almost never settles the question. It may confirm what the stronger
               evidence already says, and should not outvote it.
          Leave country_code empty when the evidence is genuinely ambiguous. An address with
          nothing but a street number and a five-digit code decides nothing.
        - A UK postcode is written as outward code, a space, then the inward code (three
          characters), e.g. "B60 3DJ".
        - city is the settlement. It is never a country name unless the settlement really is named
          after one (Luxembourg, Monaco, Singapore, Panama).
        - line1 is the street and building. line2 is a secondary line only when there is one.

        Return the fields empty rather than guessing.
        PROMPT;

    /**
     * Placeholders: {address} the input, {draft} the rule-based result as JSON, {issues} what the
     * inspector found wrong with it.
     */
    public const DEFAULT_USER_PROMPT = <<<'PROMPT'
        Address:
        {address}

        A rule-based parser produced:
        {draft}

        Problems detected: {issues}

        Return the corrected split.
        PROMPT;

    /** Result field => answer key, when the configuration does not say otherwise. */
    private const DEFAULT_FIELD_MAP = [
        'line1' => 'line1',
        'line2' => 'line2',
        'city' => 'city',
        'postcode' => 'postcode',
        'country_code' => 'country_code',
    ];

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 2_592_000,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $name = 'llm',
        private readonly string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
        private readonly string $userPrompt = self::DEFAULT_USER_PROMPT,
        private readonly bool $rejectInventedText = true,
        /** @var array<string, mixed>|null the JSON Schema sent to the model; null uses the default */
        private readonly ?array $schema = null,
        /**
         * Result field => key in the model's answer. Override when a custom schema names its
         * fields differently, so a new schema needs no code change.
         *
         * @var array<string, string>|null
         */
        private readonly ?array $fieldMap = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress
    {
        $key = $this->cacheKey($address, $spaceInPostCode);
        $cached = $this->cache?->get($key);

        if (is_array($cached)) {
            return $this->toResult($cached, $draft, $spaceInPostCode);
        }

        $data = $this->client->complete(
            $this->systemPrompt,
            $this->renderUserPrompt($address, $draft, $issues),
            $this->schema ?? self::schema(),
        );

        $result = $this->toResult($data, $draft, $spaceInPostCode);

        if ($this->rejectInventedText && !$this->onlyUsesInputWords($address, $result)) {
            $this->logger->warning('llm refinement rejected: it introduced text that is not in the input', [
                'address' => $address,
                'result' => $result->toLegacyArray(),
            ]);

            return $draft;
        }

        $this->cache?->set($key, $data, $this->cacheTtl);

        return $result;
    }

    /**
     * @param list<Issue> $issues
     */
    private function renderUserPrompt(string $address, ParsedAddress $draft, array $issues): string
    {
        $problems = implode(', ', array_map(static fn (Issue $i): string => $i->value, $issues));

        return str_replace(
            ['{address}', '{draft}', '{issues}'],
            [
                trim(explode('|', $address)[0]),
                json_encode($draft->toLegacyArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}',
                '' === $problems ? 'none' : $problems,
            ],
            $this->userPrompt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'line1' => $string,
                'line2' => $string,
                'city' => $string,
                'postcode' => $string,
                'country_code' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2, or empty'],
            ],
            'required' => ['line1', 'line2', 'city', 'postcode', 'country_code'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toResult(array $data, ParsedAddress $draft, bool $spaceInPostCode): ParsedAddress
    {
        $map = $this->fieldMap ?? self::DEFAULT_FIELD_MAP;
        $read = static fn (string $field): string => trim((string) ($data[$map[$field] ?? $field] ?? ''));

        $code = mb_strtoupper($read('country_code'));
        $country = '' === $code ? null : $this->countries->resolve($code);
        $postcode = $read('postcode');

        if (!$spaceInPostCode) {
            $postcode = str_replace([' ', '-'], '', $postcode);
        }

        return $draft->with([
            'line1' => $read('line1'),
            'line2' => $read('line2'),
            'city' => $read('city'),
            'postcode' => $postcode,
            'country' => $country?->name ?? '',
            'country_code' => $country?->alpha2 ?? '',
            'trace' => $draft->trace + ['refined_by' => $this->client->describe()],
            'source' => $this->name,
        ]);
    }

    /**
     * Guards against invention: every word of the output must come from the input. The country
     * name is exempt because it is looked up from the code, not copied.
     */
    private function onlyUsesInputWords(string $address, ParsedAddress $result): bool
    {
        $input = self::fold(explode('|', $address)[0]);

        foreach ([$result->line1, $result->line2, $result->city, $result->postcode] as $field) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $field, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $folded = self::fold($word);

                if ('' !== $folded && !str_contains($input, $folded)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function cacheKey(string $address, bool $spaceInPostCode): string
    {
        // The prompts are part of the key: editing a prompt must not return answers produced by
        // the previous one.
        return 'address.llm.' . hash('xxh128', implode('|', [
            $this->client->describe(),
            (int) $spaceInPostCode,
            hash('xxh128', $this->systemPrompt . $this->userPrompt . json_encode($this->schema ?? [])),
            $address,
        ]));
    }

    private static function fold(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(false === $ascii ? $value : $ascii));
    }
}
