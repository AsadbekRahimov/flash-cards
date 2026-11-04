<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;

/**
 * What a learner sees of a run (docs/18 §12.8, `GET /game/runs/{id}`): the place, the counters, the CURRENT element projected for the
 * viewer's locale (never the snapshot), the event of that element, whether a hint may be bought, the summary once the run is over.
 * `GameRunResource` (G2.2c) wraps {@see toArray()}; `boosters` (G2.5b): what the learner owns while the run is on, and what was used in it.
 */
final readonly class RunView
{
    /**
     * @param  array{element: array<string, mixed>, event: string|null}|null  $current
     * @param  array{price: int, available: bool}  $hint
     * @param  array{available: list<array{key: string, effect: string, qty: int}>, used: list<array{key: string, effect: string, element_id: string|null}>}  $boosters
     * @param  array<string, mixed>|null  $summary
     */
    public function __construct(
        public int $id,
        public RunKind $kind,
        public RunStatus $status,
        public ?RunFinishReason $finishReason,
        public ?string $courseSlug,
        public ?int $lessonId,
        public ?string $city,
        public ?int $stageNumber,
        public ?int $lessonNumber,
        public ?string $lessonTitle,
        public bool $scopeWidened,
        public int $total,
        public int $position,
        public int $flasks,
        public int $flasksMax,
        public int $combo,
        public int $score,
        public ?array $current,
        public array $hint,
        public array $boosters,
        public ?array $summary,
        public string $serverNow,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'finish_reason' => $this->finishReason?->value,
            'course' => $this->courseSlug === null ? null : ['slug' => $this->courseSlug],
            'lesson_id' => $this->lessonId,
            'city' => $this->city,
            'stage_number' => $this->stageNumber,
            'lesson_number' => $this->lessonNumber,
            'lesson_title' => $this->lessonTitle,
            'scope_widened' => $this->scopeWidened,
            'total' => $this->total,
            'position' => $this->position,
            'flasks' => $this->flasks,
            'flasks_max' => $this->flasksMax,
            'combo' => $this->combo,
            'score' => $this->score,
            'current' => $this->current,
            'boosters' => $this->boosters,
            'hint' => $this->hint,
            'summary' => $this->summary,
            'server_now' => $this->serverNow,
        ];
    }
}
