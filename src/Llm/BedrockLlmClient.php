<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Llm;

/**
 * Claude on Amazon Bedrock, through the AWS SDK that a project on AWS already has.
 *
 * Deliberately not the Anthropic SDK: `aws/aws-sdk-php` is usually present already, credentials
 * come from the instance role, and traffic stays inside the account — which is what makes this the
 * right client for an internal deployment.
 *
 * Structured output is requested as a forced tool call. Bedrock's Anthropic API supports
 * `output_config`, but tool-forcing is the shape supported by every Claude version there, so the
 * schema is enforced the same way regardless of which model the configuration names.
 */
final class BedrockLlmClient implements LlmClientInterface
{
    private const ANTHROPIC_VERSION = 'bedrock-2023-05-31';

    private ?object $client = null;

    /**
     * Token usage of the most recent call, or null before the first one. Exposed because choosing
     * a model and an effort level is a cost decision, and cost is tokens.
     *
     * @var array{input: int, output: int}|null
     */
    public ?array $lastUsage = null;

    /**
     * @param string|null $effort  reasoning effort (low|medium|high|xhigh|max), or null to omit the
     *                             parameter — models that do not support it reject the request
     */
    public function __construct(
        private readonly string $model = 'anthropic.claude-haiku-4-5-20251001-v1:0',
        private readonly string $region = 'eu-west-1',
        private readonly int $maxTokens = 2048,
        private readonly ?string $effort = null,
        ?object $client = null,
    ) {
        $this->client = $client;
    }

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $body = [
            'anthropic_version' => self::ANTHROPIC_VERSION,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => [['role' => 'user', 'content' => $userPrompt]],
            'tools' => [[
                'name' => 'parsed_address',
                'description' => 'Return the address split into its components.',
                'input_schema' => $jsonSchema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'parsed_address'],
        ];

        if (null !== $this->effort) {
            $body['output_config'] = ['effort' => $this->effort];
        }

        $response = $this->invoke($body);

        // A forced tool call comes back as a tool_use block whose input is the schema-valid answer.
        foreach ($response['content'] ?? [] as $block) {
            if ('tool_use' === ($block['type'] ?? null) && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        if ('refusal' === ($response['stop_reason'] ?? null)) {
            throw new LlmException(sprintf('%s declined the request', $this->describe()));
        }

        throw new LlmException(sprintf('%s returned no structured answer', $this->describe()));
    }

    public function describe(): string
    {
        return 'bedrock:' . $this->model . (null === $this->effort ? '' : ':' . $this->effort);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function invoke(array $body): array
    {
        $client = $this->client ??= $this->createClient();

        try {
            $result = $client->invokeModel([
                'modelId' => $this->model,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE) ?: '{}',
            ]);
        } catch (\Throwable $e) {
            // Not every model accepts `effort`, and the ones that do not reject the whole request.
            // Retrying without it beats keeping a list of model names in code that goes stale.
            if (null !== $this->effort && $this->mentionsEffort($e->getMessage())) {
                unset($body['output_config']);

                return $this->invoke($body);
            }

            throw new LlmException(sprintf('%s: %s', $this->describe(), $e->getMessage()), 0, $e);
        }

        $decoded = json_decode((string) $result['body'], true);

        if (!is_array($decoded)) {
            throw new LlmException(sprintf('%s returned a body that is not JSON', $this->describe()));
        }

        $this->lastUsage = [
            'input' => (int) ($decoded['usage']['input_tokens'] ?? 0),
            'output' => (int) ($decoded['usage']['output_tokens'] ?? 0),
        ];

        return $decoded;
    }

    private function mentionsEffort(string $message): bool
    {
        return str_contains($message, 'effort') || str_contains($message, 'output_config');
    }

    private function createClient(): object
    {
        if (!class_exists(\Aws\BedrockRuntime\BedrockRuntimeClient::class)) {
            throw new LlmException('aws/aws-sdk-php is required for the Bedrock client');
        }

        return new \Aws\BedrockRuntime\BedrockRuntimeClient([
            'region' => $this->region,
            'version' => 'latest',
        ]);
    }
}
