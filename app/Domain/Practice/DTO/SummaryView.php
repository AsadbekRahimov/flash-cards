<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * The summary of a finished solo session (docs/16 §6.4). `mismatches` is null outside pairs; `mistakes` is one row per
 * thing to repeat (pairs: one per word of `wrong_left`, `label` = "mother — мама").
 */
final readonly class SummaryView
{
    /** @param list<array{element_id: string, kind: string, label: string, your_answer: string|null, verdict: string}> $mistakes */
    public function __construct(
        public int $total,
        public int $answered,
        public int $correctFirst,
        public int $typos,
        public int $hints,
        public ?int $mismatches,
        public int $durationMs,
        public string $finishReason,
        public bool $retryAvailable,
        public array $mistakes,
    ) {}
}
