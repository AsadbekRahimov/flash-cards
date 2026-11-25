<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\DTO\CupProposal;
use App\Domain\Cup\Enums\CupCancelReason;
use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\CupClock;
use App\Domain\Cup\Support\CupSeeds;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Models\Course;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The life of a cup (docs/33 §3.1): draft → scheduled → running → finished, or canceled from any open state. Every transition runs
 * under `FOR UPDATE` of the row of the cup and re-reads the status and the clock after the lock, so a repeated call, a second
 * scheduler or a click racing the scheduler is a no-op, never a second transition. Calls to Telegram (who is an administrator, is the
 * bot one) happen BEFORE the transaction - no lock is held across the network.
 *
 * Who may do what is decided here, on the server: publishing, the settings and the cancel by the chat ask Telegram about the user id
 * the caller resolved from the signed webhook or the JWT ({@see CupAdminCheck}); a value of the client is never an argument.
 *
 * Lock order of the cup (one for every path, no cycles): `cups → cup_round_attempts → cup_participants → cup_day_scores`. The start
 * and the finish lock the cup first; joining takes `FOR SHARE` of the cup, so a join and a start / finish are ordered, never mixed.
 */
final class CupLifecycleService
{
    public function __construct(
        private readonly CupAdminCheck $admins,
        private readonly GroupBotAccessChecker $botAccess,
        private readonly CupWordPlanner $planner,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
        private readonly CupStandings $standings,
        private readonly CupScoreBook $scores,
    ) {}

    /**
     * "Create a cup" (`cup:new`, `/cup`): any member of the chat; a draft with the default settings, or the cup the chat already has
     * (`created = false`) - two presses at once make one draft (the unique `active_group_id`).
     *
     * @throws CupException cup_unavailable | cup_paused | cup_material_unavailable
     */
    public function propose(TelegramGroup $group, int $proposedByTelegramUserId, ?int $threadId = null, ?CarbonImmutable $now = null): CupProposal
    {
        $now ??= CarbonImmutable::now();
        if (! CupAccess::allowsGroup($group)) {
            throw CupException::unavailable();
        }
        $pause = CupAccess::pauseReason($group);
        if ($pause !== null) {
            throw CupException::paused($pause->value);
        }

        $open = $this->openCupOf($group);
        if ($open !== null) {
            return new CupProposal($open, false);
        }

        $level = (string) config('cup.default_level', 'A2');
        $course = $this->planner->courseForLevel($level) ?? throw CupException::materialUnavailable();

        try {
            $cup = DB::transaction(fn (): Cup => Cup::query()->create([
                'telegram_group_id' => $group->id,
                'active_group_id' => $group->id,
                'public_code' => CupSeeds::newPublicCode(),
                'status' => CupStatus::Draft,
                'course_id' => $course->id,
                'level' => $level,
                'timezone' => (string) config('cup.timezone', 'Asia/Tashkent'),
                'days' => (int) config('cup.days', 7),
                'counted_days' => (int) config('cup.counted_days', 5),
                'round_tasks' => (int) config('cup.round.tasks', 10),
                'duel_tasks' => (int) config('cup.duel.tasks', 5),
                'summary_minute' => (int) config('cup.summary_minute', 1200),
                'locale' => $this->locales->forGroup($group),
                'seed_secret' => CupSeeds::newSecret(),
                'proposed_by_telegram_user_id' => $proposedByTelegramUserId,
                'card_thread_id' => $threadId,
                'meta' => [],
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            $open = $this->openCupOf($group);
            if ($open === null) {
                throw CupException::alreadyActive(0);
            }

            return new CupProposal($open, false);
        }

        $this->events->record(ProductEventName::CupProposed, ['cup_id' => $cup->id], group: $group);

        return new CupProposal($cup, true);
    }

    /**
     * Publishing a draft (docs/33 §3.1, §3.3): only an administrator of the chat (fresh `getChatMember`), only when the bot is an
     * administrator too (В1/К1), only with enough words of the level. The start is the nearest local midnight at least
     * `start_min_lead_hours` away; the sizes of the config are frozen in the row. A published cup is returned as it is (a second
     * press of "Опубликовать" is not an error).
     *
     * @throws CupException cup_unavailable | cup_closed | cup_paused | cup_not_chat_admin | cup_bot_not_admin | cup_unverifiable
     *                      | cup_level_invalid | cup_material_unavailable
     */
    public function publish(Cup $cup, int $telegramUserId, ?string $level = null, ?string $locale = null, ?CarbonImmutable $now = null): Cup
    {
        $now ??= CarbonImmutable::now();
        $group = $cup->telegramGroup;
        if (! CupAccess::allowsGroup($group)) {
            throw CupException::unavailable();
        }
        if ($cup->status === CupStatus::Scheduled || $cup->status === CupStatus::Running) {
            return $cup;
        }
        if ($cup->status !== CupStatus::Draft) {
            throw CupException::closed();
        }
        $pause = CupAccess::pauseReason($group);
        if ($pause !== null) {
            throw CupException::paused($pause->value);
        }

        $level ??= $cup->level;
        $course = $this->courseWithMaterial($level);
        $locale = $locale !== null && $this->locales->isSupported($locale) ? $locale : $cup->locale;

        $this->admins->assertChatAdmin($group, $telegramUserId);
        $botIsAdmin = $this->botAccess->botIsAdmin($group);
        if ($botIsAdmin === false) {
            throw CupException::botNotAdmin();
        }
        if ($botIsAdmin === null) {
            throw CupException::telegramUnavailable();
        }

        /** @var array{0: Cup, 1: bool} $outcome */
        $outcome = DB::transaction(function () use ($cup, $telegramUserId, $level, $course, $locale, $now): array {
            $locked = $this->lock($cup->id);
            $locked->setRelation('telegramGroup', $cup->telegramGroup);
            if ($locked->status === CupStatus::Scheduled || $locked->status === CupStatus::Running) {
                return [$locked, false];
            }
            if ($locked->status !== CupStatus::Draft) {
                throw CupException::closed();
            }

            $timezone = (string) config('cup.timezone', 'Asia/Tashkent');
            $days = (int) config('cup.days', 7);
            $startsAt = CupClock::firstStart($now, $timezone, (int) config('cup.start_min_lead_hours', 6));
            $locked->forceFill([
                'status' => CupStatus::Scheduled,
                'level' => $level,
                'course_id' => $course->id,
                'locale' => $locale,
                'timezone' => $timezone,
                'days' => $days,
                'counted_days' => min($days, (int) config('cup.counted_days', 5)),
                'round_tasks' => (int) config('cup.round.tasks', 10),
                'duel_tasks' => (int) config('cup.duel.tasks', 5),
                'starts_at' => $startsAt,
                'ends_at' => CupClock::endOf($startsAt, $timezone, $days),
                'confirmed_by_telegram_user_id' => $telegramUserId,
                'confirmed_at' => $now,
                'updated_at' => $now,
            ])->save();

            return [$locked, true];
        });

        [$published, $fresh] = $outcome;
        if ($fresh) {
            $this->events->record(ProductEventName::CupPublished, ['cup_id' => $published->id, 'level' => $published->level, 'days' => $published->days], group: $group);
        }

        return $published;
    }

    /**
     * The settings of an administrator of the chat (docs/33 §6.5): level and language only in a draft; the summaries in any open cup.
     *
     * @param  array{level?: string, locale?: string, summaries_enabled?: bool, summary_minute?: int}  $settings
     *
     * @throws CupException cup_closed | cup_paused | cup_not_chat_admin | cup_unverifiable | cup_already_published | cup_level_invalid | cup_material_unavailable
     */
    public function updateSettings(Cup $cup, int $telegramUserId, array $settings, ?CarbonImmutable $now = null): Cup
    {
        $now ??= CarbonImmutable::now();
        if (! $cup->status->isOpen()) {
            throw CupException::closed();
        }
        $level = $settings['level'] ?? null;
        $locale = $settings['locale'] ?? null;
        $course = $level !== null ? $this->courseWithMaterial($level) : null;
        $this->admins->assertChatAdmin($cup->telegramGroup, $telegramUserId);

        return DB::transaction(function () use ($cup, $settings, $level, $locale, $course, $now): Cup {
            $locked = $this->lock($cup->id);
            if (! $locked->status->isOpen()) {
                throw CupException::closed();
            }
            $changes = [];
            if ($level !== null || $locale !== null) {
                if ($locked->status !== CupStatus::Draft) {
                    throw CupException::alreadyPublished();
                }
                if ($course !== null) {
                    $changes += ['level' => $level, 'course_id' => $course->id];
                }
                if ($locale !== null && $this->locales->isSupported($locale)) {
                    $changes['locale'] = $locale;
                }
            }
            if (isset($settings['summaries_enabled'])) {
                $changes['summaries_enabled'] = $settings['summaries_enabled'];
            }
            if (isset($settings['summary_minute'])) {
                $changes['summary_minute'] = max(0, min(1439, $settings['summary_minute']));
            }
            if ($changes !== []) {
                $locked->forceFill($changes + ['updated_at' => $now])->save();
            }
            $locked->setRelation('telegramGroup', $cup->telegramGroup);

            return $locked;
        });
    }

    /**
     * The cancel of an administrator of the chat (callback `cup:cancel:{id}`, Mini App).
     *
     * @throws CupException cup_not_chat_admin | cup_paused | cup_unverifiable
     */
    public function cancelByChatAdmin(Cup $cup, int $telegramUserId, ?CarbonImmutable $now = null): bool
    {
        if (! $cup->status->isOpen()) {
            return false;
        }
        $this->admins->assertChatAdmin($cup->telegramGroup, $telegramUserId);

        return $this->cancel($cup->id, CupCancelReason::ChatAdmin, $now ?? CarbonImmutable::now());
    }

    /** The cancel of an administrator of the platform (`/admin`, K9 - the panel checks the role and writes `audit_logs`). */
    public function cancelByPlatform(Cup $cup, ?CarbonImmutable $now = null): bool
    {
        return $this->cancel($cup->id, CupCancelReason::PlatformAdmin, $now ?? CarbonImmutable::now());
    }

    /** A draft left unpublished for `draft_ttl_hours` (the scheduler). */
    public function expireDraft(int $cupId, CarbonImmutable $now): bool
    {
        $deadline = $now->subHours(max(1, (int) config('cup.draft_ttl_hours', 48)));

        return $this->cancel($cupId, CupCancelReason::DraftExpired, $now, static fn (Cup $cup): bool => $cup->status === CupStatus::Draft && $cup->created_at !== null && $cup->created_at->lte($deadline));
    }

    /**
     * The midnight of day 1 (the scheduler): with two active participants the cup runs - the rows of its days and the plan of its words
     * are written in this transaction; with fewer the start moves to the next midnight, and a cup published `no_players_cancel_days`
     * ago without a second participant is canceled.
     *
     * @return 'started'|'shifted'|'canceled'|'noop'
     *
     * @throws CupException cup_material_unavailable (the course lost its words since the publication: the cup waits, the scheduler reports)
     */
    public function start(int $cupId, CarbonImmutable $now): string
    {
        /** @var array{0: 'started'|'shifted'|'canceled'|'noop', 1: Cup|null} $result */
        $result = DB::transaction(function () use ($cupId, $now): array {
            $cup = $this->lockOrNull($cupId);
            if ($cup === null || $cup->status !== CupStatus::Scheduled || $cup->starts_at === null || $cup->starts_at->gt($now)) {
                return ['noop', null];
            }

            $players = DB::table('cup_participants')->where('cup_id', $cup->id)->where('status', CupParticipantStatus::Active->value)->count();
            if ($players < 2) {
                $giveUp = ($cup->confirmed_at ?? $cup->created_at ?? $now)->copy()->addDays(max(1, (int) config('cup.no_players_cancel_days', 7)));
                if ($now->gte($giveUp)) {
                    $this->markCanceled($cup, CupCancelReason::NoPlayers, $now);

                    return ['canceled', $cup];
                }
                $startsAt = CarbonImmutable::instance($cup->starts_at);
                while ($startsAt->lte($now)) {
                    $startsAt = CupClock::nextDay($startsAt, $cup->timezone);
                }
                $cup->forceFill(['starts_at' => $startsAt, 'ends_at' => CupClock::endOf($startsAt, $cup->timezone, $cup->days), 'updated_at' => $now])->save();

                return ['shifted', $cup];
            }

            $course = Course::query()->findOrFail($cup->course_id);
            $plan = $this->planner->plan($cup, $course);

            $rows = [];
            for ($day = 1; $day <= $cup->days; $day++) {
                $rows[] = [
                    'cup_id' => $cup->id,
                    'day' => $day,
                    'starts_at' => CupClock::dayStart($cup->starts_at, $cup->timezone, $day),
                    'ends_at' => CupClock::dayStart($cup->starts_at, $cup->timezone, $day + 1),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('cup_days')->insert($rows);
            $cup->forceFill(['status' => CupStatus::Running, 'word_plan' => $plan, 'updated_at' => $now])->save();

            return ['started', $cup];
        });

        [$outcome, $cup] = $result;
        if ($cup !== null && $outcome !== 'shifted') {
            $this->standings->forget($cupId);
            $this->events->record(
                $outcome === 'started' ? ProductEventName::CupStarted : ProductEventName::CupCanceled,
                $outcome === 'started' ? ['cup_id' => $cupId] : ['cup_id' => $cupId, 'reason' => CupCancelReason::NoPlayers->value],
                group: $cup->telegramGroup,
            );
        }

        return $outcome;
    }

    /**
     * The finish (docs/33 §5.6), `settle_minutes` after the end of the last day: the cup first (`FOR UPDATE`), then every participant
     * (`FOR UPDATE` by id) - an answer that holds a participant finishes first and is counted; one that comes later re-reads the status
     * under the lock of its participant and writes nothing. Places for all (left ones too), `finished`, the outbox row `final`
     * (`ON CONFLICT DO NOTHING`), the days that were never built are closed. A repeated or a racing finish is a no-op.
     */
    public function finish(int $cupId, CarbonImmutable $now): bool
    {
        /** @var array{0: Cup, 1: int}|null $done */
        $done = DB::transaction(function () use ($cupId, $now): ?array {
            $cup = $this->lockOrNull($cupId);
            $settle = max(0, (int) config('cup.settle_minutes', 30));
            if ($cup === null || $cup->status !== CupStatus::Running || $cup->ends_at === null || $cup->ends_at->copy()->addMinutes($settle)->gt($now)) {
                return null;
            }

            $participants = count(DB::table('cup_participants')->where('cup_id', $cup->id)->orderBy('id')->lockForUpdate()->pluck('id')->all());
            $this->scores->writeFinalRanks($cup->id);

            $cup->forceFill(['status' => CupStatus::Finished, 'active_group_id' => null, 'finished_at' => $now, 'updated_at' => $now])->save();
            DB::table('cup_messages')->insertOrIgnore([
                'cup_id' => $cup->id,
                'dedupe_key' => 'final',
                'kind' => CupMessageKind::Final->value,
                'status' => CupMessageStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('cup_days')->where('cup_id', $cup->id)->whereNull('built_at')->update(['built_at' => $now, 'updated_at' => $now]);

            return [$cup, $participants];
        });

        if ($done === null) {
            return false;
        }
        [$cup, $participants] = $done;
        $this->standings->forget($cupId);
        $this->events->record(ProductEventName::CupFinished, ['cup_id' => $cupId, 'participants' => $participants], group: $cup->telegramGroup);

        return true;
    }

    /** The open cup of the chat (draft, scheduled or running), if any. */
    public function openCupOf(TelegramGroup $group): ?Cup
    {
        $cup = Cup::query()->where('active_group_id', $group->id)->first();
        $cup?->setRelation('telegramGroup', $group);

        return $cup;
    }

    /** @throws CupException cup_level_invalid | cup_material_unavailable */
    private function courseWithMaterial(string $level): Course
    {
        if (! in_array($level, (array) config('cup.levels', []), true)) {
            throw CupException::levelInvalid();
        }
        $course = $this->planner->courseForLevel($level) ?? throw CupException::materialUnavailable();
        if ($this->planner->eligibleCount($course) < (int) config('cup.days', 7) * (int) config('cup.round.tasks', 10)) {
            throw CupException::materialUnavailable();
        }

        return $course;
    }

    /** @param (callable(Cup): bool)|null $when */
    private function cancel(int $cupId, CupCancelReason $reason, CarbonImmutable $now, ?callable $when = null): bool
    {
        $canceled = DB::transaction(function () use ($cupId, $reason, $now, $when): ?Cup {
            $cup = $this->lockOrNull($cupId);
            if ($cup === null || ! $cup->status->isOpen() || ($when !== null && ! $when($cup))) {
                return null;
            }
            $this->markCanceled($cup, $reason, $now);

            return $cup;
        });

        if ($canceled === null) {
            return false;
        }
        $this->standings->forget($cupId);
        $this->events->record(ProductEventName::CupCanceled, ['cup_id' => $cupId, 'reason' => $reason->value], group: $canceled->telegramGroup);

        return true;
    }

    private function markCanceled(Cup $cup, CupCancelReason $reason, CarbonImmutable $now): void
    {
        $cup->forceFill([
            'status' => CupStatus::Canceled,
            'cancel_reason' => $reason,
            'active_group_id' => null,
            'canceled_at' => $now,
            'updated_at' => $now,
        ])->save();
        // The days that will never be played leave the scheduler's index of unbuilt days.
        DB::table('cup_days')->where('cup_id', $cup->id)->whereNull('built_at')->update(['built_at' => $now, 'updated_at' => $now]);
    }

    private function lock(int $cupId): Cup
    {
        return Cup::query()->whereKey($cupId)->lockForUpdate()->firstOrFail();
    }

    private function lockOrNull(int $cupId): ?Cup
    {
        return Cup::query()->whereKey($cupId)->lockForUpdate()->first();
    }
}
