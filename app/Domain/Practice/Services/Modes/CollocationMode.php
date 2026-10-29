<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Learner;
use LogicException;

/**
 * "Pick the collocation" (docs/16 §5.4). A SKELETON of task 18.1: the mode is not playable yet - it reports no material,
 * so the hub shows it as "not enough" and opening a session answers 422. Task 18.3 replaces this file whole.
 */
final class CollocationMode implements PracticeModeHandler
{
    public function mode(): PracticeMode
    {
        return PracticeMode::Collocation;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        return ModeAvailability::unavailable(max(1, (int) config('practice.solo.items.min')));
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        throw new LogicException('CollocationMode::build arrives in 18.3.');
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        throw new LogicException('CollocationMode::project arrives in 18.3.');
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        throw new LogicException('CollocationMode::grade arrives in 18.3.');
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        throw new LogicException('CollocationMode::mistakeLabel arrives in 18.3.');
    }
}
