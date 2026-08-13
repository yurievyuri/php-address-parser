<?php

declare(strict_types=1);

namespace Address\Parser\Refiner;

use Address\Parser\Country\CountryResolverInterface;
use Address\Parser\Country\Iso3166CountryResolver;
use Address\Parser\Llm\LlmClientInterface;
use Address\Parser\ParsedAddress;
use Address\Parser\Quality\Issue;
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
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You split postal addresses into structured components. The addresses come from a CRM, are
        written by people in many countries, and are frequently malformed.

        Rules:
        - Use only the words present in the input. Never add a postcode, city, or country that is
          not written there. Correcting obvious typos in a postcode is allowed; supplying a missing
          one is not.
        - Every meaningful word of the input must appear in exactly one output field. If you are
          unsure where a word belongs, put it in line1.
        - country_code is the ISO 3166-1 alpha-2 code, uppercase, and only when the input names the
          country or carries a postcode whose format identifies it unambiguously. Empty otherwise.
        - A UK postcode is written as outward code, a space, then the inward code (three
          characters), e.g. "B60 3DJ".
        - city is the settlement. It is never a country name unless the settlement really is named
          after one (Luxembourg, Monaco, Singapore, Panama).
        - line1 is the street and building. line2 is a secondary line only when there is one.

        Return the fields empty rather than guessing.
        PROMPT;

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 2_592_000,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $name = 'llm',
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
            self::SYSTEM_PROMPT,
            $this->userPrompt($address, $draft, $issues),
            self::schema(),
        );

        $result = $this->toResult($data, $draft, $spaceInPostCode);

        if (!$this->onlyUsesInputWords($address, $result)) {
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
    private function userPrompt(string $address, ParsedAddress $draft, array $issues): string
    {
        $problems = implode(', ', array_map(static fn (Issue $i): string => $i->value, $issues));

        return sprintf(
            "Address:\n%s\n\nA rule-based parser produced:\n%s\n\nProblems detected: %s\n\nReturn the corrected split.",
            trim(explode('|', $address)[0]),
            json_encode($draft->toLegacyArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}',
            '' === $problems ? 'none' : $problems,
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
        $code = mb_strtoupper(trim((string) ($data['country_code'] ?? '')));
        $country = '' === $code ? null : $this->countries->resolve($code);
        $postcode = trim((string) ($data['postcode'] ?? ''));

        if (!$spaceInPostCode) {
            $postcode = str_replace([' ', '-'], '', $postcode);
        }

        return $draft->with([
            'line1' => trim((string) ($data['line1'] ?? '')),
            'line2' => trim((string) ($data['line2'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
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
        return 'address.llm.' . hash('xxh128', $this->client->describe() . '|' . (int) $spaceInPostCode . '|' . $address);
    }

    private static function fold(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(false === $ascii ? $value : $ascii));
    }
}
