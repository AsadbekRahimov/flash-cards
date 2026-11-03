<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Game\DTO\RunLimits;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\LocaleSet;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The daily caravan (docs/18 §2.4, §12.3.1, §12.7.2): one run per local day made of the words the learner has to repeat today.
 *
 *   - the pool is {@see DueRepetitionCriteria} (the one definition of "due" of the reminders, `/today` and the map), the hard words
 *     first, then by `next_review_at`, up to `size_max`; fewer than `min_elements` - topped up to `size_min` with the introduced
 *     words that were repeated the longest ago; fewer than `min_elements` even so - 422 `game_not_enough_material {reason: no_words}`;
 *   - one per day is a fact of the database (`game_runs.daily_key = "{learner}:{local date}"`, unique): a second start is 409
 *     `game_daily_done {resets_at}` - before the snapshot is built, or, for the loser of a race, from {@see RunService::open()};
 *   - no limits of the Free plan and no ticket: the caravan is the day's free part of the game (`free_slot` stays NULL);
 *   - the correct answers leave the cards where they are (docs/18 §12.4): nothing of the learning is written here or by the run.
 *
 * {@see state()} is the single reading of the day for the map and for `/today`.
 */
final class DailyCaravanService
{
    public function __construct(
        private readonly GameAccess $access,
        private readonly RunService $runs,
        private readonly RunSnapshotBuilder $snapshots,
        private readonly DueRepetitionCriteria $due,
        private readonly LearnerClock $clock,
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Starts the caravan of the learner's local day.
     *
     * @throws GameException game_unavailable | game_daily_done | game_not_enough_material | game_run_active
     */
    public function start(Learner $learner, bool $replaceActive = false, ?CarbonImmutable $now = null): GameRun
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);

        $dailyKey = $this->dailyKey($learner, $now);
        if (GameRun::query()->where('daily_key', $dailyKey)->exists()) {
            throw $this->done($learner, $now);
        }

        $limits = RunLimits::for(RunKind::Daily);
        $wordIds = $this->poolIds($learner, $limits, $now);
        if (count($wordIds) < $limits->minElements) {
            $e = GameException::notEnoughMaterial(count($wordIds), $limits->minElements, 'no_words');
            $this->events->record(ProductEventName::GameNotEnoughMaterial, ['kind' => RunKind::Daily->value, 'course_id' => null, 'lesson_id' => null, ...$e->details], learner: $learner);

            throw $e;
        }

        $course = $this->course($learner, $wordIds[0]);
        $snapshot = $this->snapshots->forDaily($course, $wordIds, new LocaleSet([$this->locales->forLearner($learner)]));

        $run = $this->runs->open($learner, $snapshot, ['course_id' => $course->id, 'daily_key' => $dailyKey], $replaceActive, $now);
        if (! $run->wasRecentlyCreated) {
            // The other tab of the same moment won: what `open()` hands back is ITS caravan, and the day has only one.
            throw $this->done($learner, $now);
        }

        return $run;
    }

    /**
     * The unfinished run and the state of the day's caravan from ONE statement over the learner's runs: the live one (`active`, not
     * expired) and the caravan of today (any run with today's `daily_key` - the day's caravan is spent however it ended).
     *
     * @param  int  $dueCount  the learner's due words, already counted by the caller (`LearnerStatsService::dueCount`)
     * @return array{active_run: array{id: int, kind: string, progress: array{answered: int, total: int}}|null, daily: array{available: bool, done_today: bool, due_count: int, resets_at: string}}
     */
    public function state(Learner $learner, CarbonImmutable $now, int $dueCount): array
    {
        $bounds = $this->clock->dayBoundsUtc($learner, $now);
        $dailyKey = $learner->id.':'.$bounds->date;

        $rows = DB::table('game_runs')
            ->where('learner_id', $learner->id)
            ->where(function ($q) use ($dailyKey): void {
                $q->where('status', RunStatus::Active->value)->orWhere('daily_key', $dailyKey);
            })
            ->get(['id', 'kind', 'status', 'daily_key', 'answered', 'total', 'expires_at']);

        $active = null;
        $dailyDone = false;
        foreach ($rows as $row) {
            $live = $row->status === RunStatus::Active->value && CarbonImmutable::parse((string) $row->expires_at, 'UTC')->greaterThan($now);
            if ($live) {
                $active = ['id' => (int) $row->id, 'kind' => (string) $row->kind, 'progress' => ['answered' => (int) $row->answered, 'total' => (int) $row->total]];
            } elseif ($row->daily_key === $dailyKey) {
                // finished for any reason, or expired and not yet swept: the day's caravan is spent
                $dailyDone = true;
            }
        }

        return [
            'active_run' => $active,
            'daily' => [
                'available' => ! $dailyDone && $active === null && $dueCount > 0,
                'done_today' => $dailyDone,
                'due_count' => $dueCount,
                'resets_at' => $bounds->endUtc->utc()->toIso8601ZuluString('millisecond'),
            ],
        ];
    }

    /**
     * The block `game` of `GET /today` (docs/04 A.2a): nothing is read while the game is closed.
     *
     * @return array{daily_available: bool, active_run: array{id: int, kind: string, progress: array{answered: int, total: int}}|null}
     */
    public function todayBlock(Learner $learner, CarbonImmutable $now, int $dueCount): array
    {
        if (! $this->access->state($learner)->isAvailable()) {
            return ['daily_available' => false, 'active_run' => null];
        }

        $state = $this->state($learner, $now, $dueCount);

        return ['daily_available' => $state['daily']['available'], 'active_run' => $state['active_run']];
    }

    /**
     * The words of the caravan: the due ones (the hard first, then the longest waiting), topped up when there are too few.
     *
     * @return list<int>
     */
    private function poolIds(Learner $learner, RunLimits $limits, CarbonImmutable $now): array
    {
        /** @var list<int> $ids */
        $ids = $this->due->apply(WordRepetition::query()->where('learner_id', $learner->id), $now)
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->orderBy('id')
            ->limit($limits->sizeMax)
            ->pluck('word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $missing = $limits->sizeMin - count($ids);
        if (count($ids) >= $limits->minElements || $missing <= 0) {
            return $ids;
        }

        // The introduced words repeated the longest ago; those never answered (NULL) after the answered ones.
        $extra = WordRepetition::query()
            ->where('learner_id', $learner->id)
            ->whereNotIn('word_id', $ids)
            ->whereHas('word', fn ($word) => $word->where('is_active', true))
            ->orderByRaw('last_reviewed_at IS NULL')
            ->orderBy('last_reviewed_at')
            ->orderBy('id')
            ->limit($missing)
            ->pluck('word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return [...$ids, ...$extra];
    }

    /** The active course of the learner (the one `/today` shows); without it - the course of the first word of the pool. */
    private function course(Learner $learner, int $firstWordId): Course
    {
        $active = $learner->activeCourse;
        if ($active !== null && $this->catalog->isAvailable($active, $learner)) {
            return $active;
        }

        $courseId = Word::query()
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('words.id', $firstWordId)
            ->value('stages.course_id');

        return Course::query()->whereKey($courseId)->first() ?? throw new LogicException("The word {$firstWordId} belongs to no course.");
    }

    private function dailyKey(Learner $learner, CarbonImmutable $now): string
    {
        return $learner->id.':'.$this->clock->localDate($learner, $now);
    }

    private function done(Learner $learner, CarbonImmutable $now): GameException
    {
        return GameException::dailyDone($this->clock->dayBoundsUtc($learner, $now)->endUtc->utc()->toIso8601ZuluString('millisecond'));
    }
}
