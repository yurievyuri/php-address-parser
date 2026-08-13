<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Log;

/**
 * One collected event: a PSR-3 level, a message, and its context.
 */
final readonly class LogRecord implements \JsonSerializable
{
    public \DateTimeImmutable $time;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $level,
        public string $message,
        public array $context = [],
        ?\DateTimeImmutable $time = null,
    ) {
        $this->time = $time ?? new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'time' => $this->time->format(\DateTimeInterface::ATOM),
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return sprintf('[%s] %s %s', $this->level, $this->message, json_encode($this->context, JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
