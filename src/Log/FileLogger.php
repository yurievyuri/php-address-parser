<?php

declare(strict_types=1);

namespace Address\Parser\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * A PSR-3 logger writing one JSON object per line to a file.
 *
 * Present so the library is observable out of the box: a host application that already has Monolog
 * passes its own logger and this is never constructed, but a project that does not should not have
 * to build one before it can find out why an address parsed badly.
 *
 * One line per event, JSON, append-only — greppable with the tools already on the box, and safe
 * for concurrent PHP-FPM workers because each line is written with a single locked append.
 */
final class FileLogger extends AbstractLogger
{
    /** Severity order, lowest first — everything below the configured level is dropped. */
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

    private readonly int $threshold;

    /**
     * @param string $path      file to append to; parent directories are created
     * @param string $minLevel  PSR-3 level below which events are dropped
     * @param string $channel   written into every line, so one file can hold several sources
     */
    public function __construct(
        private readonly string $path,
        string $minLevel = LogLevel::INFO,
        private readonly string $channel = 'address',
    ) {
        $this->threshold = self::SEVERITY[$minLevel]
            ?? throw new \InvalidArgumentException(sprintf('unknown log level "%s"', $minLevel));
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $severity = self::SEVERITY[(string) $level] ?? null;

        if (null === $severity || $severity < $this->threshold) {
            return;
        }

        $line = json_encode(
            [
                'time' => date('c'),
                'channel' => $this->channel,
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => self::serialisable($context),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (false === $line) {
            return;
        }

        $this->append($line . "\n");
    }

    private function append(string $line): void
    {
        $directory = \dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        // Logging must never take the caller down: a full disk or a read-only mount is a reason to
        // lose a log line, not to fail an address parse.
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function serialisable(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $clean[$key] = match (true) {
                $value instanceof \Throwable => [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile() . ':' . $value->getLine(),
                ],
                $value instanceof \JsonSerializable, is_scalar($value), null === $value, is_array($value) => $value,
                $value instanceof \Stringable => (string) $value,
                default => get_debug_type($value),
            };
        }

        return $clean;
    }
}
