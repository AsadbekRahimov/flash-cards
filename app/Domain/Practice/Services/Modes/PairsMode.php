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
 * "Find the pairs" (docs/16 §4.3, §4.4, §5.5). A SKELETON of task 18.1: the mode is not playable yet - it reports no
 * material, so the hub shows it as "not enough" and opening a session answers 422. Task 18.2 replaces this file whole.
 */
final class PairsMode implements PracticeModeHandler
{
    public function mode(): PracticeMode
    {
        return PracticeMode::Pairs;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        return ModeAvailability::unavailable(max(1, (int) config('practice.solo.pairs.min_per_round')));
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        throw new LogicException('PairsMode::build arrives in 18.2.');
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        throw new LogicException('PairsMode::project arrives in 18.2.');
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        throw new LogicException('PairsMode::grade arrives in 18.2.');
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        throw new LogicException('PairsMode::mistakeLabel arrives in 18.2.');
    }
}
