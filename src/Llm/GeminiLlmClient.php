<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Llm;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Google Gemini, via the Generative Language REST API.
 *
 * Structured output is native here: `responseMimeType: application/json` plus a `responseSchema`.
 * The schema dialect is a subset of OpenAPI 3.0, not full JSON Schema — `additionalProperties` and
 * `$schema` are rejected, so they are stripped, and `propertyOrdering` is added because Gemini
 * honours field order and a stable order keeps responses (and prompt caching) predictable.
 */
final class GeminiLlmClient extends HttpJsonLlmClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Keywords the OpenAPI-subset schema dialect does not accept. */
    private const UNSUPPORTED_KEYWORDS = ['additionalProperties', '$schema', 'exclusiveMinimum', 'exclusiveMaximum'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-flash-latest',
        private readonly int $maxTokens = 2048,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        int $maxAttempts = 3,
        float $retryBaseDelay = 0.5,
    ) {
        parent::__construct($http, $requests, $streams, $maxAttempts, $retryBaseDelay);
    }

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $schema = self::withoutKeywords($jsonSchema, self::UNSUPPORTED_KEYWORDS);

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $schema['propertyOrdering'] = array_keys($schema['properties']);
        }

        $response = $this->post(
            sprintf(self::ENDPOINT, $this->model),
            [
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
                    'maxOutputTokens' => $this->maxTokens,
                ],
            ],
            ['X-goog-api-key' => $this->apiKey],
        );

        $candidate = $response['candidates'][0] ?? null;

        if (!is_array($candidate)) {
            $reason = $response['promptFeedback']['blockReason'] ?? 'no candidate returned';
            throw new LlmException(sprintf('%s produced no answer: %s', $this->describe(), (string) $reason));
        }

        // A truncated answer is invalid JSON, and the reason for it is worth naming.
        $finish = (string) ($candidate['finishReason'] ?? '');

        if ('' !== $finish && !in_array($finish, ['STOP', 'FINISH_REASON_UNSPECIFIED'], true)) {
            throw new LlmException(sprintf('%s stopped early: %s', $this->describe(), $finish));
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        if ('' === trim($text)) {
            throw new LlmException(sprintf('%s returned an empty answer', $this->describe()));
        }

        return $this->decodeJsonAnswer($text);
    }

    public function describe(): string
    {
        return 'gemini:' . $this->model;
    }
}
