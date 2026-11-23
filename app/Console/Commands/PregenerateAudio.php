<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\WordAudioScheduler;
use App\Models\Course;
use Illuminate\Console\Command;

/**
 * Queues PregenerateWordAudioJob and PregenerateExampleAudioJob (queue `tts`) for the active words of a course and their anchor
 * examples (docs/12 task 1.2c, docs/26 §9.11), or of every course but the decks of learners with `--all` (docs/26 §5.6:
 * after a change of the voice or of `TTS_AUDIO_VERSION`); `--only=words` or `--only=examples` takes one of the two. Audio that
 * is already current is skipped unless --force. With --force only the first job of each unique text re-synthesizes
 * (one TTS call per file, across all the courses of the run); the rest are queued without force:
 * they just point at the shared file.
 */
final class PregenerateAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:pregenerate
        {course? : Course slug}
        {--all : Every course except the decks of learners}
        {--force : Regenerate even if the audio already exists}
        {--only= : words or examples (default: both)}';

    /** @var string */
    protected $description = 'Queue TTS pre-generation for the active words of a course and their examples (or of all courses with --all).';

    public function handle(WordAudioScheduler $scheduler): int
    {
        $slug = $this->argument('course');
        $all = (bool) $this->option('all');
        if ($all === ($slug !== null)) {
            $this->error('Give either a course slug or --all.');

            return self::INVALID;
        }
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['words', 'examples'], true)) {
            $this->error('--only must be words or examples.');

            return self::INVALID;
        }
        $words = $only !== 'examples';
        $examples = $only !== 'words';

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
        $totals = ['queued' => 0, 'skipped' => 0, 'examples_queued' => 0, 'examples_skipped' => 0];
        /** @var array<string, true> $forced paths already queued for re-synthesis in this run */
        $forced = [];

        foreach ($courses as $course) {
            $result = $scheduler->queueCourse($course, $force, $forced, $words, $examples);
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            if ($all && $words) {
                $this->line("{$course->slug}: queued {$result['queued']}, already up to date {$result['skipped']}");
            }
            if ($all && $examples) {
                $this->line("{$course->slug} examples: queued {$result['examples_queued']}, already up to date {$result['examples_skipped']}");
            }
        }

        if ($words) {
            $this->info("Queued: {$totals['queued']}, already up to date: {$totals['skipped']}");
        }
        if ($examples) {
            $this->info("Examples queued: {$totals['examples_queued']}, already up to date: {$totals['examples_skipped']}");
        }

        return self::SUCCESS;
    }
}
