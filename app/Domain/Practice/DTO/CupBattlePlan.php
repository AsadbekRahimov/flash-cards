<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Models\Course;
use App\Models\Learner;

/**
 * A match of a duel of a cup made ready OUTSIDE any transaction ({@see BattleService::prepareForCup()}): the two players, the mode, the
 * course and the snapshot of `practice.battle.rounds` rounds in the languages of both - the heavy read of the material, so that the
 * transaction of the acceptance holds its locks only for the inserts ({@see BattleService::openForCup()}).
 */
final readonly class CupBattlePlan
{
    public function __construct(
        public Learner $challenger,
        public Learner $opponent,
        public PracticeMode $mode,
        public Course $course,
        public Snapshot $snapshot,
        public string $challengerLocale,
        public string $opponentLocale,
    ) {}
}
