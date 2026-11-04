<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunStatus;
use App\Models\GameRun;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the widget `GameActivityWidget` of `/admin` (docs/18 §10, §12.10, §12.12): the last 7 days of the game, as
 * scalars (the widget reads them through `WidgetCache`, never as objects). One place for every rule of the pilot metrics.
 *
 * - `runs_per_player_day` - the runs that ended `completed` or `failed` per learner who started a run, per day (one decimal);
 * - the paywall: the learners with `game.limit_reached` in the window and those of them with a `payment.succeeded` after their
 *   first such event. The window is 7 days and so is the payment window: the first event is no earlier than `now - 7 days`, so a
 *   payment up to `now` is always within 7 days of it;
 * - the control of the study: the share of the right grades (`quality >= 3`) of `training_reviews` of the window, of the players
 *   (a run that ended `completed` or `failed` in the window) and of the other active learners. `null` = no grades at all.
 */
final class GameActivityStats
{
    public const DAYS = 7;

    /** A grade of the cards that counts as right (the bar of `SpacedRepetitionEngine::PASS_QUALITY`; the game does not import the learning domain). */
    public const RIGHT_QUALITY = 3;

    /**
     * @return array{
     *     runs: int, players: int, active: int, share: int, finished_runs: int, runs_per_player_day: float,
     *     paywall_learners: int, paywall_paid: int, paywall_percent: int,
     *     study_players_reviews: int, study_players_percent: int|null, study_others_reviews: int, study_others_percent: int|null,
     * } `share` = whole percent of the active learners who played
     */
    public function lastWeek(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDays(self::DAYS);

        $runs = GameRun::query()->where('started_at', '>=', $since)->count();
        $players = GameRun::query()->where('started_at', '>=', $since)->distinct()->count('learner_id');
        $active = Learner::query()->where('last_seen_at', '>=', $since)->count();
        $finished = $this->finishedRuns($since)->count();

        $paywall = $this->paywall($since, $now);
        $study = $this->study($since);

        return [
            'runs' => $runs,
            'players' => $players,
            'active' => $active,
            'share' => $active > 0 ? min(100, (int) round($players * 100 / $active)) : 0,
            'finished_runs' => $finished,
            'runs_per_player_day' => $players > 0 ? round($finished / $players / self::DAYS, 1) : 0.0,
            'paywall_learners' => $paywall['learners'],
            'paywall_paid' => $paywall['paid'],
            'paywall_percent' => $paywall['learners'] > 0 ? (int) round($paywall['paid'] * 100 / $paywall['learners']) : 0,
            'study_players_reviews' => $study['players']['total'],
            'study_players_percent' => $this->percent($study['players']),
            'study_others_reviews' => $study['others']['total'],
            'study_others_percent' => $this->percent($study['others']),
        ];
    }

    /** @return Builder<GameRun> the runs of the window that ended played to the end or failed (not expired / abandoned) */
    private function finishedRuns(CarbonImmutable $since): Builder
    {
        return GameRun::query()
            ->where('started_at', '>=', $since)
            ->where('status', RunStatus::Finished->value)
            ->whereIn('finish_reason', [RunFinishReason::Completed->value, RunFinishReason::Failed->value]);
    }

    /**
     * One statement. `f` = the first `game.limit_reached` of every learner in the window (index `(name, occurred_at)`), the
     * correlated EXISTS looks for a payment of the learner after it (index `(learner_id, occurred_at)`).
     *
     * @return array{learners: int, paid: int}
     */
    private function paywall(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $first = DB::table('product_events')
            ->select('learner_id')
            ->selectRaw('MIN(occurred_at) AS first_at')
            ->where('name', ProductEventName::GameLimitReached->value)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('learner_id')
            ->groupBy('learner_id');

        $row = DB::query()
            ->fromSub($first, 'f')
            ->selectRaw('COUNT(*) AS learners')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM product_events p WHERE p.name = ? AND p.learner_id = f.learner_id'
                .' AND p.occurred_at >= f.first_at AND p.occurred_at <= ?) THEN 1 ELSE 0 END), 0) AS paid',
                [ProductEventName::PaymentSucceeded->value, $now],
            )
            ->first();

        return ['learners' => (int) ($row->learners ?? 0), 'paid' => (int) ($row->paid ?? 0)];
    }

    /**
     * One statement over `training_reviews (created_at, learner_id)` of the window; the players come in as a derived distinct list.
     *
     * @return array{players: array{total: int, right: int}, others: array{total: int, right: int}}
     */
    private function study(CarbonImmutable $since): array
    {
        $playersList = $this->finishedRuns($since)->select('learner_id')->distinct();

        $rows = DB::table('training_reviews AS tr')
            ->joinSub($playersList, 'p', 'p.learner_id', '=', 'tr.learner_id', 'left')
            ->join('learners AS l', 'l.id', '=', 'tr.learner_id')
            ->where('tr.created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNotNull('p.learner_id')->orWhere('l.last_seen_at', '>=', $since))
            ->selectRaw('CASE WHEN p.learner_id IS NULL THEN 0 ELSE 1 END AS is_player')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN tr.quality >= ? THEN 1 ELSE 0 END) AS right_count', [self::RIGHT_QUALITY])
            ->groupByRaw('1')
            ->get();

        $out = ['players' => ['total' => 0, 'right' => 0], 'others' => ['total' => 0, 'right' => 0]];
        foreach ($rows as $row) {
            $out[(int) $row->is_player === 1 ? 'players' : 'others'] = ['total' => (int) $row->total, 'right' => (int) $row->right_count];
        }

        return $out;
    }

    /** @param array{total: int, right: int} $group */
    private function percent(array $group): ?int
    {
        return $group['total'] > 0 ? (int) round($group['right'] * 100 / $group['total']) : null;
    }
}
