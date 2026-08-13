<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\Llm\BedrockConverseClient;
use Codelot\AddressParser\Llm\LlmException;
use Codelot\AddressParser\Refiner\LlmRefiner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Converse is one request shape for every vendor on Bedrock, which is what makes switching model a
 * configuration change. These pin the shape it sends, and the way it degrades for models that
 * support less than a forced tool call — verified against the real API for Claude, Nova, Mistral,
 * Qwen and GPT-OSS.
 */
#[CoversClass(BedrockConverseClient::class)]
final class BedrockConverseClientTest extends TestCase
{
    public function testItSendsTheConverseShapeAndReadsTheToolUseBlock(): void
    {
        $aws = $this->aws([$this->toolUseResponse(['line1' => '15 Davies Street', 'city' => 'London'])]);

        $client = new BedrockConverseClient(
            modelId: 'eu.anthropic.claude-haiku-4-5-20251001-v1:0',
            maxTokens: 1024,
            client: $aws,
        );

        $answer = $client->complete('system text', 'user text', LlmRefiner::schema());

        self::assertSame(['line1' => '15 Davies Street', 'city' => 'London'], $answer);

        $request = $aws->requests[0];
        self::assertSame('eu.anthropic.claude-haiku-4-5-20251001-v1:0', $request['modelId']);
        self::assertSame([['text' => 'system text']], $request['system']);
        self::assertSame('user text', $request['messages'][0]['content'][0]['text']);
        self::assertSame(1024, $request['inferenceConfig']['maxTokens']);
        self::assertSame('parsed_address', $request['toolConfig']['toolChoice']['tool']['name']);
        self::assertSame(
            LlmRefiner::schema(),
            $request['toolConfig']['tools'][0]['toolSpec']['inputSchema']['json'],
            'the schema goes to the model as-is',
        );
    }

    public function testSwitchingVendorIsOnlyTheModelId(): void
    {
        foreach ([
            'eu.anthropic.claude-haiku-4-5-20251001-v1:0',
            'eu.amazon.nova-lite-v1:0',
            'mistral.ministral-3-3b-instruct',
            'qwen.qwen3-32b-v1:0',
            'openai.gpt-oss-120b-1:0',
        ] as $modelId) {
            $aws = $this->aws([$this->toolUseResponse(['line1' => 'Somewhere'])]);

            $answer = (new BedrockConverseClient(modelId: $modelId, client: $aws))
                ->complete('s', 'u', LlmRefiner::schema());

            self::assertSame(['line1' => 'Somewhere'], $answer, $modelId);
            self::assertSame($modelId, $aws->requests[0]['modelId']);
            self::assertArrayHasKey('toolConfig', $aws->requests[0], "{$modelId}: the request shape does not change");
        }
    }

    public function testUsageIsReportedForCostAccounting(): void
    {
        $aws = $this->aws([$this->toolUseResponse(['line1' => 'x'], inputTokens: 706, outputTokens: 76)]);
        $client = new BedrockConverseClient(client: $aws);

        $client->complete('s', 'u', LlmRefiner::schema());

        self::assertSame(['input' => 706, 'output' => 76], $client->lastUsage);
    }

    public function testVendorSpecificFieldsArePassedThroughUntouched(): void
    {
        $aws = $this->aws([$this->toolUseResponse(['line1' => 'x'])]);

        (new BedrockConverseClient(
            effort: 'low',
            additionalModelRequestFields: ['reasoning_config' => ['type' => 'enabled']],
            client: $aws,
        ))->complete('s', 'u', LlmRefiner::schema());

        $fields = $aws->requests[0]['additionalModelRequestFields'];
        self::assertSame('low', $fields['output_config']['effort']);
        self::assertSame(['type' => 'enabled'], $fields['reasoning_config']);
    }

    /**
     * Haiku answers "Extra inputs are not permitted" to output_config.effort while Sonnet accepts
     * it, so the client degrades instead of failing — a list of model names in code would go stale.
     */
    public function testAModelRejectingTheVendorFieldsIsRetriedWithoutThem(): void
    {
        $aws = $this->aws([
            new \RuntimeException('The model returned the following errors: output_config.effort: Extra inputs are not permitted'),
            $this->toolUseResponse(['line1' => 'recovered']),
        ]);

        $answer = (new BedrockConverseClient(effort: 'low', client: $aws))->complete('s', 'u', LlmRefiner::schema());

        self::assertSame(['line1' => 'recovered'], $answer);
        self::assertCount(2, $aws->requests);
        self::assertArrayHasKey('additionalModelRequestFields', $aws->requests[0]);
        self::assertArrayNotHasKey('additionalModelRequestFields', $aws->requests[1], 'the retry drops them');
    }

    /**
     * Vendors word the same refusal differently — Anthropic "Extra inputs are not permitted",
     * Nova "extraneous key [x] is not permitted" — so the client matches on the field name it
     * sent rather than on a phrase.
     */
    public function testAVendorWordingTheRefusalDifferentlyStillDegrades(): void
    {
        $aws = $this->aws([
            new \RuntimeException('The model returned the following errors: Malformed input request: #: extraneous key [reasoning_config] is not permitted, please reformat your input and try again.'),
            $this->toolUseResponse(['line1' => 'recovered']),
        ]);

        $answer = (new BedrockConverseClient(
            additionalModelRequestFields: ['reasoning_config' => ['type' => 'enabled']],
            client: $aws,
        ))->complete('s', 'u', LlmRefiner::schema());

        self::assertSame(['line1' => 'recovered'], $answer);
        self::assertArrayNotHasKey('additionalModelRequestFields', $aws->requests[1]);
    }

    /**
     * The AWS SDK buries the useful sentence inside a paragraph of request context; the client
     * reads getAwsErrorMessage() when the exception offers it.
     */
    public function testTheAwsErrorMessageIsPreferredOverTheWrappedOne(): void
    {
        $awsException = new class('Error executing "Converse" on "https://bedrock-runtime…"; AWS HTTP error: …') extends \RuntimeException {
            public function getAwsErrorMessage(): string
            {
                return 'The model returned the following errors: output_config.effort: Extra inputs are not permitted';
            }
        };

        $aws = $this->aws([$awsException, $this->toolUseResponse(['line1' => 'recovered'])]);

        $answer = (new BedrockConverseClient(effort: 'low', client: $aws))->complete('s', 'u', LlmRefiner::schema());

        self::assertSame(['line1' => 'recovered'], $answer, 'the refusal was only recognisable in the AWS message');
    }

    public function testAModelThatCannotForceAToolIsRetriedWithoutToolChoice(): void
    {
        $aws = $this->aws([
            new \RuntimeException('This model does not support tool choice'),
            $this->toolUseResponse(['line1' => 'recovered']),
        ]);

        $answer = (new BedrockConverseClient(client: $aws))->complete('s', 'u', LlmRefiner::schema());

        self::assertSame(['line1' => 'recovered'], $answer);
        self::assertArrayHasKey('toolChoice', $aws->requests[0]['toolConfig']);
        self::assertArrayNotHasKey('toolChoice', $aws->requests[1]['toolConfig']);
        self::assertArrayHasKey('tools', $aws->requests[1]['toolConfig'], 'the tool itself is still offered');
    }

    public function testAModelWithoutToolsFallsBackToJsonInThePrompt(): void
    {
        $aws = $this->aws([
            new \RuntimeException("This model doesn't support tool use"),
            $this->textResponse('{"line1":"from plain text","city":"London"}'),
        ]);

        $answer = (new BedrockConverseClient(client: $aws))->complete('s', 'user text', LlmRefiner::schema());

        self::assertSame(['line1' => 'from plain text', 'city' => 'London'], $answer);
        self::assertArrayNotHasKey('toolConfig', $aws->requests[1]);
        self::assertStringContainsString(
            'Reply with JSON only',
            $aws->requests[1]['messages'][0]['content'][0]['text'],
            'the schema has to reach the model somehow',
        );
    }

    public function testFencedJsonFromASloppyModelIsStillRead(): void
    {
        $aws = $this->aws([$this->textResponse("```json\n{\"line1\":\"fenced\"}\n```")]);

        self::assertSame(
            ['line1' => 'fenced'],
            (new BedrockConverseClient(client: $aws))->complete('s', 'u', LlmRefiner::schema()),
        );
    }

    public function testATruncatedAnswerIsAnErrorRatherThanHalfAnAddress(): void
    {
        $aws = $this->aws([$this->textResponse('{"line1":"cut off he', stopReason: 'max_tokens')]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/token limit/');

        (new BedrockConverseClient(client: $aws))->complete('s', 'u', LlmRefiner::schema());
    }

    public function testABlockedAnswerSaysSo(): void
    {
        $aws = $this->aws([$this->textResponse('', stopReason: 'guardrail_intervened')]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/blocked \(guardrail_intervened\)/');

        (new BedrockConverseClient(client: $aws))->complete('s', 'u', LlmRefiner::schema());
    }

    public function testAnUnrelatedFailureIsNotSwallowedByTheFallbacks(): void
    {
        $aws = $this->aws([new \RuntimeException('The security token included in the request is invalid')]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/security token/');

        (new BedrockConverseClient(client: $aws))->complete('s', 'u', LlmRefiner::schema());
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function toolUseResponse(array $input, int $inputTokens = 100, int $outputTokens = 20): array
    {
        return [
            'stopReason' => 'tool_use',
            'output' => ['message' => ['content' => [['toolUse' => ['name' => 'parsed_address', 'input' => $input]]]]],
            'usage' => ['inputTokens' => $inputTokens, 'outputTokens' => $outputTokens],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'stopReason' => $stopReason,
            'output' => ['message' => ['content' => [['text' => $text]]]],
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
        ];
    }

    /**
     * A stand-in for BedrockRuntimeClient: records what it was sent, and replays the queued
     * answers — an exception in the queue is thrown rather than returned.
     *
     * @param list<array<string, mixed>|\Throwable> $answers
     */
    private function aws(array $answers): object
    {
        return new class($answers) {
            /** @var list<array<string, mixed>> */
            public array $requests = [];

            /** @param list<array<string, mixed>|\Throwable> $answers */
            public function __construct(private array $answers)
            {
            }

            /**
             * @param array<string, mixed> $request
             *
             * @return array<string, mixed>
             */
            public function converse(array $request): array
            {
                $this->requests[] = $request;
                $answer = array_shift($this->answers);

                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer ?? [];
            }
        };
    }
}
