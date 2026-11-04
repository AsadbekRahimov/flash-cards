<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Class;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Game\Services\Runs\RunSnapshotBuilder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\LocaleSet;
use App\Models\GameRun;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The fight with the boss of a class (docs/18 §12.11, G2.9a): a run `class_boss` on the words fixed in the caravan at its start
 * (`boss_snapshot_words`), no flasks, won at `boss.required_accuracy`, once per member (`game_runs.class_boss_key` is unique). The
 * start is the transaction of {@see RunService::open()}; the reward (`economy.class_boss`), the member's `boss_won_at` and the caravan's
 * `boss_defeated_at` are {@see ClassCaravanService::contribute()}'s, inside the finish. The fight costs no free run and no ticket
 * (`GameLimits` counts `stop` and `boss` only). A service of its own, so that RunService -> ClassCaravanService -> RunService is no cycle.
 */
final class ClassBossService
{
    public function __construct(
        private readonly GameAccess $access,
        private readonly ClassCaravanService $caravans,
        private readonly RunService $runs,
        private readonly RunSnapshotBuilder $snapshots,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @throws GameException game_unavailable | class_caravan_not_found | class_boss_locked | class_boss_done | game_not_enough_material | game_run_active
     */
    public function start(Learner $learner, int $classCaravanId, bool $replaceActive = false, ?CarbonImmutable $now = null): GameRun
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);

        $caravan = $this->caravans->find($learner, $classCaravanId);
        $key = ClassCaravanService::classBossKey($caravan, $learner);

        // The same fight already on (a second tab) is the answer; another live run is 409.
        $busy = $this->runs->busyForClassBoss($learner, $key, $replaceActive, $now);
        if ($busy !== null) {
            return $busy;
        }

        $this->caravans->assertClassBossAllowed($caravan, $learner, $now);

        $course = $caravan->course ?? throw GameException::classCaravanNotFound();
        $locales = new LocaleSet([$this->locales->forLearner($learner)]);
        try {
            $snapshot = $this->snapshots->forClassBoss($course, $caravan->boss_snapshot_words ?? [], $locales);
        } catch (GameException $e) {
            if ($e->errorCode === GameException::NOT_ENOUGH_MATERIAL) {
                $this->events->record(ProductEventName::GameNotEnoughMaterial, ['kind' => RunKind::ClassBoss->value, 'course_id' => $course->id, 'lesson_id' => null, ...$e->details], learner: $learner);
            }

            throw $e;
        }

        // Two tabs at once: open() hands back the fight the other tab has just started (the same key), or says what stands in the way;
        // a second fight after the first is over meets the unique key there and is told class_boss_done.
        return $this->runs->open($learner, $snapshot, ['course_id' => $course->id, 'class_caravan_id' => $caravan->id, 'class_boss_key' => $key], $replaceActive, $now);
    }
}
