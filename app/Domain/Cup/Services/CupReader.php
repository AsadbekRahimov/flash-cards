<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\DTO\CupContext;
use App\Domain\Cup\DTO\DayScore;
use App\Domain\Cup\Enums\CupAttemptStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupScoring;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The reads of the Mini App of a cup (docs/33 §7.2, K7) that are not decisions: where a request finds its cup, "my cups", my days.
 * No rule lives here - who may see what is {@see CupMembershipGate}, the rest of the core decides on what this reads.
 *
 * {@see locate()} is ONE statement: the cup by its id (PK) or code (unique), its group (PK) and the learner's own participation
 * (the unique `(cup_id, learner_id)`); the models are hydrated from the joined row, the group is set as the relation the core reads
 * (`$cup->telegramGroup`), so a request pays one statement before the core, whatever the core asks of the cup.
 */
final class CupReader
{
    /** The alias prefix of the scalars of {@see locate()} `$extra` in the row. */
    private const EXTRA_PREFIX = 'ctx_x_';

    /** The columns of `telegram_groups` the core reads (the pause, the chat, the language of the group). */
    private const GROUP_COLUMNS = ['id', 'chat_id', 'title', 'status', 'bot_status', 'bot_status_at', 'organization_id', 'course_id', 'meta', 'created_at', 'updated_at'];

    private const PARTICIPANT_COLUMNS = [
        'id', 'cup_id', 'learner_id', 'display_name', 'status', 'joined_at', 'first_day', 'left_at', 'withdrawn_at', 'membership_checked_at',
        'best_total', 'best_round_points', 'days_played', 'final_rank', 'rename_count', 'created_at', 'updated_at',
    ];

    /**
     * The cup by its numeric id (the routes `/cups/{id}`), with the learner's participation. `$extra` - scalar subqueries a caller
     * needs in the SAME statement (the duels, {@see CupDuelReader}), by name; they may refer to `cups` (the cup) and `cp` (my row, NULL
     * columns for a non-participant) and come back in {@see CupContext::$extra}.
     *
     * @param  array<string, Builder>  $extra
     */
    public function locate(int $cupId, Learner $learner, array $extra = []): ?CupContext
    {
        $query = $this->query($learner)->where('cups.id', $cupId);
        foreach ($extra as $name => $subquery) {
            $query->selectSub($subquery, self::EXTRA_PREFIX.$name);
        }

        return $this->first($query);
    }

    /** The cup by the code of its link (`/cups/by-code/{code}`, `/cups/setup/{code}`), with the number of its participants. */
    public function locateByCode(string $code, Learner $learner, bool $withCount = false): ?CupContext
    {
        $query = $this->query($learner)->where('cups.public_code', $code);
        if ($withCount) {
            // Index-only through the unique (cup_id, learner_id): tens of entries of one cup.
            $query->selectSub(DB::table('cup_participants as cpc')->selectRaw('count(*)')->whereColumn('cpc.cup_id', 'cups.id'), 'ctx_participants_count');
        }

        return $this->first($query);
    }

    /**
     * "My cups" (`GET /cups`): every participation of the learner (index `learner_id`, a few rows) but the cups he left himself, with its cup and group in one
     * statement; the open cups first, then the last `$closedLimit` finished or canceled ones, newest first. With each, the state of
     * the round of today ({@see todayOf()}) - two scalar subqueries in the SAME statement: today's day of the cup (unique
     * `(cup_id, day)`) and the participant's attempt of that day (unique `(cup_day_id, participant_id)`).
     *
     * @return list<CupContext>
     */
    public function mine(Learner $learner, int $closedLimit = 10, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $at = $now->format('Y-m-d H:i:s');
        $today = fn (string $column) => DB::table('cup_days as td')->select("td.{$column}")
            ->whereColumn('td.cup_id', 'cups.id')->where('td.starts_at', '<=', $at)->where('td.ends_at', '>', $at)->limit(1);
        $attempt = fn (string $column) => DB::table('cup_round_attempts as ta')->select("ta.{$column}")
            ->whereColumn('ta.participant_id', 'cp.id')->where('ta.cup_day_id', '=', $today('id'))->limit(1);

        $rows = DB::table('cup_participants as cp')
            ->join('cups', 'cups.id', '=', 'cp.cup_id')
            ->join('telegram_groups as tg', 'tg.id', '=', 'cups.telegram_group_id')
            ->where('cp.learner_id', $learner->id)
            // A cup the learner left himself is not his any more (docs/33 §11.12): it is not listed (and not opened).
            ->whereNull('cp.withdrawn_at')
            ->where('cups.status', '!=', CupStatus::Draft->value)
            ->orderByDesc('cups.id')
            ->limit(100)
            ->select([...self::cupColumns(), ...self::prefixed('tg', 'tg__', self::GROUP_COLUMNS), ...self::prefixed('cp', 'cp__', self::PARTICIPANT_COLUMNS)])
            ->selectSub($today('day'), 'ctx_today_day')
            ->selectSub($attempt('status'), 'ctx_today_status')
            ->selectSub($attempt('expires_at'), 'ctx_today_expires_at')
            ->get();

        $open = [];
        $closed = [];
        foreach ($rows as $row) {
            $context = $this->hydrate((array) $row, $now);
            if ($context->cup->status->isOpen()) {
                $open[] = $context;
            } elseif (count($closed) < $closedLimit) {
                $closed[] = $context;
            }
        }

        return [...$open, ...$closed];
    }

    /**
     * The days of a participant (`me.days` of the table): every day of the cup with his points, whether he played it and whether it
     * counts in his total ({@see CupScoring::best()}, the same choice as the core's) - one statement, ≤ `days` rows.
     *
     * @return list<array{day: int, points: int, round_points: int, duel_points: int, played: bool, counted: bool, available: bool}>
     */
    public function days(Cup $cup, CupParticipant $participant): array
    {
        $scores = [];
        foreach (DB::table('cup_day_scores')->where('participant_id', $participant->id)->get(['day', 'round_points', 'duel_points']) as $row) {
            $scores[(int) $row->day] = new DayScore((int) $row->day, (int) $row->round_points, (int) $row->duel_points);
        }
        $counted = array_flip(CupScoring::best(array_values($scores), $cup->counted_days)->countedDays);

        $days = [];
        for ($day = 1; $day <= $cup->days; $day++) {
            $score = $scores[$day] ?? null;
            $days[] = [
                'day' => $day,
                'points' => $score?->total() ?? 0,
                'round_points' => $score->round ?? 0,
                'duel_points' => $score->duel ?? 0,
                'played' => $score !== null,
                'counted' => $score !== null && isset($counted[$day]),
                'available' => $day >= $participant->first_day,
            ];
        }

        return $days;
    }

    private function query(Learner $learner): Builder
    {
        return DB::table('cups')
            ->join('telegram_groups as tg', 'tg.id', '=', 'cups.telegram_group_id')
            ->leftJoin('cup_participants as cp', fn (JoinClause $join): JoinClause => $join->on('cp.cup_id', '=', 'cups.id')->where('cp.learner_id', '=', $learner->id))
            ->select([...self::cupColumns(), ...self::prefixed('tg', 'tg__', self::GROUP_COLUMNS), ...self::prefixed('cp', 'cp__', self::PARTICIPANT_COLUMNS)]);
    }

    private function first(Builder $query): ?CupContext
    {
        $row = $query->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row, ?CarbonImmutable $now = null): CupContext
    {
        $cup = [];
        $group = [];
        $participant = [];
        $count = null;
        $ctx = [];
        $extra = [];
        foreach ($row as $key => $value) {
            match (true) {
                str_starts_with($key, 'tg__') => $group[substr($key, 4)] = $value,
                str_starts_with($key, 'cp__') => $participant[substr($key, 4)] = $value,
                $key === 'ctx_participants_count' => $count = (int) $value,
                str_starts_with($key, self::EXTRA_PREFIX) => $extra[substr($key, strlen(self::EXTRA_PREFIX))] = $value,
                str_starts_with($key, 'ctx_') => $ctx[$key] = $value,
                default => $cup[$key] = $value,
            };
        }

        /** @var Cup $model */
        $model = (new Cup)->newFromBuilder($cup);
        /** @var TelegramGroup $telegramGroup */
        $telegramGroup = (new TelegramGroup)->newFromBuilder($group);
        $model->setRelation('telegramGroup', $telegramGroup);

        $mine = null;
        if (($participant['id'] ?? null) !== null) {
            /** @var CupParticipant $mine */
            $mine = (new CupParticipant)->newFromBuilder($participant);
        }

        $today = $now !== null && array_key_exists('ctx_today_day', $ctx) && $mine !== null ? self::todayOf($model, $mine, $ctx, $now) : null;

        return new CupContext($model, $mine, $count, $today, $extra);
    }

    /**
     * The state of the round of today as {@see CupRoundService::today()} decides it (same rules, from the columns of {@see mine()}):
     * `not_available` - the cup does not run, no day now, or the participant joined later; `not_started`; `active` - an attempt open
     * and within its deadline (+ the grace of an answer); `done`.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{state: 'not_started'|'active'|'done'|'not_available', day: int|null}
     */
    private static function todayOf(Cup $cup, CupParticipant $participant, array $ctx, CarbonImmutable $now): array
    {
        $day = $ctx['ctx_today_day'] === null ? null : (int) $ctx['ctx_today_day'];
        if ($cup->status !== CupStatus::Running || $day === null || $day < $participant->first_day) {
            return ['state' => 'not_available', 'day' => null];
        }
        $status = $ctx['ctx_today_status'] ?? null;
        if ($status === null) {
            return ['state' => 'not_started', 'day' => $day];
        }
        $grace = max(0, (int) config('cup.round.grace_ms', 1500));
        $active = $status === CupAttemptStatus::Active->value
            && $now->lte(CarbonImmutable::parse((string) $ctx['ctx_today_expires_at'], 'UTC')->addMilliseconds($grace));

        return ['state' => $active ? 'active' : 'done', 'day' => $day];
    }

    /** @return list<string> */
    private static function cupColumns(): array
    {
        return ['cups.*'];
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function prefixed(string $table, string $prefix, array $columns): array
    {
        return array_map(static fn (string $column): string => "{$table}.{$column} as {$prefix}{$column}", $columns);
    }
}
