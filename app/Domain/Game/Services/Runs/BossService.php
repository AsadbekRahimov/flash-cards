<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Game\DTO\RunLimits;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Game\Services\GameLimits;
use App\Domain\Game\Support\BossReadiness;
use App\Domain\Game\Support\BossWordPicker;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\LocaleSet;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The fight with the boss of a city (docs/18 §2, §12.3.1, §12.3.4): `boss.size` of the learner's hardest words of the city, picked
 * by {@see BossWordPicker} and FIXED in the snapshot, the kinds in a circle, no flasks. The start is the transaction of
 * {@see RunService::open()}; the reward, the map and the hooks of a win are {@see RunFinisher}'s (nothing of it is repeated here).
 *
 *   - ready: the same rule as `boss.state` on the map ({@see BossReadiness}) - enough words introduced and enough stops cleared, else
 *     409 `boss_not_ready {introduced, required, cleared_stops, required_stops}`. A defeated boss is fought again without the check
 *     (the city is open; the reward is `boss_repeat`), as long as there are words to fight with;
 *   - the Free plan is {@see GameLimits}: the bosses of the first `boss_stages` cities only (403 `game_pro_required`), and the fight
 *     costs the free run of the day or a ticket like a stop (403 `game_limit_reached`; claimed in {@see RunService::open()}).
 */
final class BossService
{
    public function __construct(
        private readonly GameAccess $access,
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
        private readonly BossWordPicker $picker,
        private readonly RunSnapshotBuilder $snapshots,
        private readonly RunService $runs,
        private readonly GameLimits $limits,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Starts the fight with the boss of the city `$stageId` of the course. Like a stop: an unfinished run of the SAME boss is handed
     * back as it is (a second tab), another run is 409 `game_run_active` unless `$replaceActive` ({@see RunService::open()}).
     *
     * @throws GameException game_unavailable | course_not_found | stage_not_found | stage_not_in_course | game_pro_required |
     *                       boss_not_ready | game_not_enough_material | game_run_active
     */
    public function start(Learner $learner, string $courseSlug, int $stageId, bool $replaceActive = false, ?CarbonImmutable $now = null): GameRun
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);

        $course = $this->catalog->findAvailable($courseSlug, $learner) ?? throw GameException::courseNotFound();
        $stage = Stage::query()->find($stageId) ?? throw GameException::stageNotFound();
        if ($stage->course_id !== $course->id) {
            throw GameException::stageNotInCourse();
        }

        // The same boss already on (a second tab) is the answer, whatever the limits say; another live run is 409.
        $busy = $this->runs->busyForBoss($learner, $stage->id, $replaceActive, $now);
        if ($busy !== null) {
            return $busy;
        }

        // Free fights the bosses of the first `boss_stages` cities only (403 game_pro_required); the fight itself is claimed in open().
        $this->limits->assertBossAllowed($learner, $stage->number, $now);

        ['readiness' => $readiness, 'defeated' => $defeated] = $this->readiness($learner, $stage);
        if (! $defeated && ! $readiness->isReady()) {
            throw GameException::bossNotReady($readiness->introduced, $readiness->required, $readiness->clearedStops, $readiness->requiredStops);
        }

        // The runs of the day used up and no ticket (403 game_limit_reached), before the material is read.
        try {
            $this->limits->assertRunAllowed($learner, RunKind::Boss, $now, $replaceActive);
        } catch (GameException $e) {
            // The limit was read a moment after another tab started this very fight (the run `busyForBoss` saw none of now holds the
            // place of the day): the fight that is on is the answer, whatever the limit says - the one-shot re-read of RunService::start()
            // (docs/18 §12.7.2), on this refusal only. Any other live run, or none, keeps the refusal.
            if ($e->errorCode !== GameException::LIMIT_REACHED || $replaceActive) {
                throw $e;
            }
            try {
                $busy = $this->runs->busyForBoss($learner, $stage->id, false, $now);
            } catch (GameException) {
                throw $e;
            }
            if ($busy !== null) {
                return $busy;
            }

            throw $e;
        }

        $limits = RunLimits::for(RunKind::Boss);
        $wordIds = $this->picker->pick($learner->id, $stage->id, $limits->sizeMax);
        if (count($wordIds) < $limits->minElements) {
            // A boss beaten long ago whose words are gone (deactivated) has nothing to fight with: not ready again.
            if ($defeated) {
                throw GameException::bossNotReady(count($wordIds), $readiness->required, $readiness->clearedStops, $readiness->requiredStops);
            }

            throw $this->notEnough($learner, $course, GameException::notEnoughMaterial(count($wordIds), $limits->minElements, 'no_words'));
        }

        $locales = new LocaleSet([$this->locales->forLearner($learner)]);
        try {
            $snapshot = $this->snapshots->forBoss($course, $stage, $wordIds, $locales);
        } catch (GameException $e) {
            throw $e->errorCode === GameException::NOT_ENOUGH_MATERIAL ? $this->notEnough($learner, $course, $e) : $e;
        }

        return $this->runs->open($learner, $snapshot, ['course_id' => $course->id, 'stage_id' => $stage->id], $replaceActive, $now);
    }

    /**
     * The readiness of the boss of the city and whether it has been beaten, from ONE statement: the lessons of the module with active
     * words, the learner's rows of those words counted per lesson, the stars of the stop and the city's `boss_defeated_at`.
     *
     * @return array{readiness: BossReadiness, defeated: bool}
     */
    public function readiness(Learner $learner, Stage $stage): array
    {
        $rows = DB::table('lessons')
            ->join('words', function (JoinClause $join): void {
                $join->on('words.lesson_id', '=', 'lessons.id')->where('words.is_active', '=', true);
            })
            ->leftJoin('word_repetitions', function (JoinClause $join) use ($learner): void {
                $join->on('word_repetitions.word_id', '=', 'words.id')->where('word_repetitions.learner_id', '=', $learner->id);
            })
            ->leftJoin('game_stop_progress', function (JoinClause $join) use ($learner): void {
                $join->on('game_stop_progress.lesson_id', '=', 'lessons.id')->where('game_stop_progress.learner_id', '=', $learner->id);
            })
            ->leftJoin('game_city_progress', function (JoinClause $join) use ($learner): void {
                $join->on('game_city_progress.stage_id', '=', 'lessons.stage_id')->where('game_city_progress.learner_id', '=', $learner->id);
            })
            ->where('lessons.stage_id', $stage->id)
            ->groupBy('lessons.id', 'game_stop_progress.stars', 'game_city_progress.boss_defeated_at')
            ->selectRaw('lessons.id as lesson_id, COUNT(word_repetitions.id) as opened, game_stop_progress.stars as stars, game_city_progress.boss_defeated_at as defeated_at')
            ->get();

        $introduced = 0;
        $cleared = 0;
        $defeated = false;
        foreach ($rows as $row) {
            $introduced += (int) $row->opened;
            $cleared += (int) $row->stars >= 1 ? 1 : 0;
            $defeated = $defeated || $row->defeated_at !== null;
        }

        return ['readiness' => BossReadiness::of($introduced, $rows->count(), $cleared), 'defeated' => $defeated];
    }

    /** Records that the boss had too little to be made of and hands the exception back to be thrown. */
    private function notEnough(Learner $learner, Course $course, GameException $e): GameException
    {
        $this->events->record(ProductEventName::GameNotEnoughMaterial, ['kind' => RunKind::Boss->value, 'course_id' => $course->id, 'lesson_id' => null, ...$e->details], learner: $learner);

        return $e;
    }
}
