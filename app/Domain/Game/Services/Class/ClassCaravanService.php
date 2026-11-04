<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Class;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Game\DTO\ClassContribution;
use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Game\Support\BossWordPicker;
use App\Domain\Learning\Services\TeacherAuthority;
use App\Domain\Operations\Support\PureCache;
use App\Jobs\PostClassCaravanSummaryJob;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The caravan of a class (docs/18 §2.8, §12.11, G2.9a): a teacher sends the whole group on a week-long caravan; every finished run of
 * a member moves it; the boss of the class is made of the hardest words of the class and fought once per member; the week ends by the
 * clock and its summary goes to the chat exactly once (`PostClassCaravanSummaryJob`).
 *
 *   - "one active caravan per group" is a fact of the database: `active_group_id` (= the group while `active`) is UNIQUE; the loser
 *     of two starts at once gets 409 `class_caravan_active` from the unique violation, after a courtesy check that answers the same;
 *   - the contribution of a run is written in the run's own transaction of the finish ({@see contribute()}, called by RunFinisher as
 *     its last step) under the caravan's row taken FOR UPDATE FIRST, so that "the caravan is still on" and the contribution are one
 *     atomic fact (docs/18 §12.5.1 rule 21); nothing that holds a member's row ever waits for the caravan, so there is no cycle;
 *   - the close ({@see closeDue()}) and the cancel hold the one row of the caravan and nothing else; the message to Telegram is sent
 *     by the job, outside any transaction.
 *
 * The bot commands, the `/org` page, the API and the Mini App (G2.9b) and the admin resource (G2.11) are thin callers of this service.
 */
final class ClassCaravanService
{
    /** The pure cache of the standings of an active caravan (docs/18 §12.12): arrays, 60 s, TTL only; a finished caravan reads the database. */
    public const CACHE_PREFIX = 'game:class:v1:';

    public const STANDINGS_TTL = 60;

    /** The cache lock of the summary job (docs/18 §12.11): one message per caravan whatever the number of workers. */
    public const SUMMARY_LOCK_PREFIX = 'game:class-summary:';

    public const CANCELED_BY_TEACHER = 'teacher';

    public const CANCELED_BY_ADMIN = 'admin';

    public const SUMMARY_SKIPPED_GROUP_INACTIVE = 'group_inactive';

    public function __construct(
        private readonly TeacherAuthority $authority,
        private readonly CourseCatalogService $catalog,
        private readonly GameAccess $access,
        private readonly ProductEventRecorder $events,
        private readonly BossWordPicker $picker,
    ) {}

    // ------------------------------------------------------------------ the teacher

    /**
     * Starts the caravan of the group for a week (docs/18 §12.11): the course of the group, the active members at the start, the target
     * of stars, the boss words of the class fixed now. One INSERT; two teachers at the same instant - one caravan, the other a 409.
     *
     * @throws GameException class_caravan_forbidden {reason: not_teacher | group_inactive | centers_only} | class_caravan_active
     */
    public function start(TelegramGroup $group, User $by, ?CarbonImmutable $now = null): ClassCaravan
    {
        $now ??= CarbonImmutable::now();
        $this->assertMayRun($group, $by);
        if ($group->status !== 'active') {
            throw GameException::classCaravanForbidden(GameException::REASON_GROUP_INACTIVE);
        }
        if ((bool) config('game.class.centers_only', true) && ! $this->belongsToLiveCenter($group, $now)) {
            throw GameException::classCaravanForbidden(GameException::REASON_CENTERS_ONLY);
        }
        if ($this->activeOfGroup($group) !== null) {
            throw GameException::classCaravanActive(); // the courtesy check; the unique index below is the decision
        }

        $course = $this->catalog->courseForGroup($group);
        $members = $this->activeMemberIds($group->id);
        $target = max($this->classConfig('min_target', 12), count($members) * $this->classConfig('stars_per_member', 6));

        try {
            $caravan = ClassCaravan::query()->create([
                'telegram_group_id' => $group->id,
                'organization_id' => $group->organization_id,
                'course_id' => $course->id,
                'started_by_user_id' => $by->id,
                'active_group_id' => $group->id,
                'status' => ClassCaravanStatus::Active,
                'starts_at' => $now,
                'ends_at' => $now->addDays($this->classConfig('days', 7)),
                'target_stars' => $target,
                'stars_total' => 0,
                'runs_total' => 0,
                'boss_snapshot_words' => $this->picker->pickForGroup($group->id, $course->id, max(1, (int) config('game.boss.size', 12))),
                'boss_unlocked_at' => null,
                'boss_defeated_at' => null,
                'summary_posted_at' => null,
                'finished_at' => null,
                'meta' => ['members_at_start' => count($members)],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw GameException::classCaravanActive(); // the other teacher's start has just committed
        }

        $this->events->record(ProductEventName::GameClassStarted, [
            'class_caravan_id' => $caravan->id,
            'group_id' => $group->id,
            'members' => count($members),
            'target' => $target,
        ], group: $group, user: $by);

        return $caravan;
    }

    /**
     * Cancels the caravan (docs/18 §12.11): no summary, the runs of the members stay their own. A caravan that is not active any more is
     * returned as it is (a second `/cancel_caravan`, the scheduler that closed it a moment ago).
     *
     * @throws GameException class_caravan_forbidden {reason: not_teacher}
     */
    public function cancel(ClassCaravan $caravan, User $by, ?CarbonImmutable $now = null): ClassCaravan
    {
        $now ??= CarbonImmutable::now();
        $group = $caravan->group ?? throw GameException::classCaravanNotFound();
        $this->assertMayRun($group, $by);
        $reason = $this->authority->canTeach($by, $group) ? self::CANCELED_BY_TEACHER : self::CANCELED_BY_ADMIN;

        $outcome = DB::transaction(function () use ($caravan, $by, $reason, $now): ClassCaravan {
            /** @var ClassCaravan $locked */
            $locked = ClassCaravan::query()->whereKey($caravan->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => ClassCaravanStatus::Canceled,
                'active_group_id' => null,
                'finished_at' => $now,
                'meta' => [
                    ...$locked->meta,
                    'canceled_reason' => $reason,
                    'canceled_by_user_id' => $by->id,
                ],
            ])->save();

            DB::afterCommit(fn () => $this->forgetStandings($locked->id));

            return $locked;
        });

        return $outcome;
    }

    /** The active caravan of the group, if any (`/cancel_caravan`, the `/org` page, the courtesy check of the start). */
    public function activeOfGroup(TelegramGroup|int $group): ?ClassCaravan
    {
        /** @var ClassCaravan|null $caravan */
        $caravan = ClassCaravan::query()->where('active_group_id', $group instanceof TelegramGroup ? $group->id : $group)->first();

        return $caravan;
    }

    // ------------------------------------------------------------------ the scheduler

    /**
     * `game:class-caravans-close` (every 15 minutes): every active caravan past its `ends_at` becomes `finished` in a transaction of its
     * own (the row FOR UPDATE, read again), and its summary is queued after the commit. A caravan that fails is reported and the sweep
     * goes on (docs/18 §12.5.1 rule 23).
     *
     * @return int caravans closed by this call
     */
    public function closeDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $closed = 0;

        /** @var list<int> $ids */
        $ids = ClassCaravan::query()
            ->where('status', ClassCaravanStatus::Active->value)
            ->where('ends_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($ids as $id) {
            try {
                if ($this->finishOne($id, $now)) {
                    $closed++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $closed;
    }

    /** One caravan: `finished` under its lock; the cache, the event and the summary job after the commit. True when this call closed it. */
    private function finishOne(int $id, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($id, $now): bool {
            /** @var ClassCaravan|null $caravan */
            $caravan = ClassCaravan::query()->whereKey($id)->where('status', ClassCaravanStatus::Active->value)->lockForUpdate()->first();
            if ($caravan === null) {
                return false; // canceled, or closed by the other scheduler, between the listing and the lock
            }

            $caravan->forceFill([
                'status' => ClassCaravanStatus::Finished,
                'active_group_id' => null,
                'finished_at' => $now,
            ])->save();

            DB::afterCommit(function () use ($caravan): void {
                $this->forgetStandings($caravan->id);
                $this->events->record(ProductEventName::GameClassFinished, [
                    'class_caravan_id' => $caravan->id,
                    'group_id' => $caravan->telegram_group_id,
                    'stars_total' => $caravan->stars_total,
                    'runs_total' => $caravan->runs_total,
                    'boss_defeated' => $caravan->boss_defeated_at !== null,
                ], group: $caravan->group);
                PostClassCaravanSummaryJob::dispatch($caravan->id);
            });

            return true;
        });
    }

    // ------------------------------------------------------------------ the runs

    /**
     * The caravan a run of the learner joins at its start (docs/18 §12.5.3 step 6): the active caravan of an active group the learner is
     * an active member of, inside its window - one read, no lock. A learner in two such groups joins the caravan that started first.
     */
    public function activeIdFor(Learner $learner, CarbonImmutable $now): ?int
    {
        $id = $this->activeCaravansOf($learner->id, $now)->orderBy('cc.starts_at')->orderBy('cc.id')->value('cc.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The contribution of a finished run (docs/18 §12.5.3 step 6), INSIDE the transaction of the finish, last: the caravan's row FOR
     * UPDATE first - read again as `active` and inside its window (a caravan closed, canceled or past its end since the start of the run
     * gets nothing) - then the member's row, then the totals. A `class_boss` victory marks the member and, when the wins reach half of
     * the participants, the caravan. Null when the run is in no caravan or the caravan is over.
     */
    public function contribute(GameRun $run, int $stars, bool $bossWon, CarbonImmutable $now): ?ClassContribution
    {
        $id = $run->class_caravan_id;
        if ($id === null) {
            return null;
        }

        /** @var ClassCaravan|null $caravan */
        $caravan = ClassCaravan::query()->whereKey($id)->where('status', ClassCaravanStatus::Active->value)->lockForUpdate()->first();
        if ($caravan === null || $caravan->starts_at->gt($now) || $caravan->ends_at->lte($now)) {
            return null;
        }

        $won = $bossWon && $run->kind === RunKind::ClassBoss;
        $this->upsertMember($caravan->id, $run->learner_id, $stars, $run->score, $won, $now);

        $starsTotal = $caravan->stars_total + $stars;
        $bossUnlocked = $caravan->boss_unlocked_at === null && $starsTotal >= $caravan->target_stars;
        $bossDefeated = false;
        if ($won && $caravan->boss_defeated_at === null) {
            $row = DB::table('class_caravan_members')
                ->where('class_caravan_id', $caravan->id)
                ->selectRaw('COUNT(*) AS participants, COUNT(boss_won_at) AS wins')
                ->first();
            $participants = (int) ($row->participants ?? 0);
            $wins = (int) ($row->wins ?? 0);
            $bossDefeated = $participants > 0 && $wins >= (int) ceil($participants / 2);
        }

        $caravan->forceFill([
            'stars_total' => $starsTotal,
            'runs_total' => $caravan->runs_total + 1,
            'boss_unlocked_at' => $bossUnlocked ? $now : $caravan->boss_unlocked_at,
            'boss_defeated_at' => $bossDefeated ? $now : $caravan->boss_defeated_at,
        ])->save();

        return new ClassContribution(
            caravanId: $caravan->id,
            stars: $stars,
            starsTotal: $starsTotal,
            targetStars: $caravan->target_stars,
            runsTotal: $caravan->runs_total,
            bossUnlocked: $bossUnlocked,
            bossDefeated: $bossDefeated,
        );
    }

    /**
     * The member's row: one UPDATE; when the learner has no row yet (the first finished run in the caravan) it is made with the values.
     * The row is written only by the learner's own finishes (serial: `active_learner_id`), so UPDATE-then-INSERT cannot lose a run.
     */
    private function upsertMember(int $caravanId, int $learnerId, int $stars, int $score, bool $won, CarbonImmutable $now): void
    {
        $sql = 'UPDATE class_caravan_members SET runs = runs + 1, stars = stars + ?, best_score = CASE WHEN best_score < ? THEN ? ELSE best_score END, last_run_at = ?, updated_at = ?'
            .($won ? ', boss_won_at = COALESCE(boss_won_at, ?)' : '')
            .' WHERE class_caravan_id = ? AND learner_id = ?';
        $bindings = [$stars, $score, $score, $now, $now, ...($won ? [$now] : []), $caravanId, $learnerId];

        if (DB::update($sql, $bindings) > 0) {
            return;
        }

        $inserted = ClassCaravanMember::query()->insertOrIgnore([
            'class_caravan_id' => $caravanId,
            'learner_id' => $learnerId,
            'runs' => 1,
            'stars' => $stars,
            'best_score' => $score,
            'boss_won_at' => $won ? $now : null,
            'last_run_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            DB::update($sql, $bindings); // somebody made the row between the UPDATE and the INSERT: it takes the run after all
        }
    }

    // ------------------------------------------------------------------ the boss of the class (the fight itself: ClassBossService)

    /** `game_runs.class_boss_key` of the one fight of a member (docs/18 §12.2.8). */
    public static function classBossKey(ClassCaravan $caravan, Learner $learner): string
    {
        return "{$caravan->id}:{$learner->id}";
    }

    /**
     * May the learner fight the boss of the class now (docs/18 §12.11): the caravan is active and inside its window and the learner a
     * member of its group (else 404), the boss is open, the learner has not fought yet, the snapshot has enough words.
     *
     * @throws GameException game_unavailable | class_caravan_not_found | class_boss_locked | class_boss_done | game_not_enough_material
     */
    public function assertClassBossAllowed(ClassCaravan $caravan, Learner $learner, CarbonImmutable $now): void
    {
        $this->access->assertAvailable($learner);
        if (! $caravan->isActive() || $caravan->starts_at->gt($now) || $caravan->ends_at->lte($now) || ! $this->isVisibleTo($caravan, $learner)) {
            throw GameException::classCaravanNotFound();
        }
        if ($caravan->boss_unlocked_at === null) {
            throw GameException::classBossLocked();
        }
        if (GameRun::query()->where('class_boss_key', self::classBossKey($caravan, $learner))->exists()) {
            throw GameException::classBossDone();
        }
        $required = max(1, (int) config('game.boss.min_words', 8));
        $words = count($caravan->boss_snapshot_words ?? []);
        if ($words < $required) {
            throw GameException::notEnoughMaterial($words, $required, 'no_words');
        }
    }

    // ------------------------------------------------------------------ the learner (G2.9b: `GET /game/class`, the map, the Mini App)

    /**
     * The caravans the learner may see (docs/18 §12.11): `active` and `finished` ones of the active groups the learner is an active
     * member of, the newest first.
     *
     * @return Collection<int, ClassCaravan>
     */
    public function visibleFor(Learner $learner, int $limit = 10): Collection
    {
        $this->access->assertAvailable($learner);

        return $this->visibleQuery($learner)->orderByDesc('starts_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * One caravan by id, for a learner who may see it (a stranger's, or a canceled one, looks like a missing one).
     *
     * @throws GameException game_unavailable | class_caravan_not_found
     */
    public function find(Learner $learner, int $id): ClassCaravan
    {
        $this->access->assertAvailable($learner);

        /** @var ClassCaravan|null $caravan */
        $caravan = $this->visibleQuery($learner)->whereKey($id)->first();

        return $caravan ?? throw GameException::classCaravanNotFound();
    }

    /**
     * The table of contributions, best first (docs/18 §12.12): arrays of scalars, cached for 60 s while the caravan is active; a finished
     * or canceled caravan reads the database (its rows do not change any more).
     *
     * @return list<array{learner_id: int, name: string, stars: int, runs: int, best_score: int, boss_won: bool}>
     */
    public function standings(ClassCaravan $caravan): array
    {
        if (! $caravan->isActive()) {
            return $this->computeStandings($caravan->id);
        }

        /** @var list<array{learner_id: int, name: string, stars: int, runs: int, best_score: int, boss_won: bool}> */
        return PureCache::store()->remember(self::standingsKey($caravan->id), self::STANDINGS_TTL, fn (): array => $this->computeStandings($caravan->id));
    }

    /** @return array{learner_id: int, name: string, stars: int, runs: int, best_score: int, boss_won: bool}|null the learner's own row of the standings */
    public function myStanding(ClassCaravan $caravan, Learner $learner): ?array
    {
        foreach ($this->standings($caravan) as $row) {
            if ($row['learner_id'] === $learner->id) {
                return $row;
            }
        }

        return null;
    }

    public static function standingsKey(int $caravanId): string
    {
        return self::CACHE_PREFIX.$caravanId.':standings';
    }

    public function forgetStandings(int $caravanId): void
    {
        PureCache::store()->forget(self::standingsKey($caravanId));
    }

    // ------------------------------------------------------------------ reads

    /** @return list<array{learner_id: int, name: string, stars: int, runs: int, best_score: int, boss_won: bool}> */
    private function computeStandings(int $caravanId): array
    {
        $rows = DB::table('class_caravan_members as m')
            ->join('learners as l', 'l.id', '=', 'm.learner_id')
            ->where('m.class_caravan_id', $caravanId)
            ->orderByDesc('m.stars')
            ->orderByDesc('m.runs')
            ->orderBy('m.id')
            ->get(['m.learner_id', 'l.first_name', 'm.stars', 'm.runs', 'm.best_score', 'm.boss_won_at'])
            ->map(static fn (object $row): array => [
                'learner_id' => (int) $row->learner_id,
                'name' => is_string($row->first_name) && $row->first_name !== '' ? $row->first_name : '—',
                'stars' => (int) $row->stars,
                'runs' => (int) $row->runs,
                'best_score' => (int) $row->best_score,
                'boss_won' => $row->boss_won_at !== null,
            ])
            ->values()
            ->all();

        return array_values($rows);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ClassCaravan> the caravans of the active groups the learner is an active member of */
    private function visibleQuery(Learner $learner): \Illuminate\Database\Eloquent\Builder
    {
        return ClassCaravan::query()
            ->whereIn('status', [ClassCaravanStatus::Active->value, ClassCaravanStatus::Finished->value])
            ->whereExists(fn (Builder $q) => $q->from('group_members as gm')
                ->join('telegram_groups as tg', 'tg.id', '=', 'gm.telegram_group_id')
                ->whereColumn('gm.telegram_group_id', 'class_caravans.telegram_group_id')
                ->where('gm.learner_id', $learner->id)
                ->where('gm.status', 'active')
                ->where('tg.status', 'active'));
    }

    private function isVisibleTo(ClassCaravan $caravan, Learner $learner): bool
    {
        return $this->visibleQuery($learner)->whereKey($caravan->id)->exists();
    }

    /** The active caravans of the learner's active groups inside their window (`cc`), the query the start of a run reads. */
    private function activeCaravansOf(int $learnerId, CarbonImmutable $now): Builder
    {
        return DB::table('class_caravans as cc')
            ->join('group_members as gm', function (JoinClause $join) use ($learnerId): void {
                $join->on('gm.telegram_group_id', '=', 'cc.telegram_group_id')->where('gm.learner_id', $learnerId)->where('gm.status', 'active');
            })
            ->join('telegram_groups as tg', function (JoinClause $join): void {
                $join->on('tg.id', '=', 'cc.telegram_group_id')->where('tg.status', 'active');
            })
            ->where('cc.status', ClassCaravanStatus::Active->value)
            ->where('cc.starts_at', '<=', $now)
            ->where('cc.ends_at', '>', $now);
    }

    /** @return list<int> */
    private function activeMemberIds(int $groupId): array
    {
        return array_values(DB::table('group_members')
            ->where('telegram_group_id', $groupId)
            ->where('status', 'active')
            ->orderBy('learner_id')
            ->pluck('learner_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    // ------------------------------------------------------------------ rights

    /** @throws GameException class_caravan_forbidden {reason: not_teacher} */
    private function assertMayRun(TelegramGroup $group, User $by): void
    {
        if (! $this->isAdmin($by) && ! $this->authority->canTeach($by, $group)) {
            throw GameException::classCaravanForbidden(GameException::REASON_NOT_TEACHER);
        }
    }

    private function isAdmin(User $user): bool
    {
        return $user->is_active === true && $user->hasRole('admin');
    }

    /** The group belongs to an active center with a live subscription (docs/18 §12.11, §12.16 В3; the rule of the seats, docs/13 §6.1). */
    private function belongsToLiveCenter(TelegramGroup $group, CarbonImmutable $now): bool
    {
        $organization = $group->organization_id === null ? null : $group->organization;
        if ($organization === null || ! $organization->isActive()) {
            return false;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('subscriber_type', 'organization')
            ->where('subscriber_id', $organization->id)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->orderBy('id')
            ->first();

        return $subscription !== null && SubscriptionLiveness::isLive($subscription, $now);
    }

    private function classConfig(string $key, int $default): int
    {
        return max(1, (int) config("game.class.{$key}", $default));
    }
}
