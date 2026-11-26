<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * The learner's active course for the "Today" screen: the course, their progress and the lesson to take new words from (null once all
 * were served). `lessonTitles`: lesson id => the title the learner reads, from the same cached tree (the day plan names the lesson
 * studied today by it, no statement).
 */
final readonly class CourseToday
{
    /** @param  array<int, string|null>  $lessonTitles */
    public function __construct(
        public CatalogCourse $course,
        public LearnerProgress $progress,
        public ?NextLesson $nextLesson,
        public LearnerWordStages $stages = new LearnerWordStages(0, 0, 0),
        public array $lessonTitles = [],
    ) {}
}
