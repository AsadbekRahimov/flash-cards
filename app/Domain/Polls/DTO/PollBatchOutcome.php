<?php

declare(strict_types=1);

namespace App\Domain\Polls\DTO;

/**
 * What one {@see \App\Domain\Polls\Services\PollDeliverySender::sendBatch()} did. `more` = the job queues a successor after
 * `delaySeconds` (the batch stopped early: budget spent, no slot, a 429, or simply the batch is done and rows may be left);
 * false = nothing left for this chain (all rows taken by others or finished, the poll stopped / closed / past its deadline).
 */
final readonly class PollBatchOutcome
{
    public function __construct(
        public int $sent = 0,
        public int $skipped = 0,
        public int $forbidden = 0,
        public int $failed = 0,
        public int $unknown = 0,
        public int $released = 0,
        public bool $more = false,
        public int $delaySeconds = 0,
    ) {}

    /** @return array{sent: int, skipped: int, forbidden: int, failed: int, unknown: int, released: int, more: bool, delay: int} */
    public function toArray(): array
    {
        return [
            'sent' => $this->sent,
            'skipped' => $this->skipped,
            'forbidden' => $this->forbidden,
            'failed' => $this->failed,
            'unknown' => $this->unknown,
            'released' => $this->released,
            'more' => $this->more,
            'delay' => $this->delaySeconds,
        ];
    }
}
