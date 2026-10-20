<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\DTO\DateRange;
use App\Domain\Organizations\DTO\GroupActivityRow;
use App\Domain\Organizations\DTO\LearnerActivityRow;
use App\Models\Organization;

/**
 * CSV of the activity report (docs/13 §6.4): UTF-8 with BOM (Excel opens it as UTF-8), `;` as the
 * delimiter (the default list separator of Excel with ru/uz regional settings), CRLF line ends,
 * headers in the cabinet's language (`org.reports.csv.*`). Telegram names are user-controlled, so a
 * cell that would start a spreadsheet formula (`= + - @`, tab, CR) is prefixed with `'`.
 * No Telegram ids — the same columns the cabinet shows.
 */
final class OrgActivityReportCsv
{
    public const DELIMITER = ';';

    /**
     * @param  resource  $out
     * @param  list<LearnerActivityRow>  $rows
     */
    public function writeLearners($out, Organization $organization, array $rows): void
    {
        $this->bom($out);
        $this->line($out, array_map(fn (string $key): string => __('org.reports.csv.'.$key), [
            'learner', 'username', 'reviews', 'new_words', 'days_active', 'goal_met_days', 'streak', 'assignments_done', 'assignments_total', 'last_seen',
        ]));

        foreach ($rows as $row) {
            $this->line($out, [
                $row->name,
                $row->username ?? '',
                $row->reviews,
                $row->newWords,
                $row->daysActive,
                $row->goalMetDays,
                $row->streakCurrent,
                $row->assignmentsDone,
                $row->assignmentsTotal,
                $row->lastSeenAt?->setTimezone($organization->timezone)->format('Y-m-d H:i') ?? '',
            ]);
        }
    }

    /**
     * @param  resource  $out
     * @param  list<GroupActivityRow>  $rows
     */
    public function writeGroups($out, array $rows): void
    {
        $this->bom($out);
        $this->line($out, array_map(fn (string $key): string => __('org.reports.csv.'.$key), [
            'group', 'learners', 'active_learners', 'reviews', 'new_words', 'goal_met_days', 'assignments_done', 'assignments_total',
        ]));

        foreach ($rows as $row) {
            $this->line($out, [
                $row->title,
                $row->learners,
                $row->activeLearners,
                $row->reviews,
                $row->newWords,
                $row->goalMetDays,
                $row->assignmentsDone,
                $row->assignmentsTotal,
            ]);
        }
    }

    /** File name: `activity-<slug>-<from>_<to>.csv` (or `groups-…`). */
    public function filename(string $kind, Organization $organization, DateRange $range): string
    {
        return sprintf('%s-%s-%s_%s.csv', $kind, preg_replace('/[^a-z0-9-]+/i', '', $organization->slug) ?: 'org', $range->from, $range->to);
    }

    /** @param  resource  $out */
    private function bom($out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
    }

    /**
     * @param  resource  $out
     * @param  list<string|int>  $cells
     */
    private function line($out, array $cells): void
    {
        $safe = array_map(function (string|int $cell): string {
            $cell = (string) $cell;

            return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
        }, $cells);

        fputcsv($out, $safe, self::DELIMITER, '"', '', "\r\n");
    }
}
