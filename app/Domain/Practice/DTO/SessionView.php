<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use Carbon\CarbonImmutable;

/**
 * A solo session as the client may see it (docs/16 §7.2). `current` is what the mode's `project()` made of the current
 * element - the only way an element reaches a response; null once the session is finished, when `summary` is there instead.
 */
final readonly class SessionView
{
    /**
     * @param  array{slug: string, title: string}  $course
     * @param  array{id: int|null, stage: int|null, number: int|null, title: string|null}|null  $lesson
     * @param  array<string, mixed>|null  $current
     */
    public function __construct(
        public int $id,
        public string $status,
        public string $mode,
        public string $source,
        public ?int $retryOf,
        public array $course,
        public ?array $lesson,
        public int $done,
        public int $total,
        public int $correctFirst,
        public int $hints,
        public int $mismatches,
        public CarbonImmutable $expiresAt,
        public CarbonImmutable $serverNow,
        public ?array $current,
        public ?SummaryView $summary,
    ) {}
}
