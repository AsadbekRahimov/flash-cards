<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\WordAudioService;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Course;
use App\Models\Word;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Queues PregenerateWordAudioJob (queue `low`) for the active words of a course
 * (docs/12 task 1.2c). Words whose audio is already current are skipped unless --force.
 * With --force only the first word of each unique text re-synthesizes (one TTS call per file);
 * the rest are queued without force: they just point at the shared file.
 */
final class PregenerateAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:pregenerate {course : Course slug} {--force : Regenerate even if the audio already exists}';

    /** @var string */
    protected $description = 'Queue TTS pre-generation for the active words of a course.';

    public function handle(WordAudioService $audio): int
    {
        $course = Course::query()->where('slug', (string) $this->argument('course'))->first();
        if ($course === null) {
            $this->error('Course not found: '.(string) $this->argument('course'));

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $queued = 0;
        $skipped = 0;
        /** @var array<string, true> $forced paths already queued for re-synthesis in this run */
        $forced = [];

        Word::query()
            ->where('is_active', true)
            ->whereHas('lesson.stage', fn (Builder $q) => $q->where('course_id', $course->id))
            ->chunkById(500, function (Collection $words) use ($audio, $force, &$queued, &$skipped, &$forced): void {
                foreach ($words as $word) {
                    if (! $force && $audio->isCurrent($word)) {
                        $skipped++;

                        continue;
                    }

                    $path = $audio->pathFor($word->word);
                    $first = $force && $path !== null && ! isset($forced[$path]);
                    if ($first) {
                        $forced[$path] = true;
                    }

                    PregenerateWordAudioJob::dispatch((int) $word->id, $first);
                    $queued++;
                }
            });

        $this->info("Queued: {$queued}, already up to date: {$skipped}");

        return self::SUCCESS;
    }
}
