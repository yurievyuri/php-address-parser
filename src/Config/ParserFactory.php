<?php

declare(strict_types=1);

namespace Address\Parser\Config;

use Address\Parser\AddressParserInterface;
use Address\Parser\Country\CountryResolverInterface;
use Address\Parser\Country\Iso3166CountryResolver;
use Address\Parser\EscalatingParser;
use Address\Parser\Http\HttpClientFactory;
use Address\Parser\Llm\AnthropicLlmClient;
use Address\Parser\Llm\LlmClientInterface;
use Address\Parser\Quality\Issue;
use Address\Parser\Quality\QualityInspector;
use Address\Parser\Refiner\LibpostalRefiner;
use Address\Parser\Refiner\LlmRefiner;
use Address\Parser\Refiner\RefinerInterface;
use Address\Parser\RuleBasedParser;
use Psr\Log\LoggerInterface;
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

    public function __construct(
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly ?CacheInterface $cache = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
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

    private function registerBuiltins(): void
    {
        // Claude, through the official SDK, on the Anthropic API or on Bedrock.
        $this->register('claude', function (array $service): RefinerInterface {
            $client = $service['client'] ?? null;

            if (!$client instanceof LlmClientInterface) {
                $client = new AnthropicLlmClient(
                    model: (string) ($service['model'] ?? 'claude-opus-5'),
                    maxTokens: (int) ($service['max_tokens'] ?? 2048),
                    apiKey: isset($service['api_key']) ? (string) $service['api_key'] : null,
                    awsRegion: isset($service['aws_region']) ? (string) $service['aws_region'] : null,
                );
            }

            return new LlmRefiner(
                client: $client,
                countries: $this->countries,
                cache: $this->cache,
                cacheTtl: (int) ($service['cache_ttl'] ?? 2_592_000),
                logger: $this->logger,
                name: (string) ($service['name'] ?? 'claude'),
            );
        });

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
