<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Models\ExamSession;
use App\Models\Organization;

/**
 * CSV of the results of one exam (`/org`): the format and the formula escaping of {@see OrgActivityReportCsv} (UTF-8 with BOM, `;`,
 * CRLF, a cell that starts a formula gets a `'`), headers in the cabinet's language (`org.exams.csv.*`). Names only, no Telegram ids.
 */
final class ExamResultsCsv
{
    public function __construct(private readonly OrgActivityReportCsv $csv) {}

    /**
     * @param  resource  $out
     * @param  list<array{learner_id: int|null, name: string, correct: int, total: int, percent: float, score: int, rank: int}>  $results
     */
    public function write($out, array $results): void
    {
        $this->csv->bom($out);
        $this->csv->line($out, array_map(fn (string $key): string => __('org.exams.csv.'.$key), ['rank', 'learner', 'correct', 'total', 'percent', 'score']));

        foreach ($results as $row) {
            $this->csv->line($out, [$row['rank'], $row['name'], $row['correct'], $row['total'], (string) $row['percent'], $row['score']]);
        }
    }

    /** `exam-<slug>-<id>-<date of the start>.csv` */
    public function filename(ExamSession $exam, Organization $organization): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/i', '', $organization->slug) ?: 'org';

        return sprintf('exam-%s-%d-%s.csv', $slug, $exam->id, $exam->started_at?->setTimezone($organization->timezone)->format('Y-m-d') ?? 'na');
    }
}
