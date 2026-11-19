<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Services\ClientTimingReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `analytics:client-timing` — how fast the open of the Mini App is for the learner: p50 / p75 / p95 of the sampled timing marks per
 * platform, cold / warm start and class / solo ({@see ClientTimingReport}; docs/ADMIN_GUIDE.md "Скорость открытия"). Read-only, not
 * scheduled. The window is the last `--days` days up to now.
 */
final class ClientTimingReportCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:client-timing
        {--days=7 : The window: the last N days up to now (1..90)}';

    /** @var string */
    protected $description = 'Percentiles (p50/p75/p95, ms) of the Mini App open timing marks by platform, cold/warm and class/solo.';

    private const LABELS = [
        'button_ready' => 'start → button',
        'understand' => 'button → tap',
        'first_answer_ack' => 'start → 1st answer',
        'first_audio' => 'start → 1st audio',
    ];

    public function handle(ClientTimingReport $report): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if (! is_int($days) || $days < 1 || $days > 90) {
            $this->error('--days: a whole number of days from 1 to 90 is expected.');

            return self::INVALID;
        }

        $to = CarbonImmutable::now()->utc();
        $result = $report->build($to->subDays($days), $to);

        $this->line("Opens of the Mini App with timing marks in [{$result['from']}, {$result['to']}) UTC: {$result['opens']}. Milliseconds from the start of the open; n = opens that have the measure.");
        if ($result['cells'] === []) {
            $this->warn('No client.timing events in the window (is CLIENT_TIMING_SAMPLE_PERCENT above 0?).');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($result['cells'] as $cell) {
            foreach (ClientTimingReport::METRICS as $metric) {
                $m = $cell['metrics'][$metric];
                $rows[] = [
                    $cell['platform'],
                    $cell['start'],
                    $cell['segment'],
                    $cell['opens'],
                    self::LABELS[$metric],
                    $m['n'],
                    $m['p50'] ?? '—',
                    $m['p75'] ?? '—',
                    $m['p95'] ?? '—',
                    $cell['small'] ? 'too small (< '.ClientTimingReport::MIN_OPENS.' opens)' : '',
                ];
            }
        }
        $this->table(['Platform', 'Start', 'Segment', 'Opens', 'Measure', 'n', 'p50', 'p75', 'p95', 'Note'], $rows);

        if ($result['truncated']) {
            $this->warn('Only the first '.ClientTimingReport::MAX_ROWS.' opens of the window were read: narrow --days.');
        }

        return self::SUCCESS;
    }
}
