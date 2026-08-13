<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Llm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Bedrock through the Converse API — one request shape for every vendor on the platform.
 *
 * This is the reason to prefer it over InvokeModel: `messages`, `system`, `inferenceConfig` and
 * `toolConfig` mean the same thing whether the model behind `modelId` is Claude, Nova, Mistral,
 * Qwen or an OSS model, so switching vendor is a string in configuration rather than a new adapter.
 * Verified against all five: each returns the same `toolUse` block for the same request.
 *
 * Vendor-specific parameters do not disappear, they move to `additionalModelRequestFields` — and
 * that field is configuration here, not code, because what belongs in it differs per model. Claude
 * Haiku, for instance, rejects `output_config.effort` outright with "Extra inputs are not
 * permitted", while Sonnet accepts it.
 *
 * Structured output uses a forced tool call, with two fallbacks for models that support less than
 * that: first without `toolChoice`, then as plain JSON asked for in the prompt. A model that cannot
 * do tools at all is still usable rather than unavailable.
 */
final class BedrockConverseClient implements LlmClientInterface
{
    private const TOOL_NAME = 'parsed_address';

    private ?object $client;

    /**
     * Token usage of the most recent call. Choosing a model is a cost decision, and cost is tokens.
     *
     * @var array{input: int, output: int}|null
     */
    public ?array $lastUsage = null;

    /**
     * @param array<string, mixed> $additionalModelRequestFields vendor-specific parameters, passed through untouched
     */
    public function __construct(
        private readonly string $modelId = 'eu.anthropic.claude-haiku-4-5-20251001-v1:0',
        private readonly string $region = 'eu-west-1',
        private readonly int $maxTokens = 2048,
        private readonly ?string $effort = null,
        private readonly array $additionalModelRequestFields = [],
        private readonly LoggerInterface $logger = new NullLogger(),
        ?object $client = null,
    ) {
        $this->client = $client;
    }

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $request = [
            'modelId' => $this->modelId,
            'system' => [['text' => $systemPrompt]],
            'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
            'inferenceConfig' => ['maxTokens' => $this->maxTokens],
            'toolConfig' => [
                'tools' => [[
                    'toolSpec' => [
                        'name' => self::TOOL_NAME,
                        'description' => 'Return the address split into its components.',
                        'inputSchema' => ['json' => $jsonSchema],
                    ],
                ]],
                'toolChoice' => ['tool' => ['name' => self::TOOL_NAME]],
            ],
        ];

        $additional = $this->additionalModelRequestFields;

        if (null !== $this->effort) {
            // Where effort lives is Anthropic's shape; other vendors take their own fields through
            // the same channel, which is why it is configurable.
            $additional['output_config'] = ['effort' => $this->effort] + ($additional['output_config'] ?? []);
        }

        if ([] !== $additional) {
            $request['additionalModelRequestFields'] = $additional;
        }

        $response = $this->send($request);

        return $this->extract($response);
    }

    public function describe(): string
    {
        return 'bedrock-converse:' . $this->modelId . (null === $this->effort ? '' : ':' . $this->effort);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function send(array $request): array
    {
        $client = $this->client ??= $this->createClient();

        try {
            $result = $client->converse($request);
        } catch (\Throwable $e) {
            $message = $this->messageOf($e);

            // Degrade one capability at a time rather than failing: what a model supports is not
            // knowable from its name, and a hard-coded list of exceptions goes stale.
            if (isset($request['additionalModelRequestFields'])
                && $this->rejectsExtraFields($message, $request['additionalModelRequestFields'])) {
                $this->logger->info('bedrock: model rejected the vendor-specific fields, retrying without them', [
                    'model' => $this->modelId,
                    'fields' => array_keys($request['additionalModelRequestFields']),
                ]);
                unset($request['additionalModelRequestFields']);

                return $this->send($request);
            }

            if (isset($request['toolConfig']['toolChoice']) && $this->rejectsToolChoice($message)) {
                $this->logger->info('bedrock: model does not support forcing a tool, retrying without it', [
                    'model' => $this->modelId,
                ]);
                unset($request['toolConfig']['toolChoice']);

                return $this->send($request);
            }

            if (isset($request['toolConfig']) && $this->rejectsTools($message)) {
                $this->logger->info('bedrock: model does not support tools, asking for JSON in the prompt', [
                    'model' => $this->modelId,
                ]);

                return $this->send($this->asPlainJsonRequest($request));
            }

            throw new LlmException(sprintf('%s: %s', $this->describe(), $message), 0, $e);
        }

        $this->lastUsage = [
            'input' => (int) ($result['usage']['inputTokens'] ?? 0),
            'output' => (int) ($result['usage']['outputTokens'] ?? 0),
        ];

        // The SDK hands back an Aws\Result (ArrayAccess); a test double hands back a plain array.
        if (is_object($result) && method_exists($result, 'toArray')) {
            /** @var array<string, mixed> */
            return $result->toArray();
        }

        /** @var array<string, mixed> */
        return is_array($result) ? $result : [];
    }

    /**
     * Rewrites the request for a model without tool support: the schema goes into the prompt and
     * the answer comes back as text.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function asPlainJsonRequest(array $request): array
    {
        $schema = $request['toolConfig']['tools'][0]['toolSpec']['inputSchema']['json'] ?? [];
        unset($request['toolConfig']);

        $instruction = sprintf(
            "\n\nReply with JSON only — no prose, no code fences — matching this schema:\n%s",
            json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        $request['messages'][0]['content'][0]['text'] .= $instruction;

        return $request;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function extract(array $response): array
    {
        $stopReason = (string) ($response['stopReason'] ?? '');

        if ('max_tokens' === $stopReason) {
            throw new LlmException(sprintf('%s hit the token limit before finishing', $this->describe()));
        }

        if (in_array($stopReason, ['content_filtered', 'guardrail_intervened'], true)) {
            throw new LlmException(sprintf('%s: the answer was blocked (%s)', $this->describe(), $stopReason));
        }

        $blocks = $response['output']['message']['content'] ?? [];
        $text = '';

        foreach ($blocks as $block) {
            if (isset($block['toolUse']['input']) && is_array($block['toolUse']['input'])) {
                return $block['toolUse']['input'];
            }

            $text .= (string) ($block['text'] ?? '');
        }

        if ('' !== trim($text)) {
            return $this->decodeJson($text);
        }

        throw new LlmException(sprintf('%s returned no answer', $this->describe()));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $text): array
    {
        $text = trim($text);

        // Some models fence their JSON even when told not to.
        if (str_starts_with($text, '```')) {
            $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text));
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new LlmException(sprintf(
                '%s returned text that is not JSON: %s',
                $this->describe(),
                mb_substr($text, 0, 200),
            ));
        }

        return $decoded;
    }

    /**
     * True when the model refused because of a field we added rather than for any other reason.
     *
     * Vendors word this differently — Anthropic says "output_config.effort: Extra inputs are not
     * permitted", Nova says "extraneous key [nonsense_field] is not permitted" — so the reliable
     * test is whether the message names a field we actually sent, not whether it matches a phrase.
     *
     * @param array<string, mixed> $fields
     */
    private function rejectsExtraFields(string $message, array $fields): bool
    {
        foreach ($this->fieldNames($fields) as $name) {
            if ('' !== $name && str_contains($message, $name)) {
                return true;
            }
        }

        return str_contains($message, 'additionalModelRequestFields');
    }

    /**
     * Field names one level deep: a vendor may name either the outer key or the inner one.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    private function fieldNames(array $fields): array
    {
        $names = [];

        foreach ($fields as $key => $value) {
            $names[] = (string) $key;

            if (is_array($value)) {
                foreach (array_keys($value) as $inner) {
                    $names[] = (string) $inner;
                }
            }
        }

        return $names;
    }

    /**
     * The AWS SDK puts the useful part in getAwsErrorMessage(); getMessage() wraps it in the
     * request URL and a stack of context. Prefer the former when it is there.
     */
    private function messageOf(\Throwable $e): string
    {
        if (method_exists($e, 'getAwsErrorMessage')) {
            $aws = $e->getAwsErrorMessage();

            if (is_string($aws) && '' !== $aws) {
                return $aws;
            }
        }

        return $e->getMessage();
    }

    private function rejectsToolChoice(string $message): bool
    {
        return str_contains($message, 'toolChoice')
            || str_contains($message, 'tool choice')
            || str_contains($message, "doesn't support tool choice")
            || str_contains($message, 'does not support tool choice');
    }

    private function rejectsTools(string $message): bool
    {
        return str_contains($message, 'toolConfig')
            || str_contains($message, "doesn't support tool use")
            || str_contains($message, 'does not support tool use');
    }

    private function createClient(): object
    {
        if (!class_exists(\Aws\BedrockRuntime\BedrockRuntimeClient::class)) {
            throw new LlmException(
                'aws/aws-sdk-php is required for the Bedrock client — composer require aws/aws-sdk-php',
            );
        }

        return new \Aws\BedrockRuntime\BedrockRuntimeClient([
            'region' => $this->region,
            'version' => 'latest',
        ]);
    }
}
