<?php

/**
 * The pipeline: rules first, then these services in order — and only while the result is still
 * wrong. The parser knows three things about a service: where it sits in the order, whether it is
 * on, and its settings. What the service actually is, is the service's own business.
 *
 * A PHP file is the most capable of the supported formats and needs no extra package: it is
 * opcached like any other code, and values can come from wherever the application already keeps
 * them — an environment loaded from AWS AppConfig, a parameter store, a container parameter, a
 * feature flag. Compute them here rather than teaching the library about your configuration
 * source.
 */

declare(strict_types=1);

// Anything the application can read is available. This is the point of the PHP format: a value
// that lives in AppConfig, SSM, or a container parameter needs no bridge — just read it.
$env = static fn (string $name, ?string $default = null): ?string => getenv($name) ?: $default;

return [
    // Limits for every service that talks over HTTP. A parser called from a request path must
    // never inherit a client's "wait forever" default.
    'http' => [
        'connect_timeout' => 3,   // seconds for the TCP + TLS handshake
        'timeout' => 10,          // seconds for the whole exchange
    ],

    // Which quality problems justify paying for a service call. Omit to escalate on any problem.
    // Keep the list tight: each entry is money and latency spent on the addresses that trigger it.
    'escalate_on' => ['country_missing', 'token_lost'],

    'services' => [
        // Cheap and fast goes first — a libpostal sidecar answers in milliseconds and costs
        // nothing per call.
        [
            'service' => 'libpostal',
            'enabled' => null !== $env('LIBPOSTAL_URL'),
            'endpoint' => $env('LIBPOSTAL_URL', 'http://libpostal.internal:8080/parse'),
        ],

        [
            'service' => 'claude',
            'enabled' => null !== $env('ANTHROPIC_API_KEY') || null !== $env('AWS_REGION'),
            'model' => $env('ADDRESS_PARSER_MODEL', 'claude-opus-5'),
            'max_tokens' => 2048,
            // Secrets are read here, from wherever the application's environment came from, and
            // never written into a file that goes into git.
            'api_key' => $env('ANTHROPIC_API_KEY'),
            // Set an AWS region to go through Bedrock instead of the Anthropic API. The
            // `anthropic.` model prefix is added for you.
            // 'aws_region' => $env('AWS_REGION'),
            //
            // Address strings repeat heavily in real data, so answers are cached — 30 days.
            'cache_ttl' => 2_592_000,
        ],

        // Anything else: a class from your own project, with its own settings.
        // [
        //     'class' => App\Address\IdealPostcodesRefiner::class,
        //     'enabled' => false,
        //     'apiKey' => $env('IDEAL_POSTCODES_KEY'),
        // ],
    ],
];
