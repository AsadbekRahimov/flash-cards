<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\DTO\LearnerWordStages;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\DTO\LevelReadiness;
use App\Domain\Learning\DTO\LevelReadinessScreen;
use App\Domain\Learning\Enums\WordStage;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * Readiness to the level (docs/13 §8.5): the share of the active words of a course in the stage `known` or `mastered`
 * (docs/06 §13.3). Pure arithmetic over the counts the catalog already reads for "Today"
 * ({@see CourseCatalogService::lessonsStarted()}); nothing is cached (the figure changes with every grade).
 *
 *   percent     = floor(100 * learned / total), at most 100, 0 for a course without words
 *   in_progress = started - learned;  not_started = max(0, total - started);  known = learned - mastered
 */
final class LevelReadinessService
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
        private readonly LevelQuizService $quizzes,
        private readonly ProductEventRecorder $events,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('readiness.enabled', true);
    }

    public function fromCounts(Course $course, LearnerWordStages $stages): LevelReadiness
    {
        $total = max(0, $course->words_count);
        $started = max(0, $stages->started);
        $learned = max(0, min($stages->learned, $started));
        $mastered = max(0, min($stages->mastered, $learned));

        $label = $course->meta['level_label'] ?? $course->level;
        $label = is_string($label) && trim($label) !== '' ? trim($label) : null;

        return new LevelReadiness(
            courseId: $course->id,
            levelLabel: $label,
            total: $total,
            learned: $learned,
            mastered: $mastered,
            known: $learned - $mastered,
            inProgress: $started - $learned,
            notStarted: max(0, $total - $started),
            percent: $total > 0 ? min(100, intdiv(100 * $learned, $total)) : 0,
        );
    }

    /**
     * The full screen: the readiness and the modules (`learned / total`), from the learner's one statement beside the cached tree.
     *
     * @return array{readiness: LevelReadiness, modules: list<array{number: int, title: string|null, learned: int, total: int}>}
     */
    public function forCourse(Course $course, Learner $learner): array
    {
        $tree = $this->catalog->tree($course, $this->locales->forLearner($learner));
        $lessons = $this->catalog->lessonsStarted($course, $learner);

        $started = 0;
        $learned = 0;
        $mastered = 0;
        foreach ($lessons as $counts) {
            $started += $counts['started'];
            $learned += $counts['learned'];
            $mastered += $counts['mastered'];
        }

        /** @var array<int, array{number: int, title: string|null, learned: int, total: int}> $modules */
        $modules = [];
        foreach ($tree as $row) {
            $number = $row['stage_number'];
            $modules[$number] ??= ['number' => $number, 'title' => $row['stage_title'], 'learned' => 0, 'total' => 0];
            $modules[$number]['total'] += $row['total'];
            $modules[$number]['learned'] += min($lessons[$row['lesson_id']]['learned'] ?? 0, $row['total']);
        }
        ksort($modules);

        return [
            'readiness' => $this->fromCounts($course, new LearnerWordStages($started, $learned, $mastered)),
            'modules' => array_values($modules),
        ];
    }

    /**
     * The whole screen: readiness + modules (the learner's one statement beside the cached tree) and the quiz of the week with
     * its history (one more statement, none when the quiz is off). Records `readiness.viewed`.
     *
     * @param  string  $source  where the learner came from: today | profile | course
     */
    public function screen(Course $course, Learner $learner, string $source, ?CarbonImmutable $now = null): LevelReadinessScreen
    {
        $built = $this->forCourse($course, $learner);
        $readiness = $built['readiness'];
        $quiz = $this->quizzes->overview($learner, $course, $now);

        $this->events->record(ProductEventName::ReadinessViewed, [
            'course_id' => $course->id,
            'percent' => $readiness->percent,
            'learned' => $readiness->learned,
            'total' => $readiness->total,
            'source' => in_array($source, ['today', 'profile', 'course'], true) ? $source : 'unknown',
        ], learner: $learner);

        return new LevelReadinessScreen(
            $course,
            $this->catalog->title($course, $this->locales->forLearner($learner)),
            $readiness,
            $built['modules'],
            $this->thresholds(),
            $quiz['week'],
            $this->quizzes->questionCount(),
            $this->quizzes->seconds(),
            $quiz['history'],
        );
    }

    /** @return array{known_days: int, mastered_days: int} the thresholds the stages are read with */
    public function thresholds(): array
    {
        $t = WordStage::thresholds();

        return ['known_days' => $t['known'], 'mastered_days' => $t['mastered']];
    }
}
