<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Exceptions\CourseKindException;
use App\Domain\Content\Support\Phrase;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;

/**
 * The kind of a course - of phrases (`exam_tag = phrases`) or of words - is the kind of its words (docs/35 §3.3 a, c): the importer
 * checks every entry against it, and the pools of options (exam, game, cup) choose courses by it. A course that already holds words
 * may therefore not be re-tagged across the line: its words would land in the pool of the other kind (a phrase among the options of a
 * word, a phrase course in the cup). A change within one kind (`general` → `ielts`) or of an empty course is free.
 */
final class CourseKindGuard
{
    /**
     * @throws CourseKindException the course holds a word (active or not) of the kind it would stop being
     */
    public function assertExamTagChange(Course $course, ?string $newTag): void
    {
        $toPhrases = Phrase::isPhraseCourse($newTag);
        if (Phrase::isPhraseCourse($course->getOriginal('exam_tag')) === $toPhrases) {
            return;
        }

        // ONE statement: a word of the course whose kind is not the new one (lessons by `stage_id`, words by `lesson_id`).
        $mismatch = Word::query()
            ->whereIn('lesson_id', Lesson::query()->select('lessons.id')->whereIn('stage_id', Stage::query()->select('stages.id')->where('course_id', $course->id)))
            ->where(fn ($q) => $toPhrases
                ? $q->whereNull('part_of_speech')->orWhere('part_of_speech', '<>', Phrase::PART_OF_SPEECH)
                : $q->where('part_of_speech', Phrase::PART_OF_SPEECH))
            ->exists();
        if ($mismatch) {
            throw new CourseKindException($toPhrases);
        }
    }
}
