<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Tests;

use Codelot\AddressParser\Config\Configuration;
use Codelot\AddressParser\Config\ParserFactory;
use Codelot\AddressParser\ParsedAddress;
use Codelot\AddressParser\Refiner\RefinerInterface;
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

    /**
     * A PHP file is the recommended format: no extra package, opcached, and values can come from
     * wherever the application already keeps them.
     */
    /**
     * The API has no "effort off" value, so `false` has to mean "leave the parameter out" — Haiku
     * rejects the request outright when it is present.
     */
    public function testEffortFalseIsAcceptedAndMeansOmitted(): void
    {
        $parser = (new ParserFactory())->create([
            'services' => [['service' => 'bedrock', 'effort' => false, 'model' => 'anthropic.claude-haiku-4-5']],
        ]);

        self::assertSame('15 Davies Street', $parser->parse('15 Davies Street, London, W1K 3DE')->line1);
    }

    public function testTheBedrockServiceDefaultsToConverse(): void
    {
        // Converse is the unified shape; picking it by default is what makes a vendor switch a
        // configuration change rather than a new adapter.
        $parser = (new ParserFactory())->create([
            'services' => [['service' => 'bedrock', 'model' => 'eu.amazon.nova-lite-v1:0']],
        ]);

        self::assertSame('15 Davies Street', $parser->parse('15 Davies Street, London, W1K 3DE')->line1);
    }

    public function testAnUnknownBedrockApiIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown bedrock api "soap"/');

        (new ParserFactory())->create([
            'services' => [['service' => 'bedrock', 'api' => 'soap']],
        ]);
    }

    public function testAnUnknownEffortIsRejectedEarly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown effort "turbo"/');

        (new ParserFactory())->create([
            'services' => [['service' => 'bedrock', 'effort' => 'turbo']],
        ]);
    }

    public function testTheShippedPhpConfigurationIsValid(): void
    {
        $parser = (new ParserFactory())->createFromFile(__DIR__ . '/../examples/address-parser.php');

        self::assertSame('15 Davies Street', $parser->parse('15 Davies Street, London, W1K 3DE')->line1);
    }

    public function testAPhpConfigurationCanBuildAServiceFromComputedSettings(): void
    {
        $path = sys_get_temp_dir() . '/address-parser-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, <<<'PHP'
            <?php

            // The shape a host application uses: settings computed at load time from its own
            // environment, with no bridge between that environment and the library.
            $code = strtoupper(substr('de-DE', 0, 2));

            return ['services' => [['class' => \Codelot\AddressParser\Tests\StubRefiner::class, 'countryCode' => $code]]];
            PHP);

        try {
            $parser = (new ParserFactory())->createFromFile($path);

            self::assertSame('DE', $parser->parse('Friedrichstrasse 43, Berlin, 10117')->countryCode);
        } finally {
            unlink($path);
        }
    }

    public function testTheShippedYamlConfigurationIsValid(): void
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class) && !\function_exists('yaml_parse_file')) {
            self::markTestSkipped('needs symfony/yaml or ext-yaml');
        }

        putenv('ANTHROPIC_API_KEY=sk-test');

        try {
            $parser = (new ParserFactory())->createFromFile(__DIR__ . '/../examples/address-parser.yaml');

            self::assertSame('15 Davies Street', $parser->parse('15 Davies Street, London, W1K 3DE')->line1);
        } finally {
            putenv('ANTHROPIC_API_KEY');
        }
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
