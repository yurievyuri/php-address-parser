<?php

declare(strict_types=1);

namespace Address\Parser\Tests;

use Address\Parser\Config\ParserFactory;
use Address\Parser\Log\EventCollector;
use Address\Parser\Log\FileLogger;
use Address\Parser\Log\LogRecord;
use Address\Parser\Log\NotifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * Observability is a feature here, not an afterthought: a batch that quietly degraded to
 * rule-based results has to be discoverable without reading a log file.
 */
#[CoversClass(FileLogger::class)]
#[CoversClass(EventCollector::class)]
#[CoversClass(ParserFactory::class)]
final class LoggingTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/address-parser-test/' . bin2hex(random_bytes(4)) . '/parser.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
            @rmdir(\dirname($this->logPath));
        }
    }

    public function testTheFileLoggerCreatesItsDirectoryAndWritesOneJsonObjectPerLine(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::INFO, 'address');
        $logger->info('first', ['address' => 'Baker Street']);
        $logger->error('second', ['error' => new \RuntimeException('boom')]);

        $lines = array_filter(explode("\n", (string) file_get_contents($this->logPath)));
        self::assertCount(2, $lines);

        $first = json_decode((string) reset($lines), true);
        self::assertSame('address', $first['channel']);
        self::assertSame('info', $first['level']);
        self::assertSame('Baker Street', $first['context']['address']);

        $second = json_decode((string) end($lines), true);
        self::assertSame('boom', $second['context']['error']['message'], 'an exception must survive as data');
    }

    public function testEventsBelowTheConfiguredLevelAreNotWritten(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::ERROR);
        $logger->debug('noise');
        $logger->warning('still noise');
        $logger->error('signal');

        $lines = array_filter(explode("\n", (string) file_get_contents($this->logPath)));
        self::assertCount(1, $lines);
        self::assertStringContainsString('signal', (string) reset($lines));
    }

    public function testLoggingFailureCannotBreakParsing(): void
    {
        // A path that cannot be created — a full disk or a read-only mount, in miniature.
        $logger = new FileLogger('/proc/definitely-not-writable/parser.log');
        $logger->error('this must not throw');

        self::expectNotToPerformAssertions();
    }

    public function testTheCollectorKeepsEventsAndFiltersThemBySeverity(): void
    {
        $collector = new EventCollector(minLevel: LogLevel::WARNING);
        $collector->debug('ignored');
        $collector->warning('a warning');
        $collector->critical('a crisis');

        self::assertCount(2, $collector->records());
        self::assertCount(1, $collector->criticalRecords());
        self::assertSame('a crisis', $collector->criticalRecords()[0]->message);
        self::assertTrue($collector->hasProblems());
    }

    public function testTheCollectorPassesEventsThroughToTheRealLogger(): void
    {
        $collector = new EventCollector(new FileLogger($this->logPath, LogLevel::DEBUG));
        $collector->error('written and kept');

        self::assertStringContainsString('written and kept', (string) file_get_contents($this->logPath));
        self::assertCount(1, $collector->records());
    }

    public function testSevereEventsReachTheNotifierAsTheyHappen(): void
    {
        $notifier = new class implements NotifierInterface {
            /** @var list<LogRecord> */
            public array $sent = [];

            public function notify(LogRecord $record): void
            {
                $this->sent[] = $record;
            }
        };

        $collector = new EventCollector(minLevel: LogLevel::WARNING, notifier: $notifier, notifyLevel: LogLevel::CRITICAL);
        $collector->warning('not worth waking anyone');
        $collector->critical('worth waking someone');

        self::assertCount(1, $notifier->sent);
        self::assertSame('worth waking someone', $notifier->sent[0]->message);
    }

    public function testAFailingNotifierCannotBreakTheWorkItReportsOn(): void
    {
        $notifier = new class implements NotifierInterface {
            public function notify(LogRecord $record): void
            {
                throw new \RuntimeException('alerting is down');
            }
        };

        $collector = new EventCollector(notifier: $notifier);
        $collector->critical('a crisis');

        self::assertCount(1, $collector->criticalRecords());
    }

    public function testTheCollectorDoesNotGrowWithoutBound(): void
    {
        $collector = new EventCollector(minLevel: LogLevel::WARNING, limit: 10);

        for ($i = 0; $i < 50; ++$i) {
            $collector->error('event ' . $i);
        }

        self::assertCount(10, $collector->records());
        self::assertSame('event 49', $collector->records()[9]->message, 'the most recent events are the ones kept');
    }

    public function testEveryServiceFailingIsReportedAsCritical(): void
    {
        $factory = new ParserFactory();
        $parser = $factory->create([
            'logging' => ['collect' => ['min_level' => 'warning']],
            'services' => [[
                'class' => ExplodingRefiner::class,
            ]],
        ]);

        $parser->parse('Friedrichstrasse 43, Berlin, 10117');

        $critical = $factory->events()?->criticalRecords() ?? [];
        self::assertCount(1, $critical, 'total loss of the escalation path must be critical, not just an error');
        self::assertStringContainsString('degraded to rules only', $critical[0]->message);
    }

    public function testASuccessfulEscalationIsRecorded(): void
    {
        $factory = new ParserFactory();
        $parser = $factory->create([
            'logging' => ['collect' => ['min_level' => 'info']],
            'services' => [['class' => StubRefiner::class, 'countryCode' => 'DE']],
        ]);

        $parser->parse('Friedrichstrasse 43, Berlin, 10117');

        $refined = array_values(array_filter(
            $factory->events()?->records() ?? [],
            static fn ($r): bool => 'address refined' === $r->message,
        ));

        self::assertCount(1, $refined, 'paying for a service must leave a trace');
        self::assertSame('stub', $refined[0]->context['service']);
        self::assertSame(['country_missing'], $refined[0]->context['resolved']);
    }

    public function testConfigurationBuildsAFileLoggerAtTheGivenPath(): void
    {
        $factory = new ParserFactory();
        $factory->create([
            'logging' => ['path' => $this->logPath, 'level' => 'debug'],
            'services' => [],
        ])->parse('15 Davies Street, London, W1K 3DE');

        self::assertFileExists($this->logPath);
        self::assertStringContainsString('address parsed', (string) file_get_contents($this->logPath));
    }
}

/**
 * A service that is always down — the shape of a provider outage.
 */
final class ExplodingRefiner implements \Address\Parser\Refiner\RefinerInterface
{
    public function name(): string
    {
        return 'exploding';
    }

    public function refine(string $address, \Address\Parser\ParsedAddress $draft, array $issues, bool $spaceInPostCode = false): \Address\Parser\ParsedAddress
    {
        throw new \RuntimeException('service unavailable');
    }
}
