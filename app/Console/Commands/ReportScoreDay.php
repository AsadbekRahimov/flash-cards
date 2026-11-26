<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\Scoring\ScoreDayReport;
use App\Domain\Engagement\Services\Scoring\ScoreRules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `leagues:report-score-day` (docs/32 S16): the product event `score.credited` - one summary of a rating day (yesterday of the league
 * zone by default). Scheduled nightly 00:30 (routes/console.php); a repeated run writes nothing (ScoreDayReport), safe to run by hand.
 */
final class ReportScoreDay extends Command
{
    /** @var string */
    protected $signature = 'leagues:report-score-day
        {--day= : The rating day Y-m-d (default: yesterday in the league zone)}';

    /** @var string */
    protected $description = 'Record the product event score.credited: one summary of the word points of a rating day.';

    public function handle(ScoreDayReport $report, ScoreRules $rules): int
    {
        $now = CarbonImmutable::now();
        $day = $this->option('day');
        if ($day !== null) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $day);
            if ($parsed === null || $parsed->format('Y-m-d') !== $day) {
                $this->error('--day must be a date Y-m-d.');

                return self::INVALID;
            }
        } else {
            $day = $rules->ratingDay($now)->subDay()->toDateString();
        }

        $props = $report->record($day, $now);
        $this->info($props === null ? "score.credited {$day}: nothing written (already reported, or the word points are off)." : "score.credited {$day}: learners {$props['learners']}, credits {$props['credits']}.");

        return self::SUCCESS;
    }
}
