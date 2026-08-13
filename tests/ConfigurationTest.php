<?php

declare(strict_types=1);

namespace Address\Parser\Tests;

use Address\Parser\Config\Configuration;
use Address\Parser\Config\ParserFactory;
use Address\Parser\ParsedAddress;
use Address\Parser\Refiner\RefinerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Configuration is the whole interface for operating the pipeline: order, on/off, and per-service
 * settings. These pin that contract, including the part that keeps secrets out of the file.
 */
#[CoversClass(Configuration::class)]
#[CoversClass(ParserFactory::class)]
final class ConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ADDRESS_PARSER_TEST_KEY');
        unset($_ENV['ADDRESS_PARSER_TEST_KEY']);
    }

    public function testAnEnvironmentReferenceIsResolved(): void
    {
        putenv('ADDRESS_PARSER_TEST_KEY=sk-from-the-environment');

        $resolved = Configuration::resolve([
            'services' => [['service' => 'claude', 'api_key' => '${ADDRESS_PARSER_TEST_KEY}']],
        ]);

        self::assertSame('sk-from-the-environment', $resolved['services'][0]['api_key']);
    }

    public function testAMissingReferenceFailsAtLoadTimeRatherThanInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ADDRESS_PARSER_TEST_KEY/');

        Configuration::resolve(['services' => [['api_key' => '${ADDRESS_PARSER_TEST_KEY}']]]);
    }

    public function testAFallbackIsUsedWhenTheVariableIsUnset(): void
    {
        $resolved = Configuration::resolve([
            'services' => [['endpoint' => '${ADDRESS_PARSER_TEST_KEY:-http://localhost:8080/parse}']],
        ]);

        self::assertSame('http://localhost:8080/parse', $resolved['services'][0]['endpoint']);
    }

    public function testServicesRunInTheOrderTheyAreConfigured(): void
    {
        $order = new \ArrayObject();
        $factory = (new ParserFactory())
            ->register('first', fn (): RefinerInterface => $this->recorder('first', $order))
            ->register('second', fn (): RefinerInterface => $this->recorder('second', $order));

        $parser = $factory->create([
            'services' => [
                ['service' => 'second'],
                ['service' => 'first'],
            ],
        ]);

        $parser->parse('Friedrichstrasse 43, Berlin, 10117');

        self::assertSame(['second', 'first'], $order->getArrayCopy(), 'the configured order is the calling order');
    }

    public function testADisabledServiceIsNeverBuilt(): void
    {
        $factory = (new ParserFactory())->register('exploding', static function (): RefinerInterface {
            throw new \LogicException('a disabled service must not even be constructed');
        });

        $parser = $factory->create([
            'services' => [['service' => 'exploding', 'enabled' => false]],
        ]);

        self::assertSame('Friedrichstrasse 43', $parser->parse('Friedrichstrasse 43, Berlin, 10117')->line1);
    }

    public function testAClassCanBeNamedDirectlyWithoutRegistration(): void
    {
        $parser = (new ParserFactory())->create([
            'services' => [[
                'class' => StubRefiner::class,
                'countryCode' => 'DE',
            ]],
        ]);

        self::assertSame('DE', $parser->parse('Friedrichstrasse 43, Berlin, 10117')->countryCode);
    }

    public function testAnUnknownServiceNameIsRejectedWithTheKnownOnes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown service "nope".*claude/s');

        (new ParserFactory())->create(['services' => [['service' => 'nope']]]);
    }

    public function testAnUnknownIssueNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown issue "typo"/');

        (new ParserFactory())->create(['escalate_on' => ['typo']]);
    }

    public function testTheShippedExampleConfigurationIsValid(): void
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class) && !\function_exists('yaml_parse_file')) {
            self::markTestSkipped('needs symfony/yaml or ext-yaml');
        }

        putenv('ANTHROPIC_API_KEY=sk-test');
        $parser = (new ParserFactory())->createFromFile(__DIR__ . '/../examples/address-parser.yaml');
        putenv('ANTHROPIC_API_KEY');

        self::assertSame('15 Davies Street', $parser->parse('15 Davies Street, London, W1K 3DE')->line1);
    }

    /**
     * @param \ArrayObject<int, string> $order
     */
    private function recorder(string $name, \ArrayObject $order): RefinerInterface
    {
        return new class($name, $order) implements RefinerInterface {
            /** @param \ArrayObject<int, string> $order */
            public function __construct(private readonly string $name, private readonly \ArrayObject $order)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress
            {
                $this->order->append($this->name);

                return $draft;
            }
        };
    }
}

/**
 * A service class named straight from configuration, the way a host project would write one.
 */
final class StubRefiner implements RefinerInterface
{
    public function __construct(private readonly string $countryCode = '')
    {
    }

    public function name(): string
    {
        return 'stub';
    }

    public function refine(string $address, ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): ParsedAddress
    {
        return $draft->with(['country' => 'Germany', 'country_code' => $this->countryCode]);
    }
}
