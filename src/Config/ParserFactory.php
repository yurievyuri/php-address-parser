<?php

declare(strict_types=1);

namespace Address\Parser\Config;

use Address\Parser\AddressParserInterface;
use Address\Parser\Country\CountryResolverInterface;
use Address\Parser\Country\Iso3166CountryResolver;
use Address\Parser\EscalatingParser;
use Address\Parser\Http\HttpClientFactory;
use Address\Parser\Log\EventCollector;
use Address\Parser\Log\FileLogger;
use Address\Parser\Log\NotifierInterface;
use Address\Parser\Llm\AnthropicLlmClient;
use Address\Parser\Llm\BedrockLlmClient;
use Address\Parser\Llm\GeminiLlmClient;
use Address\Parser\Llm\GroqLlmClient;
use Address\Parser\Llm\LlmClientInterface;
use Address\Parser\Quality\Issue;
use Address\Parser\Quality\QualityInspector;
use Address\Parser\Refiner\LibpostalRefiner;
use Address\Parser\Refiner\LlmRefiner;
use Address\Parser\Refiner\RefinerInterface;
use Address\Parser\RuleBasedParser;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds a parser from configuration.
 *
 * The pipeline knows three things about a service and no more: **where it sits in the order**,
 * **whether it is on**, and **an opaque bag of settings** that only that service understands.
 * Whether a service is a language model, a geocoder, or a lookup against a national postal file is
 * the service's business — the parser never branches on it.
 *
 * Which means: changing provider, model, endpoint, or order is a configuration change. Adding a
 * kind of provider that does not exist yet is a new class plus one line of configuration.
 */
final class ParserFactory
{
    /** @var array<string, callable(array<string, mixed>, self): RefinerInterface> */
    private array $services = [];

    /** @var array{connect_timeout: float, timeout: float} */
    private array $httpDefaults = [
        'connect_timeout' => HttpClientFactory::DEFAULT_CONNECT_TIMEOUT,
        'timeout' => HttpClientFactory::DEFAULT_TIMEOUT,
    ];

    private LoggerInterface $logger;

    private ?EventCollector $events = null;

    public function __construct(
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->registerBuiltins();
    }

    /**
     * Teach the factory a service name usable in configuration.
     *
     * @param callable(array<string, mixed>, self): RefinerInterface $factory
     */
    public function register(string $name, callable $factory): self
    {
        $this->services[$name] = $factory;

        return $this;
    }

    public function countries(): CountryResolverInterface
    {
        return $this->countries;
    }

    public function cache(): ?CacheInterface
    {
        return $this->cache;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * What went wrong during parsing, in memory, filterable by PSR-3 severity.
     *
     * Present once `logging.collect` is configured. This is the handle for asking after a batch
     * "did anything serious happen?" and for handing the serious part to another part of the
     * system without reading a log file.
     */
    public function events(): ?EventCollector
    {
        return $this->events;
    }

    /**
     * The configured HTTP limits, for services that reach the network. A service reads them when
     * its own settings do not override them.
     *
     * @return array{connect_timeout: float, timeout: float}
     */
    public function httpTimeouts(): array
    {
        return $this->httpDefaults;
    }

    public function createFromFile(string $path, ?AddressParserInterface $base = null): AddressParserInterface
    {
        return $this->create(Configuration::load($path), $base);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config, ?AddressParserInterface $base = null): AddressParserInterface
    {
        $config = Configuration::resolve($config);
        $this->configureLogging($config['logging'] ?? []);

        /** @var array<string, mixed> $http */
        $http = $config['http'] ?? [];
        $this->httpDefaults = [
            'connect_timeout' => (float) ($http['connect_timeout'] ?? HttpClientFactory::DEFAULT_CONNECT_TIMEOUT),
            'timeout' => (float) ($http['timeout'] ?? HttpClientFactory::DEFAULT_TIMEOUT),
        ];

        $refiners = [];

        /** @var list<array<string, mixed>> $services */
        $services = $config['services'] ?? [];

        foreach ($services as $service) {
            if (false === ($service['enabled'] ?? true)) {
                continue;
            }

            // The defaults are offered through httpTimeouts(), not merged into the settings — a
            // service that does not speak HTTP must not be handed HTTP settings it cannot accept.
            $refiners[] = $this->build($service);
        }

        return new EscalatingParser(
            base: $base ?? new RuleBasedParser($this->countries, $this->logger),
            refiners: $refiners,
            inspector: new QualityInspector($this->countries),
            escalateOn: $this->issues($config['escalate_on'] ?? []),
            logger: $this->logger,
        );
    }

    /**
     * Builds the logging stack from configuration: a file logger when a path is given, wrapped in
     * a collector when in-memory collection is asked for. A logger passed to the constructor wins
     * — an application with Monolog keeps its own pipeline.
     *
     * @param array<string, mixed> $logging
     */
    private function configureLogging(array $logging): void
    {
        if ([] === $logging) {
            return;
        }

        $base = $this->logger;

        if ($base instanceof NullLogger && isset($logging['path'])) {
            $base = new FileLogger(
                path: (string) $logging['path'],
                minLevel: (string) ($logging['level'] ?? LogLevel::INFO),
                channel: (string) ($logging['channel'] ?? 'address'),
            );
        }

        /** @var array<string, mixed> $collect */
        $collect = $logging['collect'] ?? [];

        if ([] === $collect || false === ($collect['enabled'] ?? true)) {
            $this->logger = $base;

            return;
        }

        $notifier = $collect['notifier'] ?? null;

        if (is_string($notifier) && '' !== $notifier) {
            if (!class_exists($notifier) || !is_a($notifier, NotifierInterface::class, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'the notifier "%s" must exist and implement %s',
                    $notifier,
                    NotifierInterface::class,
                ));
            }

            $notifier = new $notifier();
        }

        $this->events = new EventCollector(
            inner: $base,
            minLevel: (string) ($collect['min_level'] ?? LogLevel::WARNING),
            notifier: $notifier instanceof NotifierInterface ? $notifier : null,
            notifyLevel: (string) ($collect['notify_level'] ?? LogLevel::CRITICAL),
            limit: (int) ($collect['limit'] ?? 1000),
        );

        $this->logger = $this->events;
    }

    /**
     * @param list<string> $names
     *
     * @return list<Issue>
     */
    private function issues(array $names): array
    {
        $issues = [];

        foreach ($names as $name) {
            $case = Issue::tryFrom((string) $name);

            if (null === $case) {
                throw new \InvalidArgumentException(sprintf(
                    'unknown issue "%s" in escalate_on; known: %s',
                    $name,
                    implode(', ', array_map(static fn (Issue $i): string => $i->value, Issue::cases())),
                ));
            }

            $issues[] = $case;
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function build(array $service): RefinerInterface
    {
        // A class named directly needs no registration — it is the escape hatch for a service that
        // lives in the host project.
        if (isset($service['class'])) {
            return $this->buildFromClass((string) $service['class'], $service);
        }

        $name = (string) ($service['service'] ?? '');

        if (!isset($this->services[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'unknown service "%s"; registered: %s (or name a class with "class:")',
                $name,
                implode(', ', array_keys($this->services)) ?: 'none',
            ));
        }

        return ($this->services[$name])($service, $this);
    }

    /**
     * @param array<string, mixed> $service
     */
    private function buildFromClass(string $class, array $service): RefinerInterface
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('service class "%s" does not exist', $class));
        }

        if (!is_a($class, RefinerInterface::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" does not implement %s',
                $class,
                RefinerInterface::class,
            ));
        }

        unset($service['class'], $service['service'], $service['enabled']);

        // A named constructor keeps the reading of settings inside the class that defines them.
        if (method_exists($class, 'fromConfig')) {
            /** @var RefinerInterface */
            return $class::fromConfig($service, $this);
        }

        /** @var RefinerInterface */
        return new $class(...$service);
    }

    /**
     * Wraps any LLM client in the refiner, with the caching and anti-invention checks that every
     * model-backed service gets regardless of vendor.
     *
     * @param array<string, mixed>       $service
     * @param callable(): LlmClientInterface $default
     */
    private function llm(array $service, string $name, callable $default): RefinerInterface
    {
        $client = $service['client'] ?? null;

        return new LlmRefiner(
            client: $client instanceof LlmClientInterface ? $client : $default(),
            countries: $this->countries,
            cache: $this->cache,
            cacheTtl: (int) ($service['cache_ttl'] ?? 2_592_000),
            logger: $this->logger,
            name: (string) ($service['name'] ?? $name),
            systemPrompt: $this->prompt($service, 'system_prompt', LlmRefiner::DEFAULT_SYSTEM_PROMPT),
            userPrompt: $this->prompt($service, 'user_prompt', LlmRefiner::DEFAULT_USER_PROMPT),
            rejectInventedText: (bool) ($service['reject_invented_text'] ?? true),
            // Everything the model is given comes from configuration: the prompts, the schema its
            // answer must satisfy, and how that answer maps onto the result fields.
            schema: $this->schema($service),
            fieldMap: isset($service['field_map']) && is_array($service['field_map'])
                ? array_map('strval', $service['field_map'])
                : null,
        );
    }

    /**
     * `false`, `null`, and an empty string all mean "do not send the parameter" — the API has no
     * "effort off" value, so the only way to disable it is to leave it out.
     */
    private static function effort(mixed $value): ?string
    {
        if (null === $value || false === $value || '' === $value) {
            return null;
        }

        $effort = strtolower((string) $value);
        $known = ['low', 'medium', 'high', 'xhigh', 'max'];

        if (!in_array($effort, $known, true)) {
            throw new \InvalidArgumentException(sprintf(
                'unknown effort "%s"; expected one of %s, or false to omit it',
                $value,
                implode(', ', $known),
            ));
        }

        return $effort;
    }

    /**
     * The JSON Schema for the model's answer: inline as `schema`, or a path to a JSON file as
     * `schema_file`. Absent means the library's own.
     *
     * @param array<string, mixed> $service
     *
     * @return array<string, mixed>|null
     */
    private function schema(array $service): ?array
    {
        $path = $service['schema_file'] ?? null;

        if (is_string($path) && '' !== $path) {
            $contents = @file_get_contents($path);

            if (false === $contents) {
                throw new \InvalidArgumentException(sprintf('cannot read the schema file "%s"', $path));
            }

            $decoded = json_decode($contents, true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException(sprintf('the schema file "%s" is not valid JSON', $path));
            }

            return $decoded;
        }

        return isset($service['schema']) && is_array($service['schema']) ? $service['schema'] : null;
    }

    /**
     * A prompt may be given inline or as a path to a file — long prompts are easier to review and
     * diff as their own file. `<key>_file` wins when both are present.
     *
     * @param array<string, mixed> $service
     */
    private function prompt(array $service, string $key, string $default): string
    {
        $path = $service[$key . '_file'] ?? null;

        if (is_string($path) && '' !== $path) {
            $contents = @file_get_contents($path);

            if (false === $contents) {
                throw new \InvalidArgumentException(sprintf('cannot read the prompt file "%s"', $path));
            }

            return $contents;
        }

        $inline = $service[$key] ?? null;

        return is_string($inline) && '' !== trim($inline) ? $inline : $default;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function requireSetting(array $service, string $key, string $service_name): string
    {
        $value = $service[$key] ?? null;

        if (!is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(sprintf(
                'the %s service needs "%s" — read it from the environment rather than writing it into the file',
                $service_name,
                $key,
            ));
        }

        return $value;
    }

    private function registerBuiltins(): void
    {
        // Claude, through the official SDK, on the Anthropic API or on Bedrock. The default model
        // is the cheapest of the family: splitting an address is extraction against a fixed
        // schema, not reasoning, and this runs over every address that the rules could not resolve.
        $this->register('claude', fn (array $service): RefinerInterface => $this->llm(
            $service,
            'claude',
            fn (): LlmClientInterface => new AnthropicLlmClient(
                model: (string) ($service['model'] ?? 'claude-haiku-4-5'),
                maxTokens: (int) ($service['max_tokens'] ?? 2048),
                apiKey: isset($service['api_key']) ? (string) $service['api_key'] : null,
                awsRegion: isset($service['aws_region']) ? (string) $service['aws_region'] : null,
            ),
        ));

        // Claude on Bedrock through the AWS SDK — credentials from the instance role, traffic
        // inside the account, no extra dependency on a project that already runs on AWS.
        $this->register('bedrock', fn (array $service): RefinerInterface => $this->llm(
            $service,
            'bedrock',
            fn (): LlmClientInterface => new BedrockLlmClient(
                model: (string) ($service['model'] ?? 'anthropic.claude-haiku-4-5-20251001-v1:0'),
                region: (string) ($service['region'] ?? getenv('AWS_REGION') ?: 'eu-west-1'),
                maxTokens: (int) ($service['max_tokens'] ?? 2048),
                // Omitted by default: several models reject the parameter outright. Set it only for
                // a model that supports it — Sonnet does, Haiku does not.
                effort: self::effort($service['effort'] ?? null),
            ),
        ));

        // Google Gemini, over its REST API — native JSON-schema output.
        $this->register('gemini', fn (array $service): RefinerInterface => $this->llm(
            $service,
            'gemini',
            fn (): LlmClientInterface => new GeminiLlmClient(
                apiKey: $this->requireSetting($service, 'api_key', 'gemini'),
                model: (string) ($service['model'] ?? 'gemini-flash-latest'),
                maxTokens: (int) ($service['max_tokens'] ?? 2048),
                http: HttpClientFactory::create(
                    connectTimeout: (float) ($service['connect_timeout'] ?? $this->httpDefaults['connect_timeout']),
                    timeout: (float) ($service['timeout'] ?? $this->httpDefaults['timeout']),
                ),
            ),
        ));

        // Groq Cloud, and any other OpenAI-compatible endpoint via base_url.
        $this->register('groq', fn (array $service): RefinerInterface => $this->llm(
            $service,
            'groq',
            fn (): LlmClientInterface => new GroqLlmClient(
                apiKey: $this->requireSetting($service, 'api_key', 'groq'),
                model: (string) ($service['model'] ?? 'openai/gpt-oss-120b'),
                maxTokens: (int) ($service['max_tokens'] ?? 2048),
                baseUrl: (string) ($service['base_url'] ?? 'https://api.groq.com/openai/v1'),
                http: HttpClientFactory::create(
                    connectTimeout: (float) ($service['connect_timeout'] ?? $this->httpDefaults['connect_timeout']),
                    timeout: (float) ($service['timeout'] ?? $this->httpDefaults['timeout']),
                ),
            ),
        ));

        $this->register('libpostal', function (array $service): RefinerInterface {
            if (!isset($service['endpoint'])) {
                throw new \InvalidArgumentException('the libpostal service needs an "endpoint"');
            }

            return new LibpostalRefiner(
                endpoint: (string) $service['endpoint'],
                http: $service['http_client'] ?? HttpClientFactory::create(
                    connectTimeout: (float) ($service['connect_timeout'] ?? $this->httpDefaults['connect_timeout']),
                    timeout: (float) ($service['timeout'] ?? $this->httpDefaults['timeout']),
                ),
                countries: $this->countries,
                name: (string) ($service['name'] ?? 'libpostal'),
            );
        });
    }
}
