<?php

declare(strict_types=1);

namespace Address\Parser\Config;

use Address\Parser\AddressParserInterface;
use Address\Parser\Country\CountryResolverInterface;
use Address\Parser\Country\Iso3166CountryResolver;
use Address\Parser\EscalatingParser;
use Address\Parser\Http\CurlTransport;
use Address\Parser\Llm\AnthropicLlmClient;
use Address\Parser\Llm\LlmClientInterface;
use Address\Parser\Quality\Issue;
use Address\Parser\Quality\QualityInspector;
use Address\Parser\Refiner\LibpostalRefiner;
use Address\Parser\Refiner\LlmRefiner;
use Address\Parser\Refiner\RefinerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds a parser from configuration, so which providers run — and in what order — is a
 * deployment decision rather than a code change.
 *
 * Two ways to add a provider:
 *   1. `register()` a factory under a type name, then reference that name in the configuration;
 *   2. `type: custom` with the class name, for a class that lives anywhere in the host project.
 *
 * Both take the provider from outside this package, which is the point: a PAF lookup, an internal
 * geocoder, or another vendor's model plugs in without touching the library.
 */
final class ParserFactory
{
    /** @var array<string, callable(array<string, mixed>): RefinerInterface> */
    private array $providers = [];

    public function __construct(
        private readonly CountryResolverInterface $countries = new Iso3166CountryResolver(),
        private readonly ?CacheInterface $cache = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->registerBuiltins();
    }

    /**
     * @param callable(array<string, mixed>): RefinerInterface $factory
     */
    public function register(string $type, callable $factory): self
    {
        $this->providers[$type] = $factory;

        return $this;
    }

    /**
     * @param array{
     *     escalate_on?: list<string>,
     *     providers?: list<array<string, mixed>>,
     *     base?: AddressParserInterface
     * } $config
     */
    public function create(array $config, ?AddressParserInterface $base = null): AddressParserInterface
    {
        $refiners = [];

        foreach ($config['providers'] ?? [] as $provider) {
            if (false === ($provider['enabled'] ?? true)) {
                continue;
            }

            $refiners[] = $this->build($provider);
        }

        $escalateOn = [];
        foreach ($config['escalate_on'] ?? [] as $issue) {
            $case = Issue::tryFrom((string) $issue);

            if (null === $case) {
                throw new \InvalidArgumentException(sprintf(
                    'unknown issue "%s" in escalate_on; known: %s',
                    $issue,
                    implode(', ', array_map(static fn (Issue $i): string => $i->value, Issue::cases())),
                ));
            }

            $escalateOn[] = $case;
        }

        return new EscalatingParser(
            base: $base ?? new \Address\Parser\RuleBasedParser($this->countries, $this->logger),
            refiners: $refiners,
            inspector: new QualityInspector($this->countries),
            escalateOn: $escalateOn,
            logger: $this->logger,
        );
    }

    /**
     * YAML needs either ext-yaml or symfony/yaml; the PHP-array form in `create()` always works.
     */
    public function createFromYaml(string $path): AddressParserInterface
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('config file "%s" is not readable', $path));
        }

        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $config = \Symfony\Component\Yaml\Yaml::parseFile($path);
        } elseif (\function_exists('yaml_parse_file')) {
            $config = yaml_parse_file($path);
        } else {
            throw new \RuntimeException('reading YAML needs symfony/yaml or ext-yaml — or call create() with a PHP array');
        }

        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('config file "%s" does not contain a mapping', $path));
        }

        /** @var array{escalate_on?: list<string>, providers?: list<array<string, mixed>>} $config */
        return $this->create($config);
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function build(array $provider): RefinerInterface
    {
        $type = (string) ($provider['type'] ?? '');

        if ('custom' === $type) {
            return $this->buildCustom($provider);
        }

        if (!isset($this->providers[$type])) {
            throw new \InvalidArgumentException(sprintf(
                'unknown provider type "%s"; known: %s, custom',
                $type,
                implode(', ', array_keys($this->providers)),
            ));
        }

        return ($this->providers[$type])($provider);
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function buildCustom(array $provider): RefinerInterface
    {
        $class = (string) ($provider['class'] ?? '');

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('provider class "%s" does not exist', $class));
        }

        if (!is_a($class, RefinerInterface::class, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" does not implement %s', $class, RefinerInterface::class));
        }

        /** @var array<string, mixed> $options */
        $options = $provider['options'] ?? [];

        // A named constructor keeps configuration parsing inside the provider that understands it.
        if (method_exists($class, 'fromConfig')) {
            /** @var RefinerInterface */
            return $class::fromConfig($options);
        }

        /** @var RefinerInterface */
        return new $class(...$options);
    }

    private function registerBuiltins(): void
    {
        $this->register('llm', function (array $provider): RefinerInterface {
            $client = $provider['client'] ?? null;

            if (!$client instanceof LlmClientInterface) {
                $client = new AnthropicLlmClient(
                    model: (string) ($provider['model'] ?? 'claude-opus-5'),
                    maxTokens: (int) ($provider['max_tokens'] ?? 2048),
                    apiKey: isset($provider['api_key']) ? (string) $provider['api_key'] : null,
                    awsRegion: isset($provider['aws_region']) ? (string) $provider['aws_region'] : null,
                );
            }

            return new LlmRefiner(
                client: $client,
                countries: $this->countries,
                cache: $this->cache,
                cacheTtl: (int) ($provider['cache_ttl'] ?? 2_592_000),
                logger: $this->logger,
                name: (string) ($provider['name'] ?? 'llm'),
            );
        });

        $this->register('libpostal', function (array $provider): RefinerInterface {
            if (!isset($provider['endpoint'])) {
                throw new \InvalidArgumentException('the libpostal provider needs an "endpoint"');
            }

            return new LibpostalRefiner(
                endpoint: (string) $provider['endpoint'],
                http: new CurlTransport(
                    timeout: (float) ($provider['timeout'] ?? 2.0),
                    connectTimeout: (float) ($provider['connect_timeout'] ?? 1.0),
                ),
                countries: $this->countries,
                name: (string) ($provider['name'] ?? 'libpostal'),
            );
        });
    }
}
