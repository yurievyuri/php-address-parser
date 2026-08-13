<?php

declare(strict_types=1);

namespace Address\Parser\Http;

/**
 * Minimal HTTP seam so the package needs no HTTP client dependency of its own. Host applications
 * that already have PSR-18 can wrap it in a dozen lines.
 */
interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @return string the response body
     *
     * @throws HttpException on a transport failure or a non-2xx status
     */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): string;
}
