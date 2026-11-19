<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Support\WilsonInterval;
use App\Domain\Learners\Enums\ReminderVariant;
use App\Domain\Learners\Services\ReminderExperiment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The question vs the plain text in the reminder ({@see ReminderExperiment}; audit 2026-11-18, «Напоминание в Telegram»):
 * the chain "assigned → delivered → answered in the chat → opened a session from the reminder → studied within 24 h", and the
 * guardrail "turned the reminders off or blocked the bot within 7 days", per arm.
 *
 * Definitions (docs/ADMIN_GUIDE.md repeats them):
 *  - exposure: a `reminder.sent` or `reminder.failed` (403: certainly not delivered) event with a `variant` prop and the
 *    `experiment` prop of THIS experiment (its salt; default: the configured one — an earlier experiment with another salt
 *    is another population). A learner is in the report when their FIRST exposure of this experiment falls in `[from, to)`;
 *    only that one counts (a learner reminded every day would otherwise weigh more), at its moment `t` and in its arm. A
 *    learner first exposed before `from` is not in the cohort of the window, however often they are reminded inside it;
 *  - assigned: those learners — the denominator of EVERY share, the primary one included (intent to treat: a quiz-arm learner
 *    whose question could not be built, or who never answered it, stays in);
 *  - delivered: the first exposure is `reminder.sent`;
 *  - quiz built (quiz arm): its `quiz_built` prop;
 *  - answered in the chat (quiz arm; the plain text has no question by design): a `reminder.quiz_answered` in `[t, t + 24 h)`;
 *  - session from a reminder: a `training.session_opened` with `source` = `reminder` / `reminder_quiz` in `[t, t + 24 h)`;
 *  - PRIMARY, studied within 24 h: at least one `training_reviews` row with `created_at` in `[t, t + 24 h)`;
 *  - reminders off within 7 days: a `reminders.disabled` event in `[t, t + 7 d)`;
 *  - bot blocked within 7 days: a `reminder.failed` after `t` and before `t + 7 d`, or `learners.dm_blocked_at` in that
 *    window (a block seen by another message or by `my_chat_member`; the column is cleared when the learner writes again, so
 *    this is a lower bound).
 * A learner whose 24-hour / 7-day window has not ended yet still counts in the denominator: {@see build()} returns how many
 * there are, and the command warns — read the shares of a window that has fully passed.
 *
 * Queries: one for the cohort (the first exposure of every learner, grouped in SQL; the scan is bounded to `[from, to)`, and
 * "nothing of this experiment earlier" is a NOT EXISTS per candidate over the `(learner_id, occurred_at)` index, itself bounded
 * below by the experiment's `starts_at` when it is set and the report is of the configured experiment), then per {@see CHUNK} learners one per
 * measure, each `(learner_id = ? AND ts >= ? AND ts < ?) OR …` over the `(learner_id, created_at)` /
 * `(learner_id, occurred_at)` indexes. Every value is a binding; the JSON props are read through the query builder (`props->x`),
 * which compiles them for PostgreSQL and sqlite alike.
 *
 * @phpstan-type Share array{count: int, of: int, percent: float|null, low: float|null, high: float|null}
 * @phpstan-type Arm array{assigned: int, metrics: array<string, Share|null>}
 * @phpstan-type Report array{experiment: string, from: string, to: string, arms: array<string, Arm>, immature_24h: int, immature_7d: int}
 */
final class ReminderExperimentReport
{
    /** The measures in the order of the report; a null value of a measure in an arm = not applicable there. */
    public const METRICS = [
        'delivered',
        'quiz_built',
        'answered_in_chat',
        'session_from_reminder_24h',
        'reviewed_24h',
        'reminders_disabled_7d',
        'bot_blocked_7d',
        'disabled_or_blocked_7d',
    ];

    public const PRIMARY = 'reviewed_24h';

    private const DAY = 86400;

    private const GUARD_SECONDS = 7 * self::DAY;

    /**
     * Learners per measure query. Each brings a term `(learner_id = ? AND ts >= ? AND ts < ?)` to an `OR` chain, which sqlite (the
     * tests) parses into a left-deep tree, one level per term, and refuses beyond SQLITE_MAX_EXPR_DEPTH (1000 by default, with the
     * rest of the statement on top): 400 keeps clear of it. PostgreSQL has no such limit (3 bindings per learner, far below 65 535).
     */
    private const CHUNK = 400;

    /**
     * @param  string|null  $experiment  the salt of the experiment to report on; null = the configured one
     * @return Report
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $now = null, ?string $experiment = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $experiment ??= ReminderExperiment::salt();
        // `starts_at` belongs to the configured experiment: nothing of it can have been sent earlier, so the look back stops there.
        $since = $experiment === ReminderExperiment::salt() ? ReminderExperiment::startsAt() : null;
        $cohort = $this->cohort($experiment, $from->utc(), $to->utc(), $since);

        $hits = array_fill_keys(self::METRICS, []);
        foreach (array_chunk($cohort, self::CHUNK, true) as $chunk) {
            $times = array_map(static fn (array $row): int => $row['t'], $chunk);

            $hits['answered_in_chat'] += $this->eventHits([ProductEventName::ReminderQuizAnswered], $times, 0, self::DAY);
            $hits['session_from_reminder_24h'] += $this->eventHits([ProductEventName::TrainingSessionOpened], $times, 0, self::DAY,
                static fn (Builder $q) => $q->whereIn('props->source', ['reminder', 'reminder_quiz']));
            $hits['reviewed_24h'] += $this->hits(DB::table('training_reviews'), 'created_at', $times, 0, self::DAY);
            $hits['reminders_disabled_7d'] += $this->eventHits([ProductEventName::RemindersDisabled], $times, 0, self::GUARD_SECONDS);
            $hits['bot_blocked_7d'] += $this->eventHits([ProductEventName::ReminderFailed], $times, 1, self::GUARD_SECONDS);
            $hits['bot_blocked_7d'] += $this->hits(DB::table('learners'), 'dm_blocked_at', $times, 1, self::GUARD_SECONDS, 'id');
        }
        foreach ($cohort as $learnerId => $row) {
            if ($row['delivered']) {
                $hits['delivered'][$learnerId] = true;
            }
            if ($row['quiz_built']) {
                $hits['quiz_built'][$learnerId] = true;
            }
            if (isset($hits['reminders_disabled_7d'][$learnerId]) || isset($hits['bot_blocked_7d'][$learnerId])) {
                $hits['disabled_or_blocked_7d'][$learnerId] = true;
            }
        }

        $arms = [];
        foreach (ReminderVariant::cases() as $variant) {
            $members = array_keys(array_filter($cohort, static fn (array $row): bool => $row['variant'] === $variant->value));
            $metrics = [];
            foreach (self::METRICS as $metric) {
                $quizOnly = in_array($metric, ['quiz_built', 'answered_in_chat'], true);
                $metrics[$metric] = $quizOnly && $variant !== ReminderVariant::Quiz
                    ? null
                    : $this->share(count(array_intersect_key($hits[$metric], array_flip($members))), count($members));
            }
            $arms[$variant->value] = ['assigned' => count($members), 'metrics' => $metrics];
        }

        $nowTs = $now->getTimestamp();

        return [
            'experiment' => $experiment,
            'from' => $from->utc()->format('Y-m-d H:i:s'),
            'to' => $to->utc()->format('Y-m-d H:i:s'),
            'arms' => $arms,
            'immature_24h' => count(array_filter($cohort, static fn (array $row): bool => $nowTs < $row['t'] + self::DAY)),
            'immature_7d' => count(array_filter($cohort, static fn (array $row): bool => $nowTs < $row['t'] + self::GUARD_SECONDS)),
        ];
    }

    /**
     * The first exposure of this experiment of every learner whose first exposure of it falls in `[from, to)`: the exposures of
     * the window, minus the learners with one before `from` (at or after `since`, when known).
     *
     * @return array<int, array{t: int, variant: string, delivered: bool, quiz_built: bool}> learner id => its first exposure
     */
    private function cohort(string $experiment, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $since): array
    {
        $names = [ProductEventName::ReminderSent->value, ProductEventName::ReminderFailed->value];
        $exposures = static fn (Builder $q, string $prefix): Builder => $q
            ->whereIn("{$prefix}name", $names)
            ->whereNotNull("{$prefix}learner_id")
            ->whereNotNull("{$prefix}props->variant")
            ->where("{$prefix}props->experiment", $experiment);
        $fromAt = $from->format('Y-m-d H:i:s');
        $sinceAt = $since !== null && $since->isBefore($from) ? $since->format('Y-m-d H:i:s') : null;

        $firsts = $exposures(DB::table('product_events', 'x'), 'x.')
            ->where('x.occurred_at', '>=', $fromAt)
            ->where('x.occurred_at', '<', $to->format('Y-m-d H:i:s'))
            ->whereNotExists(static function (Builder $earlier) use ($exposures, $fromAt, $sinceAt): void {
                $exposures($earlier->from('product_events', 'p')->selectRaw('1'), 'p.')
                    ->whereColumn('p.learner_id', 'x.learner_id')
                    ->where('p.occurred_at', '<', $fromAt)
                    ->when($sinceAt !== null, static fn (Builder $q) => $q->where('p.occurred_at', '>=', $sinceAt));
            })
            ->select('x.learner_id')
            ->selectRaw('MIN(x.occurred_at) AS first_at')
            ->groupBy('x.learner_id');

        $rows = $exposures(DB::table('product_events', 'e'), 'e.')
            ->joinSub($firsts, 'f', static function ($join): void {
                $join->on('f.learner_id', '=', 'e.learner_id')->on('f.first_at', '=', 'e.occurred_at');
            })
            ->orderBy('e.learner_id')
            ->orderBy('e.id')
            ->get(['e.learner_id', 'e.name', 'e.occurred_at', 'e.props']);

        $cohort = [];
        foreach ($rows as $row) {
            $learnerId = (int) $row->learner_id;
            if (isset($cohort[$learnerId])) {
                continue; // two exposures in the same second: the first written one
            }

            $props = is_string($row->props) ? json_decode($row->props, true) : null;
            $variant = is_array($props) ? ($props['variant'] ?? null) : null;
            if (ReminderVariant::tryFrom(is_string($variant) ? $variant : '') === null) {
                continue;
            }

            $cohort[$learnerId] = [
                't' => CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->getTimestamp(),
                'variant' => (string) $variant,
                'delivered' => $row->name === ProductEventName::ReminderSent->value,
                'quiz_built' => ($props['quiz_built'] ?? false) === true,
            ];
        }

        return $cohort;
    }

    /**
     * @param  list<ProductEventName>  $names
     * @param  array<int, int>  $times  learner id => t (unix)
     * @param  (callable(Builder): mixed)|null  $filter
     * @return array<int, true>
     */
    private function eventHits(array $names, array $times, int $fromOffset, int $toOffset, ?callable $filter = null): array
    {
        $query = DB::table('product_events')->whereIn('name', array_map(static fn (ProductEventName $n): string => $n->value, $names));
        if ($filter !== null) {
            $filter($query);
        }

        return $this->hits($query, 'occurred_at', $times, $fromOffset, $toOffset);
    }

    /**
     * The learners of `$times` with a row of `$query` whose `$column` is in `[t + fromOffset, t + toOffset)`.
     *
     * @param  array<int, int>  $times  learner id => t (unix)
     * @return array<int, true>
     */
    private function hits(Builder $query, string $column, array $times, int $fromOffset, int $toOffset, string $learnerColumn = 'learner_id'): array
    {
        if ($times === []) {
            return [];
        }

        $ids = $query
            ->where(static function (Builder $any) use ($times, $column, $fromOffset, $toOffset, $learnerColumn): void {
                foreach ($times as $learnerId => $t) {
                    $any->orWhere(static fn (Builder $one) => $one
                        ->where($learnerColumn, $learnerId)
                        ->where($column, '>=', gmdate('Y-m-d H:i:s', $t + $fromOffset))
                        ->where($column, '<', gmdate('Y-m-d H:i:s', $t + $toOffset)));
                }
            })
            ->distinct()
            ->pluck($learnerColumn);

        $hits = [];
        foreach ($ids as $id) {
            $hits[(int) $id] = true;
        }

        return $hits;
    }

    /** @return array{count: int, of: int, percent: float|null, low: float|null, high: float|null} */
    private function share(int $count, int $of): array
    {
        $interval = WilsonInterval::of($count, $of);

        return [
            'count' => $count,
            'of' => $of,
            'percent' => $of > 0 ? round($count / $of * 100, 1) : null,
            'low' => $interval['low'] ?? null,
            'high' => $interval['high'] ?? null,
        ];
    }
}
