<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cup\Services\CupPilotReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * The report of the pilot of the friends' cup (docs/33 §8, K9): `php artisan cups:pilot-report --since=2026-12-01 [--json]`. Read only;
 * the numbers are {@see CupPilotReport}'s - a handful of grouped statements whatever the number of cups.
 */
final class CupsPilotReport extends Command
{
    /** @var string */
    protected $signature = 'cups:pilot-report
        {--since= : the start of the period (a date, UTC); 30 days ago by default}
        {--json : print the numbers as JSON}';

    /** @var string */
    protected $description = 'The numbers of the pilot of the friends\' cup: cups, participation, rounds played to the end, day 3, a second cup.';

    public function handle(CupPilotReport $report): int
    {
        $option = $this->option('since');
        try {
            $since = is_string($option) && $option !== '' ? CarbonImmutable::parse($option, 'UTC')->startOfDay() : CarbonImmutable::now()->subDays(30)->startOfDay();
        } catch (Throwable) {
            $this->error('--since must be a date, e.g. 2026-12-01.');

            return self::INVALID;
        }

        $numbers = $report->report($since);
        if ($this->option('json') === true) {
            $this->line((string) json_encode($numbers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $p = $numbers['participants'];
        $i = $numbers['invitation'];
        $r = $numbers['rounds'];
        $d = $numbers['return_day3'];
        $s = $numbers['second_cup'];

        $this->info("The friends' cup since {$since->toDateString()}");
        $this->table(['Status', 'Cups'], collect($numbers['cups'])->map(static fn (int $n, string $status): array => [$status, $n])->values()->all());
        $this->table(['Metric', 'Value', 'Share'], [
            ['Opened an invitation (unique learners)', $i['viewers'], ''],
            ['… and joined a cup', $i['joined'], self::share($i['joined'], $i['viewers'])],
            ['Participants', $p['joined'], ''],
            ['… played a round at least once', $p['played'], self::share($p['played'], $p['joined'])],
            ['… left the chat', $p['left'], self::share($p['left'], $p['joined'])],
            ['Rounds started', $r['started'], ''],
            ['… played to the last task', $r['completed'], self::share($r['completed'], $r['started'])],
            ['… ran out of time', $r['expired'], self::share($r['expired'], $r['started'])],
            ['Average points of a round', $r['avg_points'], ''],
            ['Played on day 1 or 2', $d['early'], ''],
            ['… came back on day 3 or later', $d['returned'], self::share($d['returned'], $d['early'])],
            ['Groups with a finished cup', $s['finished_groups'], ''],
            ['… published the next cup within 14 days', $s['again'], self::share($s['again'], $s['finished_groups'])],
            ['… window of 14 days still open', $s['window_open'], ''],
        ]);

        return self::SUCCESS;
    }

    private static function share(int $part, int $whole): string
    {
        return $whole > 0 ? number_format(100 * $part / $whole, 1).' %' : '—';
    }
}
