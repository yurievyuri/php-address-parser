<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Config;

/**
 * Reads the pipeline configuration and resolves environment references in it.
 *
 * Secrets do not belong in a configuration file that lives in git. Any string value may instead
 * reference an environment variable — `${ANTHROPIC_API_KEY}`, or `${LIBPOSTAL_URL:-http://localhost:8080/parse}`
 * with a fallback — and it is resolved when the configuration is loaded. A reference with no value
 * and no fallback is an error at load time rather than a failed HTTP call later.
 */
final class Configuration
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function resolve(array $config): array
    {
        /** @var array<string, mixed> */
        return self::walk($config);
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('configuration file "%s" is not readable', $path));
        }

        $config = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => require $path,
            'json' => json_decode((string) file_get_contents($path), true),
            'yaml', 'yml' => self::parseYaml($path),
            default => throw new \InvalidArgumentException(
                sprintf('unsupported configuration format for "%s" — use .yaml, .json, or .php', $path),
            ),
        };

        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('configuration file "%s" does not contain a mapping', $path));
        }

        /** @var array<string, mixed> $config */
        return self::resolve($config);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseYaml(string $path): array
    {
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            /** @var array<string, mixed> */
            return (array) \Symfony\Component\Yaml\Yaml::parseFile($path);
        }

        if (\function_exists('yaml_parse_file')) {
            /** @var array<string, mixed> */
            return (array) yaml_parse_file($path);
        }

        throw new \RuntimeException(
            'reading YAML needs symfony/yaml or ext-yaml — or pass the configuration as a PHP array',
        );
    }

    private static function walk(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::walk($item), $value);
        }

        return is_string($value) ? self::expand($value) : $value;
    }

    private static function expand(string $value): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)(?::-(.*?))?\}/',
            static function (array $match): string {
                $name = $match[1];
                $resolved = getenv($name);

                if (false === $resolved || '' === $resolved) {
                    $resolved = $_ENV[$name] ?? $_SERVER[$name] ?? false;
                }

                if (false !== $resolved && '' !== $resolved) {
                    return (string) $resolved;
                }

                if (isset($match[2])) {
                    return $match[2];
                }

                throw new \RuntimeException(sprintf(
                    'the configuration references ${%s}, which is not set — export it, '
                    . 'or give a fallback as ${%s:-value}',
                    $name,
                    $name,
                ));
            },
            $value,
        );
    }
}
