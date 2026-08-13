<?php

declare(strict_types=1);

namespace Address\Parser\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Collects what happened during parsing, in memory, alongside normal logging.
 *
 * A log file answers questions afterwards. This answers them *now*: after a batch of addresses the
 * caller can ask what went wrong, filter by PSR-3 severity, and hand the serious part onward —
 * to an alerting channel, a queue, a health endpoint — without grepping anything.
 *
 * It decorates another logger rather than replacing it, so events still reach the file or Monolog.
 * Severe events can also be pushed to a notifier as they happen, which is the difference between
 * "an operator finds out on Monday" and "the provider outage pages someone".
 */
final class EventCollector extends AbstractLogger
{
    private const SEVERITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var list<LogRecord> */
    private array $records = [];

    private readonly int $threshold;

    private readonly ?int $notifyThreshold;

    /**
     * @param LoggerInterface  $inner       events are passed through to this logger unchanged
     * @param string           $minLevel    below this, events are not kept in memory
     * @param NotifierInterface|null $notifier  receives events at or above $notifyLevel as they happen
     * @param string           $notifyLevel severity from which the notifier is called
     * @param int              $limit       cap on retained records, so a long batch cannot exhaust memory
     */
    public function __construct(
        private readonly LoggerInterface $inner = new NullLogger(),
        string $minLevel = LogLevel::WARNING,
        private readonly ?NotifierInterface $notifier = null,
        string $notifyLevel = LogLevel::CRITICAL,
        private readonly int $limit = 1000,
    ) {
        $this->threshold = self::SEVERITY[$minLevel]
            ?? throw new \InvalidArgumentException(sprintf('unknown log level "%s"', $minLevel));
        $this->notifyThreshold = null === $notifier
            ? null
            : (self::SEVERITY[$notifyLevel] ?? throw new \InvalidArgumentException(
                sprintf('unknown log level "%s"', $notifyLevel),
            ));
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context);

        $severity = self::SEVERITY[(string) $level] ?? null;

        if (null === $severity || $severity < $this->threshold) {
            return;
        }

        $record = new LogRecord((string) $level, (string) $message, $context);

        // Keep the most recent: in a long batch the last failures are the ones being investigated.
        if (count($this->records) >= $this->limit) {
            array_shift($this->records);
        }

        $this->records[] = $record;

        if (null !== $this->notifier && null !== $this->notifyThreshold && $severity >= $this->notifyThreshold) {
            try {
                $this->notifier->notify($record);
            } catch (\Throwable $e) {
                // Alerting is not allowed to break the work it reports on.
                $this->inner->error('notifier failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Everything collected, optionally filtered to a minimum severity.
     *
     * @return list<LogRecord>
     */
    public function records(?string $minLevel = null): array
    {
        if (null === $minLevel) {
            return $this->records;
        }

        $threshold = self::SEVERITY[$minLevel]
            ?? throw new \InvalidArgumentException(sprintf('unknown log level "%s"', $minLevel));

        return array_values(array_filter(
            $this->records,
            static fn (LogRecord $r): bool => (self::SEVERITY[$r->level] ?? -1) >= $threshold,
        ));
    }

    /**
     * The subset worth waking someone for.
     *
     * Named `criticalRecords()` rather than `critical()` because PSR-3 already defines
     * `critical($message, $context)` as a way to *write* an event.
     *
     * @return list<LogRecord>
     */
    public function criticalRecords(): array
    {
        return $this->records(LogLevel::CRITICAL);
    }

    public function hasProblems(): bool
    {
        return [] !== $this->records(LogLevel::ERROR);
    }

    public function clear(): void
    {
        $this->records = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(?string $minLevel = null): array
    {
        return array_map(static fn (LogRecord $r): array => $r->toArray(), $this->records($minLevel));
    }
}
