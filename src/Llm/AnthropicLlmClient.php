<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Llm;

/**
 * Claude, via the official PHP SDK — either the Anthropic API or the Bedrock endpoint.
 *
 * The SDK is an optional dependency: install `anthropic-ai/sdk` only if this provider is used.
 * Structured output is requested through `outputConfig.format`, so the response is schema-valid
 * rather than "JSON if we are lucky".
 */
final class AnthropicLlmClient implements LlmClientInterface
{
    /**
     * @param object|null $client an Anthropic\Client or Anthropic\Bedrock\MantleClient; built from
     *                            $apiKey / $awsRegion when omitted
     */
    public function __construct(
        private readonly string $model = 'claude-opus-5',
        private readonly int $maxTokens = 2048,
        private readonly ?string $apiKey = null,
        private readonly ?string $awsRegion = null,
        private ?object $client = null,
    ) {
    }

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $client = $this->client ??= $this->createClient();

        try {
            $message = $client->messages->create(
                model: $this->modelId(),
                maxTokens: $this->maxTokens,
                system: [['type' => 'text', 'text' => $systemPrompt, 'cacheControl' => ['type' => 'ephemeral']]],
                messages: [['role' => 'user', 'content' => $userPrompt]],
                outputConfig: [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $jsonSchema,
                    ],
                ],
            );
        } catch (\Throwable $e) {
            throw new LlmException(sprintf('%s: %s', $this->describe(), $e->getMessage()), 0, $e);
        }

        // Safety classifiers can decline a request: that arrives as a normal response with
        // stopReason "refusal" and no content, not as an exception.
        if ('refusal' === ($message->stopReason ?? null)) {
            throw new LlmException(sprintf('%s declined the request', $this->describe()));
        }

        foreach ($message->content as $block) {
            if ('text' !== ($block->type ?? null)) {
                continue;
            }

            $decoded = json_decode((string) $block->text, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            throw new LlmException(sprintf('%s returned text that is not JSON', $this->describe()));
        }

        throw new LlmException(sprintf('%s returned no text block', $this->describe()));
    }

    public function describe(): string
    {
        return 'anthropic:' . $this->modelId();
    }

    /**
     * Bedrock model ids carry an `anthropic.` prefix; the direct API ids do not. Adding it here
     * means one model name works in both configurations.
     */
    private function modelId(): string
    {
        if (null !== $this->awsRegion && !str_starts_with($this->model, 'anthropic.')) {
            return 'anthropic.' . $this->model;
        }

        return $this->model;
    }

    private function createClient(): object
    {
        if (null !== $this->awsRegion) {
            if (!class_exists(\Anthropic\Bedrock\MantleClient::class)) {
                throw new LlmException('anthropic-ai/sdk is required for the Bedrock client — composer require anthropic-ai/sdk');
            }

            return new \Anthropic\Bedrock\MantleClient(awsRegion: $this->awsRegion);
        }

        if (!class_exists(\Anthropic\Client::class)) {
            throw new LlmException('anthropic-ai/sdk is required — composer require anthropic-ai/sdk');
        }

        $apiKey = $this->apiKey ?? getenv('ANTHROPIC_API_KEY');

        if (false === $apiKey || '' === $apiKey) {
            throw new LlmException('no API key: pass one to the provider or set ANTHROPIC_API_KEY');
        }

        return new \Anthropic\Client(apiKey: $apiKey);
    }
}
