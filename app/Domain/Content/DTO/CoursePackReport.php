<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

use App\Domain\Content\Services\CoursePackImporter;

/** The outcome of {@see CoursePackImporter::import()}: the files imported and why anything was refused. */
final class CoursePackReport
{
    public bool $courseCreated = false;

    /** @var array<string, ImportReport> file name => the report of its lesson import */
    public array $files = [];

    /** @var list<string> English texts of the refusals of the pack itself (no manifest, unknown course, a foreign file...). */
    public array $errors = [];

    public function ok(): bool
    {
        if ($this->errors !== [] || $this->files === []) {
            return false;
        }

        foreach ($this->files as $report) {
            if (! $report->ok()) {
                return false;
            }
        }

        return true;
    }

    /** @return array{added:int,updated:int,skipped:int} */
    public function totals(): array
    {
        $totals = ['added' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($this->files as $report) {
            $totals['added'] += $report->added;
            $totals['updated'] += $report->updated;
            $totals['skipped'] += $report->skipped;
        }

        return $totals;
    }
}
