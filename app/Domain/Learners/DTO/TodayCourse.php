<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Content\Support\Phrase;
use App\Domain\Learning\DTO\LevelQuizWeek;
use App\Domain\Learning\DTO\LevelReadiness;

/**
 * The learner's active course on the "Today" screen, localized for the learner. `learned` / `total`
 * count active words (as everywhere in the catalog); `nextLesson` is null once every word was served. `readiness` and `quiz`
 * (docs/13 §8.5) are null while `readiness.enabled` is off; `quiz` is also null for a course without the quiz line. `section` - the
 * section of the catalog the course is listed in (`phrases` | `courses`, {@see Phrase::sectionOf()}).
 */
final readonly class TodayCourse
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public int $learned,
        public int $total,
        public ?TodayLesson $nextLesson,
        public ?LevelReadiness $readiness = null,
        public ?LevelQuizWeek $quiz = null,
        public string $section = Phrase::SECTION_COURSES,
    ) {}
}
