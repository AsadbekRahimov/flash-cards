<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Practice\DTO\PracticeImportReport;
use App\Domain\Practice\Services\PracticeImporter;
use Illuminate\Console\Command;

/**
 * `php artisan practice:import {file} {--publish}` - imports the tasks of the practice from a JSON file (docs/16 §15.1) with
 * the same service as the admin page (PracticeImporter). Drafts by default; `--publish` writes them as published, and is
 * the confirmation that the content of the file has been reviewed.
 *
 * Prints what was done as a table; with any refusal nothing is written and the exit code is not zero.
 */
final class ImportPracticeItems extends Command
{
    /** @var string */
    protected $signature = 'practice:import
        {file : The JSON file (a path from the project root, or an absolute one)}
        {--publish : Publish the tasks at once - only for content that has been reviewed}';

    /** @var string */
    protected $description = 'Import practice tasks from a JSON file (all or nothing).';

    public function handle(PracticeImporter $importer): int
    {
        $path = $this->argument('file');
        $file = str_starts_with($path, '/') ? $path : base_path($path);

        if ($path === '' || ! is_file($file) || ! is_readable($file)) {
            $this->error("File not found or not readable: {$path}");

            return self::FAILURE;
        }

        // One byte over the limit is enough for the importer to refuse the file: do not read a huge file whole.
        $raw = file_get_contents($file, false, null, 0, PracticeImporter::MAX_BYTES + 1);
        if ($raw === false) {
            $this->error("File not readable: {$path}");

            return self::FAILURE;
        }

        $report = $importer->importAsSystem($raw, (bool) $this->option('publish'));

        $this->render($report);

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function render(PracticeImportReport $report): void
    {
        if ($report->ok()) {
            $this->table(['Added', 'Updated', 'Skipped', 'Published'], [[$report->added, $report->updated, $report->skipped, $report->published]]);
            $this->info('Practice import finished.');

            return;
        }

        $rows = [];
        foreach ($report->issues as $issue) {
            $rows[] = [
                $issue->context['position'] ?? '-',
                $issue->context['key'] ?? '-',
                $issue->code,
                $issue->message,
            ];
        }

        $this->table(['Task', 'Key', 'Code', 'Message'], $rows);
        $this->error('Practice import refused: nothing was written ('.count($rows).' issue(s)).');
    }
}
