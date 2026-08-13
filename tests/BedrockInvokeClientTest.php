<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\Llm\BedrockLlmClient;
use Codelot\AddressParser\Llm\LlmException;
use Codelot\AddressParser\Refiner\LlmRefiner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The older InvokeModel path, kept for a model the Converse API does not cover. Same guarantees as
 * the Converse client, different wire format.
 */
#[CoversClass(BedrockLlmClient::class)]
final class BedrockInvokeClientTest extends TestCase
{
    public function testItSendsTheAnthropicShapeAndReadsTheForcedToolCall(): void
    {
        $aws = $this->aws([$this->toolUse(['line1' => '15 Davies Street'])]);

        $answer = (new BedrockLlmClient(model: 'eu.anthropic.claude-haiku-4-5-20251001-v1:0', client: $aws))
            ->complete('system text', 'user text', LlmRefiner::schema());

        self::assertSame(['line1' => '15 Davies Street'], $answer);

        $body = json_decode($aws->calls[0]['body'], true);
        self::assertSame('bedrock-2023-05-31', $body['anthropic_version']);
        self::assertSame('system text', $body['system']);
        self::assertSame('parsed_address', $body['tool_choice']['name']);
        self::assertSame(
            'eu.anthropic.claude-haiku-4-5-20251001-v1:0',
            $aws->calls[0]['modelId'],
            'on-demand needs an inference profile, not a bare model id',
        );
    }

    public function testEffortIsOnlySentWhenConfigured(): void
    {
        $without = $this->aws([$this->toolUse(['line1' => 'x'])]);
        (new BedrockLlmClient(client: $without))->complete('s', 'u', LlmRefiner::schema());
        self::assertArrayNotHasKey('output_config', json_decode($without->calls[0]['body'], true));

        $with = $this->aws([$this->toolUse(['line1' => 'x'])]);
        (new BedrockLlmClient(effort: 'low', client: $with))->complete('s', 'u', LlmRefiner::schema());
        self::assertSame('low', json_decode($with->calls[0]['body'], true)['output_config']['effort']);
    }

    public function testAModelRejectingEffortIsRetriedWithoutIt(): void
    {
        $aws = $this->aws([
            new \RuntimeException('output_config.effort: Extra inputs are not permitted'),
            $this->toolUse(['line1' => 'recovered']),
        ]);

        $answer = (new BedrockLlmClient(effort: 'low', client: $aws))->complete('s', 'u', LlmRefiner::schema());

        self::assertSame(['line1' => 'recovered'], $answer);
        self::assertArrayNotHasKey('output_config', json_decode($aws->calls[1]['body'], true));
    }

    public function testARefusalIsReportedAsSuch(): void
    {
        $aws = $this->aws([['stop_reason' => 'refusal', 'content' => []]]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/declined the request/');

        (new BedrockLlmClient(client: $aws))->complete('s', 'u', LlmRefiner::schema());
    }

    public function testAnUnrelatedFailureSurfaces(): void
    {
        $aws = $this->aws([new \RuntimeException('The security token included in the request is invalid')]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/security token/');

        (new BedrockLlmClient(client: $aws))->complete('s', 'u', LlmRefiner::schema());
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function toolUse(array $input): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'name' => 'parsed_address', 'input' => $input]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ];
    }

    /**
     * @param list<array<string, mixed>|\Throwable> $answers
     */
    private function aws(array $answers): object
    {
        return new class($answers) {
            /** @var list<array{modelId: string, body: string}> */
            public array $calls = [];

            /** @param list<array<string, mixed>|\Throwable> $answers */
            public function __construct(private array $answers)
            {
            }

            /**
             * @param array<string, mixed> $request
             *
             * @return array<string, string>
             */
            public function invokeModel(array $request): array
            {
                $this->calls[] = ['modelId' => $request['modelId'], 'body' => $request['body']];
                $answer = array_shift($this->answers);

                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return ['body' => json_encode($answer ?? []) ?: '{}'];
            }
        };
    }
}
