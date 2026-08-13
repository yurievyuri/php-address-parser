<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Llm;

/**
 * The seam every LLM provider plugs into.
 *
 * One method, one job: given instructions and a JSON schema, return data that satisfies the
 * schema. Everything provider-specific — SDK, transport, authentication, model naming, whether the
 * model runs on a vendor API or a self-hosted endpoint — lives behind it. A host application adds
 * a provider by writing a class that implements this and registering it by name.
 */
interface LlmClientInterface
{
    /**
     * @param array<string, mixed> $jsonSchema JSON Schema the response must conform to
     *
     * @return array<string, mixed> the decoded response
     *
     * @throws LlmException when the provider is unreachable or returns something unusable
     */
    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema): array;

    /**
     * Identifier for logs and cache keys — provider and model, e.g. "anthropic:claude-haiku-4-5".
     */
    public function describe(): string;
}
