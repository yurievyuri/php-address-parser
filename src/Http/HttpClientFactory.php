<?php

declare(strict_types=1);

namespace Address\Parser\Http;

use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;

/**
 * Builds a PSR-18 client with timeouts.
 *
 * PSR-18 deliberately says nothing about timeouts — they are constructor configuration, and every
 * implementation spells them differently. That leaves a gap this class fills: a parser called from
 * a request path must never inherit a client's default timeout, which in most libraries is
 * "wait indefinitely".
 *
 * The client the host application already has is used when one is passed in; otherwise the first
 * implementation present is configured with the limits below.
 */
final class HttpClientFactory
{
    /** Enough for a TCP + TLS handshake to a healthy service, short enough to fail fast. */
    public const DEFAULT_CONNECT_TIMEOUT = 3.0;

    /** A refinement is an improvement, not a dependency: past this the rule-based result wins. */
    public const DEFAULT_TIMEOUT = 10.0;

    public static function create(
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        float $timeout = self::DEFAULT_TIMEOUT,
    ): ClientInterface {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
                'http_errors' => false,
            ]);
        }

        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)
            && class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            return new \Symfony\Component\HttpClient\Psr18Client(
                \Symfony\Component\HttpClient\HttpClient::create([
                    'timeout' => $connectTimeout,
                    'max_duration' => $timeout,
                ]),
            );
        }

        // Whatever the project has. Its own timeout configuration applies — this is the one path
        // where the limits above cannot be enforced from here.
        return Psr18ClientDiscovery::find();
    }
}
