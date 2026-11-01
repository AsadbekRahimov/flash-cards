<?php

declare(strict_types=1);

use App\Domain\Learning\Services\PracticeEntryResolver;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §11.1: `practice_entry` of POST /training/sessions/{id}/finish - where "Practice of the lesson / of the course" leads
 * after a training session. A lesson session leads to its lesson, a daily review to the course it takes new words from; none
 * for a daily review without a course, a group session, a practice that is closed to the learner and a course the hub could
 * not open. The material is not looked at (the hub explains "not enough"), and that is what keeps `finish` light.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => PracticeKit::openAccess());

/** @return array{0: Learner, 1: Course, 2: Lesson, 3: Lesson} a learner and a published course of two lessons in module 2 */
function entryWorld(): array
{
    ['course' => $course, 'lessons' => $lessons] = Fx::course([2 => [4 => 3, 5 => 3]], ['slug' => 'english-a1']);
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);

    return [$learner, $course, $lessons['2.4'], $lessons['2.5']];
}

function entryLessonSession(Learner $learner, Lesson $lesson): TrainingSession
{
    return TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);
}

function entryReviewSession(Learner $learner, ?Course $course): TrainingSession
{
    return TrainingSession::factory()->personal($learner, 'daily_review')->create(['course_id' => $course?->id]);
}

function entryFinish(object $test, Learner $learner, TrainingSession $session): TestResponse
{
    return $test->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/training/sessions/{$session->id}/finish");
}

// --- the entry of a lesson and of a course ----------------------------------------------------------

it('leads a finished lesson session to the practice of that lesson', function (): void {
    [$learner, $course, $lesson] = entryWorld();

    entryFinish($this, $learner, entryLessonSession($learner, $lesson))
        ->assertOk()
        ->assertJsonPath('practice_entry', ['course' => $course->slug, 'lesson_id' => $lesson->id]);
});

it('leads a finished daily review to the practice of the course it takes new words from', function (): void {
    [$learner, $course] = entryWorld();

    entryFinish($this, $learner, entryReviewSession($learner, $course))
        ->assertOk()
        ->assertJsonPath('practice_entry', ['course' => $course->slug, 'lesson_id' => null]);
});

it('has no entry after a daily review without a course', function (): void {
    [$learner] = entryWorld();

    entryFinish($this, $learner, entryReviewSession($learner, null))
        ->assertOk()
        ->assertJsonPath('practice_entry', null);
});

it('tells the same entry on a repeated finish (the call is idempotent)', function (): void {
    [$learner, $course, $lesson] = entryWorld();
    $session = entryLessonSession($learner, $lesson);

    $first = entryFinish($this, $learner, $session)->assertOk()->json();
    $again = entryFinish($this, $learner, $session)->assertOk()->json();

    expect($again['practice_entry'])->toBe(['course' => $course->slug, 'lesson_id' => $lesson->id])
        ->and($again)->toBe($first);
});

it('does not ask the material: a lesson without any word or task still has its entry', function (): void {
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 0]], ['slug' => 'empty-course']);
    $learner = Learner::factory()->create();

    entryFinish($this, $learner, entryLessonSession($learner, $lessons['1.1']))
        ->assertOk()
        ->assertJsonPath('practice_entry', ['course' => $course->slug, 'lesson_id' => $lessons['1.1']->id]);
});

// --- who sees it -------------------------------------------------------------------------------------

it('follows the access of the practice: the flag, the pilot, the tariff', function (string $access, string $plan, bool $pilot, bool $pro, bool $entry): void {
    config(['practice.access' => $access, 'practice.plan' => $plan]);
    [, $course, $lesson] = entryWorld();
    $learner = Learner::factory()->create(['practice_pilot_at' => $pilot ? now() : null]);
    if ($pro) {
        PracticeKit::pro($learner);
    }

    $response = entryFinish($this, $learner, entryLessonSession($learner, $lesson))->assertOk();

    expect($response->json('practice_entry'))->toBe($entry ? ['course' => $course->slug, 'lesson_id' => $lesson->id] : null);
})->with([
    'off' => ['off', 'all', true, true, false],
    'pilot, not in it' => ['pilot', 'all', false, false, false],
    'pilot, not in it, plan pro' => ['pilot', 'pro', false, true, false],
    'pilot, in it' => ['pilot', 'all', true, false, true],
    'all' => ['all', 'all', false, false, true],
    // behind Pro the entry is shown (like the button of the course): the hub offers the cards with a Pro chip
    'all, plan pro, free' => ['all', 'pro', false, false, true],
    'all, plan pro, pro' => ['all', 'pro', false, true, true],
    // a typo of the flag does not open the practice
    'unknown access' => ['everybody', 'all', true, true, false],
]);

it('has no entry for a group session, whoever asks', function (): void {
    [$member, , $lesson] = entryWorld();
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $session = TrainingSession::factory()->create(['telegram_group_id' => $group->id, 'lesson_id' => $lesson->id]);

    // a learner cannot finish a group session (the teacher does), so the resolver is asked directly
    $resolver = app(PracticeEntryResolver::class);
    expect($resolver->forSession($session, $member))->toBeNull()
        ->and($resolver->forSession($session, Learner::factory()->memberOf($group)->create()))->toBeNull();

    entryFinish($this, $member, $session)->assertForbidden();
});

it('never builds the entry of somebody else\'s session', function (): void {
    [$owner, , $lesson] = entryWorld();
    $session = entryLessonSession($owner, $lesson);

    expect(app(PracticeEntryResolver::class)->forSession($session, Learner::factory()->create()))->toBeNull();
});

// --- a course the hub could not open ------------------------------------------------------------------

it('has an entry in a course that is open by its link and in the learner\'s own deck', function (string $kind): void {
    $learner = Learner::factory()->create();
    $attributes = $kind === 'unlisted'
        ? ['visibility' => 'unlisted']
        : ['owner_type' => 'learner', 'owner_id' => $learner->id, 'source' => 'learner', 'visibility' => 'private'];
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 3]], ['slug' => "course-{$kind}", ...$attributes]);
    $learner->update(['active_course_id' => $course->id]);

    entryFinish($this, $learner, entryLessonSession($learner, $lessons['1.1']))
        ->assertJsonPath('practice_entry', ['course' => $course->slug, 'lesson_id' => $lessons['1.1']->id]);
    entryFinish($this, $learner, entryReviewSession($learner, $course))
        ->assertJsonPath('practice_entry', ['course' => $course->slug, 'lesson_id' => null]);
})->with(['unlisted', 'own deck']);

it('has no entry once the course is not published any more or has turned private', function (string $change): void {
    [$learner, $course, $lesson] = entryWorld();
    $lessonSession = entryLessonSession($learner, $lesson);
    $reviewSession = entryReviewSession($learner, $course);

    $course->update($change === 'archived' ? ['status' => 'archived'] : ['visibility' => 'private']);

    entryFinish($this, $learner, $lessonSession)->assertOk()->assertJsonPath('practice_entry', null);
    entryFinish($this, $learner, $reviewSession)->assertOk()->assertJsonPath('practice_entry', null);
})->with(['archived', 'turned private']);

it('has no entry in the private course of a center, even for the lesson that was assigned to the learner', function (): void {
    $organization = Organization::factory()->create();
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 3]], ['visibility' => 'private']);
    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();
    Assignment::factory()->create([
        'organization_id' => $organization->id,
        'telegram_group_id' => $group->id,
        'lesson_id' => $lessons['1.1']->id,
        'due_at' => now()->addHours(10),
    ]);

    // the lesson opens for the learner through the assignment, the hub of the course does not (it answers course_not_found)
    $session = entryLessonSession($learner, $lessons['1.1']);
    entryFinish($this, $learner, $session)->assertOk()->assertJsonPath('practice_entry', null);

    $hub = $this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lessons['1.1']->id}");
    $hub->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
});

it('has an entry that the hub opens: the same course, the same lesson', function (): void {
    [$learner, $course, $lesson] = entryWorld();
    $entry = entryFinish($this, $learner, entryLessonSession($learner, $lesson))->assertOk()->json('practice_entry');

    $this->withHeaders(PracticeKit::headers($learner))
        ->getJson("/api/twa/practice/hub?course={$entry['course']}&lesson_id={$entry['lesson_id']}")
        ->assertOk()
        ->assertJsonPath('course.slug', $course->slug)
        ->assertJsonPath('lesson.id', $lesson->id);
});

// --- the price --------------------------------------------------------------------------------------

it('costs one query beyond the finish it rides on: the lesson with its course, or the course of the review', function (string $kind): void {
    [$learner, $course, $lesson] = entryWorld();
    $session = $kind === 'lesson' ? entryLessonSession($learner, $lesson) : entryReviewSession($learner, $course);
    entryFinish($this, $learner, $session)->assertOk(); // closes it: what follows are the repeats, which write nothing

    $count = fn (): int => count(QueryLog::during(fn () => entryFinish($this, $learner, $session)->assertOk()));

    config(['practice.access' => 'off']);
    $closed = $count();
    config(['practice.access' => 'pilot']); // the learner is not in the pilot
    $notInPilot = $count();
    PracticeKit::openAccess();
    $open = $count();
    config(['practice.plan' => 'pro']); // a Free learner: the plan is read, from the cache that `finish` has filled itself
    $behindPro = $count();

    expect($open - $closed)->toBe(1)
        ->and($notInPilot)->toBe($closed)
        ->and($behindPro)->toBe($open);
})->with(['lesson', 'daily review']);

it('costs nothing beyond the finish for a daily review that has no course', function (): void {
    [$learner] = entryWorld();
    $session = entryReviewSession($learner, null);
    entryFinish($this, $learner, $session)->assertOk();

    $count = fn (): int => count(QueryLog::during(fn () => entryFinish($this, $learner, $session)->assertOk()));

    config(['practice.access' => 'off']);
    $closed = $count();
    PracticeKit::openAccess();

    expect($count())->toBe($closed);
});
