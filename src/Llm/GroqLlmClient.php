<?php

declare(strict_types=1);

namespace Address\Parser\Llm;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Groq Cloud — open-weight models (Llama, Qwen, and others) on their own inference hardware,
 * behind an OpenAI-compatible chat-completions API.
 *
 * The same class therefore serves any OpenAI-compatible endpoint: pass a different `baseUrl` and
 * an API key, and a self-hosted vLLM or llama.cpp server works unchanged. Structured output uses
 * `response_format: json_schema` with `strict: true`, which is why the schema is sent as-is.
 */
final class GroqLlmClient extends HttpJsonLlmClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'openai/gpt-oss-120b',
        private readonly int $maxTokens = 2048,
        private readonly string $baseUrl = 'https://api.groq.com/openai/v1',
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        parent::__construct($http, $requests, $streams);
    }

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $response = $this->post(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            [
                'model' => $this->model,
                'max_completion_tokens' => $this->maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'parsed_address',
                        'strict' => true,
                        'schema' => $jsonSchema,
                    ],
                ],
            ],
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $choice = $response['choices'][0] ?? null;

        if (!is_array($choice)) {
            throw new LlmException(sprintf('%s returned no choice', $this->describe()));
        }

        // "length" means the answer was cut off, so whatever JSON arrived is incomplete.
        if ('length' === ($choice['finish_reason'] ?? null)) {
            throw new LlmException(sprintf('%s hit the token limit before finishing', $this->describe()));
        }

        $text = (string) ($choice['message']['content'] ?? '');

        if ('' === trim($text)) {
            throw new LlmException(sprintf('%s returned an empty answer', $this->describe()));
        }

        return $this->decodeJsonAnswer($text);
    }

    public function describe(): string
    {
        return 'groq:' . $this->model;
    }
}
