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

    // PSR-3 logging. Pass your own logger to ParserFactory and this whole block is ignored —
    // it exists so a project without Monolog is still observable out of the box.
    'logging' => [
        'path' => $env('ADDRESS_PARSER_LOG', '/home/bitrix/debug/address/parser.log'),
        'level' => 'info',      // debug | info | notice | warning | error | critical | alert | emergency
        'channel' => 'address',

        // Keep events in memory as well, so a batch can be asked "did anything serious happen?"
        // without reading the file: $factory->events()->criticalRecords().
        'collect' => [
            'enabled' => true,
            'min_level' => 'warning',    // what is kept in memory
            'notify_level' => 'critical',// from which severity the notifier is called
            'limit' => 1000,             // cap on retained records, so a long batch stays bounded
            // A class implementing Codelot\AddressParser\Log\NotifierInterface — this is the hand-off
            // to the rest of the system: alerting, a queue, a webhook.
            // 'notifier' => App\Address\SlackNotifier::class,
        ],
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

        // Bedrock through the Converse API — the same request shape for every vendor, so switching
        // is a modelId. Model ids below are inference profiles, which on-demand invocation
        // requires: a bare "anthropic.claude-…" answers "Invocation … with on-demand throughput
        // isn't supported". These are the EU profiles active in our accounts (2026-08-13):
        //
        //   Anthropic  eu.anthropic.claude-haiku-4-5-20251001-v1:0   the default: $1/$5 per MTok
        //              eu.anthropic.claude-sonnet-5                  $3/$15, and only worth it with
        //                                                            'effort' => 'low' — see below
        //   Amazon     eu.amazon.nova-micro-v1:0 / nova-lite-v1:0 / nova-pro-v1:0 / nova-2-lite-v1:0
        //   Mistral    eu.mistral.pixtral-large-2502-v1:0
        //
        // Models without a regional profile are invoked by plain id, e.g. qwen.qwen3-32b-v1:0,
        // openai/gpt-oss-120b-1:0, mistral.ministral-3-3b-instruct.
        [
            'service' => 'bedrock',
            'enabled' => false,
            'model' => 'eu.anthropic.claude-haiku-4-5-20251001-v1:0',
            'region' => $env('AWS_REGION', 'eu-west-1'),
            'max_tokens' => 2048,
            // Splitting an address is extraction against a fixed schema, so the cheap end of the
            // range is the right place to start. Move up only if acceptance says so, and then to
            // Sonnet at 'effort' => 'low' rather than to a bigger model: Haiku rejects the
            // parameter outright, Sonnet accepts it. false = do not send it at all.
            'effort' => false,
            // Vendor-specific extras, passed through untouched.
            'model_fields' => [],
        ],

        [
            'service' => 'claude',
            'enabled' => null !== $env('ANTHROPIC_API_KEY') || null !== $env('AWS_REGION'),
            // Splitting an address is extraction against a fixed schema, not reasoning, so the
            // cheapest model of the family is the right default. Move up if acceptance says so.
            'model' => $env('ADDRESS_PARSER_MODEL', 'claude-haiku-4-5'),
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

        // Google Gemini, over its REST API. gemini-flash-latest verified 2026-08-13.
        // [
        //     'service' => 'gemini',
        //     'enabled' => true,
        //     'model' => 'gemini-flash-latest',
        //     'api_key' => $env('GEMINI_API_KEY'),
        // ],

        // Groq Cloud, and any other OpenAI-compatible endpoint via base_url. Not every model there
        // supports json_schema output: openai/gpt-oss-120b and qwen/qwen3.6-27b do (verified
        // 2026-08-13), llama-3.3-70b-versatile answers "This model does not support response
        // format json_schema".
        // [
        //     'service' => 'groq',
        //     'enabled' => true,
        //     'model' => 'openai/gpt-oss-120b',
        //     'api_key' => $env('GROQ_API_KEY'),
        // ],

        // Prompts are configuration too: override them per service, inline or from a file.
        // 'system_prompt_file' => __DIR__ . '/prompts/address-system.txt',
        // 'user_prompt' => "Address:\n{address}\n\nDraft: {draft}\nProblems: {issues}",

        // Anything else: a class from your own project, with its own settings.
        // [
        //     'class' => App\Address\IdealPostcodesRefiner::class,
        //     'enabled' => false,
        //     'apiKey' => $env('IDEAL_POSTCODES_KEY'),
        // ],
    ],
];
