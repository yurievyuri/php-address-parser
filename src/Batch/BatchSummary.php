<?php

declare(strict_types=1);

namespace Codelot\AddressParser\Batch;

/**
 * What a batch run did: how many addresses, which service answered for them, what is still wrong.
 *
 * The point of collecting this is cost and quality control — a run where the LLM answered for 40%
 * of addresses is a different bill from one where it answered for 4%, and the difference is
 * invisible without counting.
 */
final readonly class BatchSummary
{
    /**
     * @param array<string, int> $bySource  parser or service name => addresses it produced
     * @param array<string, int> $byIssue   issue => addresses still carrying it after the run
     * @param list<string>       $failures  addresses that could not be parsed at all
     */
    public function __construct(
        public int $total = 0,
        public array $bySource = [],
        public array $byIssue = [],
        public array $failures = [],
        public float $seconds = 0.0,
    ) {
    }

    public function clean(): int
    {
        return $this->total - $this->withIssues();
    }

    /**
     * Addresses carrying at least one issue. Not the sum of byIssue — one address can carry
     * several, so adding those up double-counts.
     */
    public function withIssues(): int
    {
        return $this->byIssue['__any__'] ?? 0;
    }

    public function escalated(): int
    {
        $escalated = 0;

        foreach ($this->bySource as $source => $count) {
            if ('rules' !== $source) {
                $escalated += $count;
            }
        }

        return $escalated;
    }

    public function addressesPerSecond(): float
    {
        return $this->seconds > 0.0 ? $this->total / $this->seconds : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'clean' => $this->clean(),
            'with_issues' => $this->withIssues(),
            'escalated' => $this->escalated(),
            'by_source' => $this->bySource,
            'by_issue' => array_diff_key($this->byIssue, ['__any__' => 0]),
            'failed' => count($this->failures),
            'seconds' => round($this->seconds, 2),
            'per_second' => round($this->addressesPerSecond(), 1),
        ];
    }
}
