<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Llm;

use Codelot\AddressParser\Http\HttpClientFactory;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Shared plumbing for providers reached over plain HTTP+JSON.
 *
 * Subclasses decide the URL, the headers, the request body, and how to find the answer inside the
 * response. Everything else — transport, timeouts, status handling, JSON decoding — is here, so a
 * new provider is roughly forty lines.
 */
abstract class HttpJsonLlmClient implements LlmClientInterface
{
    private ClientInterface $http;

    private RequestFactoryInterface $requests;

    private StreamFactoryInterface $streams;

    public function __construct(
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        /** How many times a retryable answer is retried before giving up. */
        protected readonly int $maxAttempts = 3,
        /** Base backoff, doubled per attempt, used when the provider does not send Retry-After. */
        protected readonly float $retryBaseDelay = 0.5,
    ) {
        $this->http = $http ?? HttpClientFactory::create();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    final protected function post(string $url, array $body, array $headers): array
    {
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (false === $encoded) {
            throw new LlmException(sprintf('%s: could not encode the request body', $this->describe()));
        }

        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($encoded));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $attempt = 0;

        while (true) {
            ++$attempt;

            try {
                $response = $this->http->sendRequest($request);
            } catch (\Throwable $e) {
                // A connection error is worth one more try; a broken request is not.
                if ($attempt < $this->maxAttempts) {
                    $this->sleep($this->backoff($attempt));

                    continue;
                }

                throw new LlmException(sprintf('%s: %s', $this->describe(), $e->getMessage()), 0, $e);
            }

            $status = $response->getStatusCode();

            // 429 is a rate limit and 5xx is the provider having a bad moment: both pass with time,
            // and both are common enough on shared tiers that failing immediately wastes the call.
            if ((429 === $status || $status >= 500) && $attempt < $this->maxAttempts) {
                $this->sleep($this->retryAfter($response) ?? $this->backoff($attempt));

                continue;
            }

            break;
        }

        $payload = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            // The provider's own message is the useful part; keep it, but bounded — an error body
            // can be a whole HTML page.
            throw new LlmException(sprintf(
                '%s answered HTTP %d: %s',
                $this->describe(),
                $status,
                mb_substr(trim($payload), 0, 300),
            ));
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw new LlmException(sprintf('%s returned a body that is not JSON', $this->describe()));
        }

        return $decoded;
    }

    private function backoff(int $attempt): float
    {
        return $this->retryBaseDelay * (2 ** ($attempt - 1));
    }

    /**
     * Providers say when to come back; honour it rather than guessing, but never sleep so long
     * that the caller would have been better off with the rule-based answer.
     */
    private function retryAfter(ResponseInterface $response): ?float
    {
        $header = $response->getHeaderLine('Retry-After');

        if ('' === $header) {
            return null;
        }

        $seconds = is_numeric($header)
            ? (float) $header
            : (($timestamp = strtotime($header)) === false ? null : $timestamp - time());

        if (null === $seconds || $seconds <= 0) {
            return null;
        }

        return min($seconds, 5.0);
    }

    private function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * @return array<string, mixed>
     */
    final protected function decodeJsonAnswer(string $text): array
    {
        $text = trim($text);

        // Some models wrap JSON in a fenced block even when asked not to.
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text);
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new LlmException(sprintf('%s returned text that is not JSON: %s', $this->describe(), mb_substr($text, 0, 200)));
        }

        return $decoded;
    }

    /**
     * Strips the JSON Schema keywords a provider rejects.
     *
     * @param array<string, mixed> $schema
     * @param list<string>         $unsupported
     *
     * @return array<string, mixed>
     */
    final protected static function withoutKeywords(array $schema, array $unsupported): array
    {
        $clean = [];

        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array($key, $unsupported, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::withoutKeywords($value, $unsupported) : $value;
        }

        return $clean;
    }
}
