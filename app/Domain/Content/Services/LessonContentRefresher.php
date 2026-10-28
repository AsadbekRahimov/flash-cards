<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\CourseRecountReport;
use App\Domain\Learning\Services\LearningCache;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Word;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps derived data in step after a word was added or its `is_active` flag moved in the admin
 * panel: the cached lesson word count and the course's denormalised `words_count`.
 * (The importer does the same on its own in its bulk path.) {@see refreshAll()} is the repair
 * for the figures that drifted anyway.
 */
final class LessonContentRefresher
{
    public function __construct(
        private readonly LearningCache $cache,
        private readonly CoursePublisher $publisher,
        private readonly WordAudioService $audio,
    ) {}

    public function wordChanged(Word $word): void
    {
        $lesson = $word->lesson()->with('stage.course')->first();
        if ($lesson === null) {
            return;
        }

        $this->cache->forgetLesson($lesson);

        $course = $lesson->stage?->course;
        if ($course !== null) {
            $this->publisher->recount($course);
        }
    }

    /** The English text of a word was edited in the admin panel: its pre-generated audio is stale. */
    public function wordTextChanged(Word $word): void
    {
        $this->audio->textChanged($word);
    }

    /**
     * Brings every derived figure of the content back in step with the words (`php artisan courses:recount-words`):
     * `courses.words_count` of EVERY course - the decks of learners are courses, archived ones too - through
     * {@see CoursePublisher::recount()}, and the cached active-word count of every lesson. Every path that changes
     * words keeps these figures up to date; this is the repair for rows that drifted anyway (a path that forgot,
     * a restored dump, hand-made SQL), and what a deploy runs after the migrations because `words_count` is the
     * `total` of `GET /courses` (docs/03 §6). Idempotent: a second run corrects nothing - and writes nothing: a course
     * whose figure is right is only counted (one read), so `updated_at` of the courses does not move on every deploy.
     *
     * Safe to run beside the writers of a course: a course that needs a correction is recounted in a short transaction
     * of its own that takes the course row first ({@see CoursePublisher::lockForWriting()}), so a run that meets an
     * import waits for its commit and counts afterwards instead of overwriting the figure with a count that misses
     * the import. (A course found right on the first, unlocked count is skipped: an import still in flight writes
     * its own figure when it commits.)
     */
    public function refreshAll(): CourseRecountReport
    {
        $checked = 0;
        /** @var list<array{id:int, slug:string, from:int, to:int}> $changes */
        $changes = [];

        Course::query()->orderBy('id')->chunkById(200, function (Collection $courses) use (&$checked, &$changes): void {
            foreach ($courses as $course) {
                $checked++;
                if ($this->publisher->countActiveWords($course) === $course->words_count) {
                    continue;
                }

                DB::transaction(function () use ($course, &$changes): void {
                    $locked = $this->publisher->lockForWriting($course);
                    if ($locked === null) {
                        return; // deleted since the chunk was read
                    }

                    $from = $locked->words_count;
                    $this->publisher->recount($locked);

                    if ($locked->words_count !== $from) {
                        $changes[] = ['id' => $locked->id, 'slug' => $locked->slug, 'from' => $from, 'to' => $locked->words_count];
                    }
                });
            }
        });

        $lessons = 0;
        Lesson::query()->select('id')->chunkById(1000, function (Collection $chunk) use (&$lessons): void {
            foreach ($chunk as $lesson) {
                $this->cache->forgetLesson($lesson);
                $lessons++;
            }
        });

        return new CourseRecountReport($checked, $changes, $lessons);
    }
}
