<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Services\ReminderExperimentReport;
use App\Domain\Learners\Services\ReminderExperiment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * `analytics:reminder-experiment` — the question vs the plain text in the reminder, per arm
 * ({@see ReminderExperimentReport}; docs/ADMIN_GUIDE.md "Эксперимент с напоминанием-вопросом"). Read-only, not scheduled.
 * `--from` defaults to `learning.reminders.quiz.experiment.starts_at`, `--to` to now; a bare date of `--to` includes that day.
 */
final class ReminderExperimentReportCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:reminder-experiment
        {--from= : First exposures from this UTC date / instant (default: the experiment\'s starts_at)}
        {--to= : … until this UTC date (inclusive) / instant (exclusive); default: now}';

    /** @var string */
    protected $description = 'Report of the reminder experiment (quiz vs plain): shares with Wilson 95 % intervals per arm.';

    private const LABELS = [
        'delivered' => 'Delivered',
        'quiz_built' => 'Question built',
        'answered_in_chat' => 'Answered in chat (24 h)',
        'session_from_reminder_24h' => 'Session from reminder (24 h)',
        'reviewed_24h' => 'PRIMARY: reviewed within 24 h',
        'reminders_disabled_7d' => 'Reminders off (7 d)',
        'bot_blocked_7d' => 'Bot blocked (7 d)',
        'disabled_or_blocked_7d' => 'Off or blocked (7 d)',
    ];

    public function handle(ReminderExperimentReport $report): int
    {
        $now = CarbonImmutable::now()->utc();

        try {
            $from = $this->instant($this->option('from'), false) ?? ReminderExperiment::startsAt();
            $to = $this->instant($this->option('to'), true) ?? $now;
        } catch (Throwable) {
            $this->error('--from / --to: a date (2026-11-24) or a UTC date and time (2026-11-24 09:00) is expected.');

            return self::INVALID;
        }
        if ($from === null) {
            $this->error('No --from and no learning.reminders.quiz.experiment.starts_at: give the start of the window.');

            return self::INVALID;
        }
        if (! $from->isBefore($to)) {
            $this->error('--from must be earlier than --to.');

            return self::INVALID;
        }

        $result = $report->build($from, $to, $now);

        $this->line("First exposures in [{$result['from']}, {$result['to']}) UTC; every share is over ALL assigned learners of the arm.");
        $rows = [['Assigned (denominator)', ...array_map(static fn (array $arm): string => (string) $arm['assigned'], $result['arms'])]];
        foreach (ReminderExperimentReport::METRICS as $metric) {
            $cells = [];
            foreach ($result['arms'] as $arm) {
                $share = $arm['metrics'][$metric];
                $cells[] = $share === null ? '—' : $this->cell($share);
            }
            $rows[] = [self::LABELS[$metric], ...$cells];
        }
        $this->table(['', ...array_keys($result['arms'])], $rows);

        if ($result['immature_24h'] > 0 || $result['immature_7d'] > 0) {
            $this->warn("Windows not over yet: {$result['immature_24h']} learner(s) for 24 h, {$result['immature_7d']} for 7 d — those shares will still grow; read a window that ended 7 days ago.");
        }

        return self::SUCCESS;
    }

    /** @param array{count: int, of: int, percent: float|null, low: float|null, high: float|null} $share */
    private function cell(array $share): string
    {
        if ($share['percent'] === null) {
            return "{$share['count']}/{$share['of']}";
        }

        return sprintf('%d/%d  %.1f %%  [%.1f–%.1f]', $share['count'], $share['of'], $share['percent'], $share['low'] ?? 0.0, $share['high'] ?? 0.0);
    }

    private function instant(mixed $raw, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $instant = CarbonImmutable::parse($raw, 'UTC')->utc();

        // A bare date as the end of the window takes that whole day.
        return $endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $instant->addDay() : $instant;
    }
}
