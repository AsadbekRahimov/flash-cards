<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\DTO\CoursePackReport;
use App\Domain\Content\Services\CoursePackImporter;
use Illuminate\Console\Command;

/**
 * `php artisan content:import-course {dir} {--create}` - imports a course pack (a directory with `course.json` and the lesson
 * files, CoursePackImporter). `--create` makes the course as a draft when it is missing; it is never published here: check it in
 * the admin panel and publish it there. Safe to run again (unchanged words are skipped). A refused file leaves the others imported
 * and the exit code is not zero.
 */
final class ImportCoursePack extends Command
{
    /** @var string */
    protected $signature = 'content:import-course
        {dir : The pack directory (a path from the project root, or an absolute one)}
        {--create : Create the course as a draft when it does not exist}';

    /** @var string */
    protected $description = 'Import a course pack (course.json + lesson files) into the database; the course stays a draft.';

    public function handle(CoursePackImporter $importer): int
    {
        $path = (string) $this->argument('dir');
        $dir = str_starts_with($path, '/') ? $path : base_path($path);

        if ($path === '' || ! is_dir($dir)) {
            $this->error("Directory not found: {$path}");

            return self::FAILURE;
        }

        $report = $importer->import($dir, (bool) $this->option('create'));

        $this->render($report);

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function render(CoursePackReport $report): void
    {
        if ($report->courseCreated) {
            $this->line('Course created as a draft.');
        }

        foreach ($report->errors as $error) {
            $this->error($error);
        }

        $rows = [];
        foreach ($report->files as $name => $file) {
            $rows[] = [$name, $file->added, $file->updated, $file->skipped, $file->ok() ? 'ok' : implode('; ', $file->errors)];
        }
        if ($rows !== []) {
            $this->table(['File', 'Added', 'Updated', 'Skipped', 'Status'], $rows);
        }

        $totals = $report->totals();
        $this->line('Files: '.count($report->files).", words added: {$totals['added']}, updated: {$totals['updated']}, skipped: {$totals['skipped']}.");

        if ($report->ok()) {
            $this->info('Course pack imported. The course is a draft: check it in the admin panel, then publish.');
        } else {
            $this->error('Course pack import finished with errors.');
        }
    }
}
