<?php

declare(strict_types=1);

/*
 * The arithmetic of the readiness to the level (docs/13 §8.5, docs/06 §13.3): the share of the active words of a course in
 * `review` with an interval from the "known" threshold, as a floor.
 */

use App\Domain\Content\DTO\LearnerWordStages;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Services\LevelReadinessService;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

function readinessService(): LevelReadinessService
{
    return app(LevelReadinessService::class);
}

function readinessCourse(int $words, ?string $level = 'B1', array $meta = []): Course
{
    $course = Course::factory()->published()->make(['words_count' => $words, 'level' => $level, 'meta' => $meta]);
    $course->id = 1;

    return $course;
}

it('computes the documented example: 430 started, 310 learned, 120 mastered of 1000 -> 31 %', function (): void {
    $r = readinessService()->fromCounts(readinessCourse(1000), new LearnerWordStages(430, 310, 120));

    expect($r->percent)->toBe(31)
        ->and($r->total)->toBe(1000)
        ->and($r->learned)->toBe(310)
        ->and($r->mastered)->toBe(120)
        ->and($r->known)->toBe(190)
        ->and($r->inProgress)->toBe(120)
        ->and($r->notStarted)->toBe(570)
        ->and($r->toArray()['words'])->toBe(['total' => 1000, 'learned' => 310, 'mastered' => 120, 'in_progress' => 120, 'not_started' => 570]);
});

it('takes the floor of the share, never rounding a near-complete course up to 100', function (): void {
    expect(readinessService()->fromCounts(readinessCourse(1000), new LearnerWordStages(1000, 995, 0))->percent)->toBe(99)
        ->and(readinessService()->fromCounts(readinessCourse(3), new LearnerWordStages(3, 2, 0))->percent)->toBe(66)
        ->and(readinessService()->fromCounts(readinessCourse(1000), new LearnerWordStages(1000, 1000, 10))->percent)->toBe(100);
});

it('answers 0 % for a course without words and never goes negative or above 100 when the counts run ahead of the total', function (): void {
    $empty = readinessService()->fromCounts(readinessCourse(0), new LearnerWordStages(0, 0, 0));
    expect($empty->percent)->toBe(0)->and($empty->notStarted)->toBe(0);

    // Words added since the counter was recounted: started and learned exceed the total for a moment.
    $ahead = readinessService()->fromCounts(readinessCourse(10), new LearnerWordStages(14, 12, 3));
    expect($ahead->percent)->toBe(100)
        ->and($ahead->notStarted)->toBe(0)
        ->and($ahead->inProgress)->toBe(2);

    $none = readinessService()->fromCounts(readinessCourse(100), new LearnerWordStages(0, 0, 0));
    expect($none->percent)->toBe(0)->and($none->notStarted)->toBe(100)->and($none->inProgress)->toBe(0);
});

it('names the level by meta.level_label first, then by the level of the course, then by nothing', function (): void {
    $stages = new LearnerWordStages(0, 0, 0);

    expect(readinessService()->fromCounts(readinessCourse(10, 'A1', ['level_label' => 'A1–A2']), $stages)->levelLabel)->toBe('A1–A2')
        ->and(readinessService()->fromCounts(readinessCourse(10, 'B1'), $stages)->levelLabel)->toBe('B1')
        ->and(readinessService()->fromCounts(readinessCourse(10, null), $stages)->levelLabel)->toBeNull()
        ->and(readinessService()->fromCounts(readinessCourse(10, '  ', ['level_label' => '']), $stages)->levelLabel)->toBeNull();
});

it('reads the stages from the learner\'s rows: review from the known interval is learned, relearning and short intervals are in progress', function (): void {
    config(['learning.stages.known_from_interval_days' => 7, 'learning.stages.mastered_from_interval_days' => 21]);
    ['course' => $course, 'lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => 7, 2 => 3]]);
    $learner = Learner::factory()->create();
    $ids = $words['1.1']['active'];
    $row = fn (int $wordId, string $state, int $interval, int $reps = 3) => DB::table('word_repetitions')->insert([
        'learner_id' => $learner->id, 'word_id' => $wordId, 'repetitions' => $reps, 'interval_days' => $interval, 'state' => $state,
        'next_review_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row($ids[0], 'review', 30);     // mastered
    $row($ids[1], 'review', 21);     // mastered (the threshold itself)
    $row($ids[2], 'review', 7);      // known (the threshold itself)
    $row($ids[3], 'review', 6);      // in progress
    $row($ids[4], 'relearning', 0);  // in progress: failed
    $row($ids[5], 'learning', 0, 0); // in progress
    $row($words['1.2']['active'][0], 'review', 10); // known, other lesson

    $counts = app(CourseCatalogService::class)->lessonsStarted($course, $learner);
    expect($counts[$lessons['1.1']->id])->toBe(['started' => 6, 'done' => 5, 'learned' => 3, 'mastered' => 2])
        ->and($counts[$lessons['1.2']->id])->toBe(['started' => 1, 'done' => 1, 'learned' => 1, 'mastered' => 0]);

    $screen = readinessService()->forCourse($course, $learner);
    expect($screen['readiness']->percent)->toBe(40) // 4 of 10
        ->and($screen['readiness']->learned)->toBe(4)
        ->and($screen['readiness']->mastered)->toBe(2)
        ->and($screen['readiness']->known)->toBe(2)
        ->and($screen['readiness']->inProgress)->toBe(3)
        ->and($screen['readiness']->notStarted)->toBe(3)
        ->and($screen['modules'])->toHaveCount(1)
        ->and($screen['modules'][0])->toMatchArray(['number' => 1, 'learned' => 4, 'total' => 10]);
});

it('moves with the thresholds of the config: from 10 days a card of 7 is no longer learned', function (): void {
    config(['learning.stages.known_from_interval_days' => 10, 'learning.stages.mastered_from_interval_days' => 30]);
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 4]]);
    $learner = Learner::factory()->create();
    foreach ([7 => 0, 10 => 1, 29 => 2, 30 => 3] as $interval => $i) {
        DB::table('word_repetitions')->insert([
            'learner_id' => $learner->id, 'word_id' => $words['1.1']['active'][$i], 'repetitions' => 2, 'interval_days' => $interval, 'state' => 'review',
            'next_review_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $r = readinessService()->forCourse($course, $learner);

    expect($r['readiness']->learned)->toBe(3)
        ->and($r['readiness']->mastered)->toBe(1)
        ->and($r['readiness']->percent)->toBe(75)
        ->and(readinessService()->thresholds())->toBe(['known_days' => 10, 'mastered_days' => 30]);
});

it('counts only active words and the words of this course', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => [3, 2]]]);
    ['words' => $foreign] = Fx::course([1 => [1 => 2]]);
    $learner = Learner::factory()->create();
    Fx::learn($learner, [...$words['1.1']['active'], ...$words['1.1']['inactive'], ...$foreign['1.1']['active']]);
    DB::table('word_repetitions')->where('learner_id', $learner->id)->update(['interval_days' => 9]);

    $r = readinessService()->forCourse($course, $learner)['readiness'];

    expect($r->learned)->toBe(3)->and($r->total)->toBe(3)->and($r->percent)->toBe(100);
});
