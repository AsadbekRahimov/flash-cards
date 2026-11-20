<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\DTO\AudioCoverageReport;
use App\Domain\Content\Services\WordAudioCoverageService;
use App\Domain\Content\Services\WordAudioService;
use App\Models\Course;
use Illuminate\Console\Command;
use League\Flysystem\FilesystemException;

/**
 * Coverage of the pre-generated word audio of a course, the check before publishing (docs/12 task 1.2c): active words
 * against words with audio recorded, by module and in total; `--verify` also checks the files on the disk and lists the
 * words whose file is missing, empty or stale. With `--min` the exit code is non-zero below that percent, so a publish
 * script can stop on it. Read-only: queueing the missing audio is `audio:pregenerate`.
 */
final class AudioCoverage extends Command
{
    /** @var string */
    protected $signature = 'audio:coverage
        {course : Course slug}
        {--verify : Also check the files on the disk (slower: one stat - on R2 a HEAD request - per unique file)}
        {--min= : Fail (exit code 1) when the coverage is below this percent, 0-100}
        {--limit=20 : How many broken words to list with --verify}';

    /** @var string */
    protected $description = 'Report how many active words of a course have pre-generated audio (before publishing).';

    public function handle(WordAudioCoverageService $coverage, WordAudioService $audio): int
    {
        $slug = (string) $this->argument('course');

        $min = $this->option('min');
        if ($min !== null && (! is_numeric($min) || (float) $min < 0 || (float) $min > 100)) {
            $this->error('--min must be a number from 0 to 100.');

            return self::INVALID;
        }
        $limit = $this->option('limit');
        if (! is_numeric($limit) || (int) $limit < 0) {
            $this->error('--limit must be a whole number, 0 or more.');

            return self::INVALID;
        }

        $course = Course::query()->where('slug', $slug)->first();
        if ($course === null) {
            $this->error('Course not found: '.$slug);

            return self::FAILURE;
        }

        $verify = (bool) $this->option('verify');
        try {
            $report = $coverage->report($course, $verify, (int) $limit);
        } catch (FilesystemException $e) {
            // R2 did not answer a HEAD (not a "not found"): the coverage would be a guess.
            $this->error(sprintf('The audio disk "%s" cannot be read (%s); nothing is reported.', $audio->diskName(), class_basename($e)));

            return self::FAILURE;
        }

        $this->table(
            $verify ? ['Module', 'Title', 'Active words', 'Audio in DB', '%', 'File OK', '%'] : ['Module', 'Title', 'Active words', 'Audio in DB', '%'],
            [
                ...array_map(fn (array $stage): array => $this->row(
                    (string) $stage['number'], $stage['title'], $stage['words'], $stage['with_audio'], $stage['verified'], $verify,
                ), $report->stages),
                $this->row('Total', '', $report->words, $report->withAudio, $report->verified, $verify),
            ],
        );

        if ($verify) {
            $this->reportProblems($report, $course->slug);
        }

        $this->line(sprintf(
            '%s: %d of %d active words voiced (%s%%)%s.',
            $course->slug,
            $report->covered(),
            $report->words,
            $this->percent($report->percent()),
            $verify ? ', files checked on the disk "'.$audio->diskName().'"' : ', by the DB (add --verify to check the files)',
        ));

        if ($min !== null && ! $report->meets((float) $min)) {
            $this->error(sprintf('Coverage %s%% is below --min=%s%%.', $this->percent($report->percent()), $min));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function row(string $number, string $title, int $words, int $withAudio, ?int $verified, bool $verify): array
    {
        $row = [$number, $title, (string) $words, (string) $withAudio, $this->percent(AudioCoverageReport::share($withAudio, $words))];
        if ($verify) {
            $row[] = (string) ($verified ?? 0);
            $row[] = $this->percent(AudioCoverageReport::share($verified ?? 0, $words));
        }

        return $row;
    }

    private function reportProblems(AudioCoverageReport $report, string $slug): void
    {
        if ($report->problemCount === 0) {
            $this->info('No broken audio files.');

            return;
        }

        $this->warn(sprintf(
            'Words with audio recorded but no usable file: %d (missing / empty file, or a path of an old text). Re-queue them: php artisan audio:pregenerate %s',
            $report->problemCount,
            $slug,
        ));
        if ($report->problems !== []) {
            $this->table(
                ['Word id', 'Word', 'Problem', 'Path'],
                array_map(fn (array $p): array => [(string) $p['id'], $p['word'], $p['reason'], $p['path']], $report->problems),
            );
        }
        if ($report->problemCount > count($report->problems)) {
            $this->line(sprintf('... and %d more (raise --limit to list them).', $report->problemCount - count($report->problems)));
        }
    }

    private function percent(float $value): string
    {
        return number_format($value, 1, '.', '');
    }
}
