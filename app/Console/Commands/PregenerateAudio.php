<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\WordAudioScheduler;
use App\Models\Course;
use Illuminate\Console\Command;

/**
 * Queues PregenerateWordAudioJob (queue `tts`) for the active words of a course
 * (docs/12 task 1.2c), or of every course but the decks of learners with `--all` (docs/26 §5.6:
 * after a change of the voice or of `TTS_AUDIO_VERSION`). Words whose audio is already current
 * are skipped unless --force. With --force only the first word of each unique text re-synthesizes
 * (one TTS call per file, across all the courses of the run); the rest are queued without force:
 * they just point at the shared file.
 */
final class PregenerateAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:pregenerate
        {course? : Course slug}
        {--all : Every course except the decks of learners}
        {--force : Regenerate even if the audio already exists}';

    /** @var string */
    protected $description = 'Queue TTS pre-generation for the active words of a course (or of all courses with --all).';

    public function handle(WordAudioScheduler $scheduler): int
    {
        $slug = $this->argument('course');
        $all = (bool) $this->option('all');
        if ($all === ($slug !== null)) {
            $this->error('Give either a course slug or --all.');

            return self::INVALID;
        }

        if ($all) {
            $courses = Course::query()->where('owner_type', '<>', 'learner')->orderBy('id')->get();
        } else {
            $course = Course::query()->where('slug', (string) $slug)->first();
            if ($course === null) {
                $this->error('Course not found: '.(string) $slug);

                return self::FAILURE;
            }
            $courses = [$course];
        }

        $force = (bool) $this->option('force');
        $queued = 0;
        $skipped = 0;
        /** @var array<string, true> $forced paths already queued for re-synthesis in this run */
        $forced = [];

        foreach ($courses as $course) {
            $result = $scheduler->queueCourse($course, $force, $forced);
            $queued += $result['queued'];
            $skipped += $result['skipped'];

            if ($all) {
                $this->line("{$course->slug}: queued {$result['queued']}, already up to date {$result['skipped']}");
            }
        }

        $this->info("Queued: {$queued}, already up to date: {$skipped}");

        return self::SUCCESS;
    }
}
