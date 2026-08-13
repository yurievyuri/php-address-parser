<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\Llm\GeminiLlmClient;
use Codelot\AddressParser\Llm\GroqLlmClient;
use Codelot\AddressParser\Llm\LlmException;
use Codelot\AddressParser\Refiner\LlmRefiner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The HTTP-backed providers and the retry logic underneath them.
 *
 * Both were built against live APIs — the request shapes here are the ones those APIs accepted,
 * and the failures are ones they actually returned. Live checks prove a thing works once; these
 * are what notice when it stops.
 */
final class HttpLlmClientTest extends TestCase
{
    public function testGeminiSendsItsOwnRequestShapeAndReadsTheAnswer(): void
    {
        $http = $this->http($this->response(200, json_encode([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => '{"line1":"15 Davies Street","city":"London"}']]],
            ]],
        ]) ?: ''));

        $answer = (new GeminiLlmClient(apiKey: 'test-key', http: $http, retryBaseDelay: 0.0))
            ->complete('system text', 'user text', LlmRefiner::schema());

        self::assertSame(['line1' => '15 Davies Street', 'city' => 'London'], $answer);

        $request = $http->requests[0];
        self::assertStringContainsString('gemini-flash-latest:generateContent', (string) $request->getUri());
        self::assertSame('test-key', $request->getHeaderLine('X-goog-api-key'));

        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('system text', $body['systemInstruction']['parts'][0]['text']);
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertArrayHasKey('responseSchema', $body['generationConfig']);
    }

    /**
     * Gemini's schema dialect is a subset of OpenAPI and rejects `additionalProperties`, so it is
     * stripped; field order is pinned because the model honours it.
     */
    public function testGeminiStripsTheSchemaKeywordsItsDialectRejects(): void
    {
        $http = $this->http($this->response(200, json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '{}']]]]],
        ]) ?: ''));

        (new GeminiLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());

        $schema = json_decode((string) $http->requests[0]->getBody(), true)['generationConfig']['responseSchema'];

        self::assertArrayNotHasKey('additionalProperties', $schema);
        self::assertSame(array_keys($schema['properties']), $schema['propertyOrdering']);
    }

    public function testGeminiTreatsATruncatedAnswerAsAFailure(): void
    {
        $http = $this->http($this->response(200, json_encode([
            'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"line1":"cut']]]]],
        ]) ?: ''));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/stopped early: MAX_TOKENS/');

        (new GeminiLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());
    }

    public function testGeminiReportsABlockedPrompt(): void
    {
        $http = $this->http($this->response(200, json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']]) ?: ''));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/no answer: SAFETY/');

        (new GeminiLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());
    }

    public function testGroqSendsTheOpenAiShapeWithAStrictSchema(): void
    {
        $http = $this->http($this->response(200, json_encode([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"line1":"15 Davies Street"}']]],
        ]) ?: ''));

        $answer = (new GroqLlmClient(apiKey: 'gsk-test', http: $http, retryBaseDelay: 0.0))
            ->complete('system text', 'user text', LlmRefiner::schema());

        self::assertSame(['line1' => '15 Davies Street'], $answer);

        $request = $http->requests[0];
        self::assertSame('https://api.groq.com/openai/v1/chat/completions', (string) $request->getUri());
        self::assertSame('Bearer gsk-test', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame('json_schema', $body['response_format']['type']);
        self::assertTrue($body['response_format']['json_schema']['strict']);
    }

    /**
     * The same class serves any OpenAI-compatible endpoint — a self-hosted vLLM, for instance.
     */
    public function testGroqCanPointAtAnyCompatibleEndpoint(): void
    {
        $http = $this->http($this->response(200, json_encode([
            'choices' => [['message' => ['content' => '{}']]],
        ]) ?: ''));

        (new GroqLlmClient(apiKey: 'k', baseUrl: 'http://vllm.internal:8000/v1', http: $http, retryBaseDelay: 0.0))
            ->complete('s', 'u', LlmRefiner::schema());

        self::assertSame('http://vllm.internal:8000/v1/chat/completions', (string) $http->requests[0]->getUri());
    }

    public function testGroqTreatsAnAnswerCutOffByTheTokenLimitAsAFailure(): void
    {
        $http = $this->http($this->response(200, json_encode([
            'choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"line1":"cut']]],
        ]) ?: ''));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/token limit/');

        (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());
    }

    public function testFencedJsonIsUnwrapped(): void
    {
        $http = $this->http($this->response(200, json_encode([
            'choices' => [['message' => ['content' => "```json\n{\"line1\":\"fenced\"}\n```"]]],
        ]) ?: ''));

        self::assertSame(
            ['line1' => 'fenced'],
            (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema()),
        );
    }

    /**
     * A rate limit is the normal answer from a shared tier, not a reason to lose the call —
     * this is the case that failed live before retries existed.
     */
    public function testARateLimitIsRetried(): void
    {
        $http = $this->http(
            $this->response(429, '{"error":"rate limited"}'),
            $this->response(200, json_encode(['choices' => [['message' => ['content' => '{"line1":"second try"}']]]]) ?: ''),
        );

        $answer = (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());

        self::assertSame(['line1' => 'second try'], $answer);
        self::assertCount(2, $http->requests);
    }

    #[DataProvider('retryableStatusProvider')]
    public function testServerSideFailuresAreRetried(int $status): void
    {
        $http = $this->http(
            $this->response($status, 'upstream trouble'),
            $this->response(200, json_encode(['choices' => [['message' => ['content' => '{"line1":"ok"}']]]]) ?: ''),
        );

        self::assertSame(
            ['line1' => 'ok'],
            (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema()),
        );
    }

    public static function retryableStatusProvider(): array
    {
        return [[500], [502], [503]];
    }

    public function testAClientErrorIsNotRetried(): void
    {
        $http = $this->http($this->response(400, '{"error":{"message":"This model does not support response format json_schema"}}'));

        try {
            (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());
            self::fail('a 400 should not be retried and should surface');
        } catch (LlmException $e) {
            self::assertStringContainsString('does not support response format', $e->getMessage());
        }

        self::assertCount(1, $http->requests, 'retrying a rejected request only wastes time');
    }

    public function testRetriesAreBoundedAndTheFailureSurfaces(): void
    {
        $http = $this->http(
            $this->response(503, 'down'),
            $this->response(503, 'down'),
            $this->response(503, 'down'),
            $this->response(503, 'down'),
        );

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/HTTP 503/');

        try {
            (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());
        } finally {
            self::assertCount(3, $http->requests, 'three attempts, then give up');
        }
    }

    public function testATransportFailureIsRetriedThenReported(): void
    {
        $http = $this->http(
            new \RuntimeException('connection reset'),
            $this->response(200, json_encode(['choices' => [['message' => ['content' => '{"line1":"recovered"}']]]]) ?: ''),
        );

        self::assertSame(
            ['line1' => 'recovered'],
            (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema()),
        );
    }

    public function testANonJsonBodyIsReported(): void
    {
        $http = $this->http($this->response(200, '<html>gateway</html>'));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/not JSON/');

        (new GroqLlmClient(apiKey: 'k', http: $http, retryBaseDelay: 0.0))->complete('s', 'u', LlmRefiner::schema());
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function response(int $status, string $body): array
    {
        return [$status, $body];
    }

    /**
     * @param array{0: int, 1: string}|\Throwable ...$answers
     */
    private function http(array|\Throwable ...$answers): ClientInterface
    {
        return new class($answers) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @param list<array{0: int, 1: string}|\Throwable> $answers */
            public function __construct(private array $answers)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $answer = array_shift($this->answers);

                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                [$status, $body] = $answer ?? [200, '{}'];

                return new class($status, $body) implements ResponseInterface {
                    public function __construct(private readonly int $status, private readonly string $body)
                    {
                    }

                    public function getStatusCode(): int { return $this->status; }
                    public function getBody(): StreamInterface
                    {
                        return \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory()->createStream($this->body);
                    }
                    public function getHeaderLine(string $name): string { return ''; }
                    public function getProtocolVersion(): string { return '1.1'; }
                    public function withProtocolVersion(string $version): static { return $this; }
                    public function getHeaders(): array { return []; }
                    public function hasHeader(string $name): bool { return false; }
                    public function getHeader(string $name): array { return []; }
                    public function withHeader(string $name, $value): static { return $this; }
                    public function withAddedHeader(string $name, $value): static { return $this; }
                    public function withoutHeader(string $name): static { return $this; }
                    public function withBody(StreamInterface $body): static { return $this; }
                    public function withStatus(int $code, string $reasonPhrase = ''): static { return $this; }
                    public function getReasonPhrase(): string { return ''; }
                };
            }
        };
    }
}
