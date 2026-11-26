<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoringMode;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\ScoreRules;
use App\Domain\Learners\Support\StoredDate;
use App\Models\LeagueMonth;
use App\Models\LeagueWeek;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `leagues:scoring-cutover` - the switch of the leagues to the word points v2 (docs/32 §12.6; owner's decision В1: at once, no shadow
 * month, the open week and month finish by the old rules).
 *
 * A period counts by the version written into its own row when the row is created (`league_weeks` / `league_months.rules_version`),
 * and a learner's period takes the version of that row: so the open week and month - their rows exist - finish by v1 whatever the
 * configuration says, and the first week / month whose row is created on or after `LEAGUES_SCORING_V2_STARTS_ON` (in `live`) counts by
 * v2. Nothing is rewritten. This command shows that plan and, with `--apply`, does the two safe steps of it:
 *
 *   1. closes the weeks and the months whose close is due (the scheduler's own `closeDue`, idempotent) - the v1 periods that have
 *      ended are final before anything else is looked at;
 *   2. creates the rows of the current week and month if they are missing, so the version they count by is fixed now and printed -
 *      not at the first activity of somebody.
 *
 * It refuses `--apply` unless the mode is `live` and a valid start date is configured, and it warns when the start date is not a
 * Monday (the weeks start on the next Monday) or not the 1st (the months start on the next 1st). A period row that already counts by
 * v1 although the start date says v2 (the date was set into the past) is reported: it stays v1 until it ends - by design.
 */
final class LeagueScoringCutover extends Command
{
    protected $signature = 'leagues:scoring-cutover {--apply : close the due periods and create the rows of the current week and month}';

    protected $description = 'Show (and with --apply prepare) the switch of the weekly and monthly leagues to the word points v2';

    public function handle(ScoreRules $rules, LeagueService $leagues, MonthlyRatingService $months): int
    {
        $now = CarbonImmutable::now();
        $mode = $rules->mode();
        $startsOn = $rules->version(2)?->startsOn;

        $this->line('Mode: '.$mode->value.'; v2 starts on: '.($startsOn ?? '(not set)'));

        if ($startsOn !== null) {
            $start = CarbonImmutable::parse($startsOn, $rules->timezone());
            if (! $start->isMonday()) {
                $this->warn("{$startsOn} is not a Monday: the first v2 week starts on ".$start->next(CarbonImmutable::MONDAY)->toDateString().'.');
            }
            if ($start->day !== 1) {
                $this->warn("{$startsOn} is not the 1st: the first v2 month starts on ".$start->addMonthNoOverflow()->startOfMonth()->toDateString().'.');
            }
        }

        if ($this->option('apply')) {
            if ($mode !== ScoringMode::Live || $startsOn === null) {
                $this->error('Refused: set LEAGUES_SCORING_MODE=live and LEAGUES_SCORING_V2_STARTS_ON first (php artisan config:cache).');

                return self::FAILURE;
            }

            $weeks = $leagues->closeDue($now);
            $closedMonths = $months->closeDue($now);
            $leagues->currentWeek($now);
            $months->currentMonth($now);
            $this->info("Closed: {$weeks} week(s), {$closedMonths} month(s); the rows of the current week and month exist.");
        }

        $day = $rules->ratingDay($now);
        $rows = [];
        foreach ([ScorePeriod::Week, ScorePeriod::Month] as $period) {
            $current = $rules->periodStart($period, $day)->toDateString();
            $next = $period === ScorePeriod::Week
                ? $rules->periodStart($period, $day)->addWeek()->toDateString()
                : $rules->periodStart($period, $day)->addMonthNoOverflow()->toDateString();

            foreach (['current' => $current, 'next' => $next] as $which => $start) {
                $row = $period === ScorePeriod::Week
                    ? LeagueWeek::query()->where('starts_on', StoredDate::of($start))->first()
                    : LeagueMonth::query()->where('starts_on', StoredDate::of($start))->first();
                $stored = $row?->rules_version;
                $expected = $rules->versionFor($start);
                $rows[] = [
                    $period->value,
                    $which,
                    $start,
                    $row === null ? '(no row yet)' : $row->status,
                    $stored === null ? '-' : (string) $stored,
                    $stored ?? $expected,
                ];
                if ($stored !== null && $stored !== $expected) {
                    $this->warn("The {$which} {$period->value} ({$start}) counts by v{$stored} (its row), the configuration says v{$expected}: it stays v{$stored} until it ends.");
                }
            }
        }

        $this->table(['period', '', 'starts on', 'status', 'stored version', 'counts by'], $rows);

        return self::SUCCESS;
    }
}
