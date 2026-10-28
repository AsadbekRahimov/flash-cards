<?php

declare(strict_types=1);

/*
 * TrainingSessionService::ensureStillAvailable() — the re-check that every /start, /next and /review of a LESSON
 * session runs (TrainingController::resolve). Only a running session on a private course of a CENTER is judged
 * again (assignment gone, learner out of the group, center suspended): the session is then closed `expired` and
 * the step answers 404 `lesson_not_available`. Everything else answers "still available" without a verdict —
 * including a public course that was archived meanwhile, a lesson that cannot be found, group and daily sessions.
 *
 * The cases are pinned before the check was reduced from three queries (lesson, stage, course) to one.
 */

use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/**
 * A learner with an open lesson session on a one-lesson course.
 *
 * @param  array<string, mixed>  $course  attributes of the course
 * @return array{learner: Learner, course: Course, lesson: Lesson, session: TrainingSession}
 */
function availabilityScenario(array $course = [], bool $ownDeck = false): array
{
    $learner = Learner::factory()->create();
    if ($ownDeck) {
        $course += ['owner_type' => 'learner', 'owner_id' => $learner->id, 'source' => 'learner', 'visibility' => 'private'];
    }
    ['course' => $courseModel, 'lessons' => $lessons] = Fx::course([1 => [1 => 3]], $course);
    $lesson = $lessons['1.1'];

    return [
        'learner' => $learner,
        'course' => $courseModel,
        'lesson' => $lesson,
        'session' => app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson),
    ];
}

/**
 * A center's private course whose lesson is assigned to a group the learner belongs to.
 *
 * @return array{learner: Learner, course: Course, lesson: Lesson, session: TrainingSession, organization: Organization, group: TelegramGroup, assignment: Assignment}
 */
function centerScenario(): array
{
    $organization = Organization::factory()->create();
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 3]], ['visibility' => 'private']);
    $lesson = $lessons['1.1'];
    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();
    $assignment = Assignment::factory()->create([
        'organization_id' => $organization->id,
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'due_at' => now()->addHours(10),
    ]);

    return [
        'learner' => $learner,
        'course' => $course,
        'lesson' => $lesson,
        'session' => app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson),
        'organization' => $organization,
        'group' => $group,
        'assignment' => $assignment,
    ];
}

it('keeps a lesson session of a public, an unlisted or the learner\'s own course', function (string $kind): void {
    $s = match ($kind) {
        'public' => availabilityScenario(),
        'unlisted' => availabilityScenario(['visibility' => 'unlisted']),
        default => availabilityScenario(ownDeck: true),
    };

    expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeTrue()
        ->and($s['session']->fresh()?->status)->toBe('open');
})->with(['public', 'unlisted', 'own deck']);

it('does not judge the status of a public course: an archived one keeps its running session', function (): void {
    $s = availabilityScenario();
    $s['course']->update(['status' => 'archived']);

    expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeTrue()
        ->and($s['session']->fresh()?->status)->toBe('open');
});

it('closes the session of a course that turned private under it when nothing is assigned', function (): void {
    $s = availabilityScenario();
    $s['course']->update(['visibility' => 'private']);

    expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeFalse();

    $closed = $s['session']->fresh();
    expect($closed?->status)->toBe('closed')->and($closed?->done_reason)->toBe(TrainingSession::DONE_EXPIRED);
});

it('keeps a center\'s private lesson while it is assigned to a group the learner is in', function (): void {
    $s = centerScenario();

    expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeTrue()
        ->and($s['session']->fresh()?->status)->toBe('open');
});

it('closes a center\'s private lesson session when the assignment is closed', function (): void {
    $s = centerScenario();
    app(AssignmentService::class)->close($s['assignment']);

    expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeFalse()
        ->and($s['session']->fresh()?->status)->toBe('closed')
        ->and($s['session']->fresh()?->done_reason)->toBe(TrainingSession::DONE_EXPIRED);
});

it('closes a center\'s private lesson session when the learner left the group', function (): void {
    $s = centerScenario();
    GroupMember::query()->where('learner_id', $s['learner']->id)->update(['status' => 'left', 'left_at' => now()]);

    expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeFalse()
        ->and($s['session']->fresh()?->status)->toBe('closed');
});

it('closes a center\'s private lesson session when the center is suspended', function (): void {
    $s = centerScenario();
    $s['organization']->update(['status' => 'suspended']);

    expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeFalse()
        ->and($s['session']->fresh()?->status)->toBe('closed');
});

it('answers 404 lesson_not_available over HTTP for a closed assignment, then 410 for the session', function (): void {
    $s = centerScenario();
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($s['learner']->id)['token']];

    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$s['session']->id}/next")->assertOk();

    app(AssignmentService::class)->close($s['assignment']);

    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertNotFound()->assertJsonPath('error.code', 'lesson_not_available');
    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertStatus(410);
});

it('has nothing to re-check for group and daily sessions, and nothing to judge for a lesson that cannot be found', function (): void {
    $learner = Learner::factory()->create();
    $service = app(TrainingSessionService::class);

    $daily = $service->openPersonal($learner, 'daily_review');
    $group = TrainingSession::factory()->create(['mode' => TrainingSession::MODE_GROUP_LESSON]);
    $orphan = new TrainingSession(['mode' => TrainingSession::MODE_LESSON, 'learner_id' => $learner->id, 'lesson_id' => 987654]);

    $queriesFor = fn (TrainingSession $session): array => QueryLog::during(function () use ($service, $session, $learner): void {
        expect($service->ensureStillAvailable($session, $learner))->toBeTrue();
    });

    // Decided from the session row alone ...
    expect($queriesFor($daily))->toBe([])
        ->and($queriesFor($group))->toBe([])
        // ... and one lookup finds that there is no lesson to judge.
        ->and($queriesFor($orphan))->toHaveCount(1);
});

it('answers for a public, an unlisted or an own-deck course with ONE statement that loads nothing', function (string $kind): void {
    $s = match ($kind) {
        'public', 'archived' => availabilityScenario(),
        'unlisted' => availabilityScenario(['visibility' => 'unlisted']),
        default => availabilityScenario(ownDeck: true),
    };
    if ($kind === 'archived') {
        $s['course']->update(['status' => 'archived']); // the running session is not judged on the status
    }

    // Before: lesson, then its stage, then its course (three statements, three models).
    $queries = QueryLog::during(function () use ($s): void {
        expect(app(TrainingSessionService::class)->ensureStillAvailable($s['session'], $s['learner']))->toBeTrue();
    });

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toStartWith('select exists(');
})->with(['public', 'unlisted', 'archived', 'own deck']);
