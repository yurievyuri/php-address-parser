<?php

declare(strict_types=1);

namespace Address\Parser\Http;

/**
 * Default transport: ext-curl, a connect timeout, and a total timeout. A refiner that hangs is
 * worse than one that fails, because the caller is a request path.
 */
final class CurlTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
    ) {
        if (!\extension_loaded('curl')) {
            throw new HttpException('ext-curl is not available — supply your own HttpTransportInterface');
        }
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): string
    {
        $handle = curl_init($url);

        if (false === $handle) {
            throw new HttpException('could not initialise a request to ' . $url);
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_HTTPHEADER => $formatted,
        ]);

        if (null !== $body) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (false === $response) {
            throw new HttpException(sprintf('%s %s failed: %s', $method, $url, $error));
        }

        if ($status < 200 || $status >= 300) {
            throw new HttpException(sprintf('%s %s returned HTTP %d', $method, $url, $status));
        }

        return (string) $response;
    }
}
