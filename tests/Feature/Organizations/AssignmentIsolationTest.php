<?php

declare(strict_types=1);

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\AssignmentException;
use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Twa\Services\JwtService;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;
use Tests\Support\FakeTelegramClient;

/*
 * Review 2B.8 (docs/13 "Реализация 2B.8"): a group that left a center takes its assignments with it
 * (no data of the new center's learners leaks to the old owner), a suspended center posts nothing,
 * the assigned lesson of a private course opens for the group's learners, a transport failure of a
 * one-time post keeps the claim, the learner list reads without writing.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->service = app(AssignmentService::class);

    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b']);
    $this->ownerA = isoOwner($this->a);
    $this->course = Course::factory()->published()->create();
    $this->stage = Stage::factory()->create(['course_id' => $this->course->id, 'number' => 1]);
    $this->lesson = Lesson::factory()->create(['stage_id' => $this->stage->id, 'number' => 1, 'title' => 'Food']);
    $this->words = Word::factory()->count(4)->for($this->lesson)->create(['is_active' => true]);
    $this->group = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => $this->course->id, 'status' => 'active']);
    $this->learnerA = Learner::factory()->memberOf($this->group)->create();
    $this->assignment = Assignment::factory()->create([
        'organization_id' => $this->a->id,
        'telegram_group_id' => $this->group->id,
        'lesson_id' => $this->lesson->id,
        'due_at' => now()->addHours(10),
    ]);
});

function isoOwner(Organization $organization): User
{
    $user = User::factory()->create(['is_active' => true, 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => OrganizationRole::Owner->value]);

    return $user;
}

/** Moves the group from A to B the way the owner / superadmin does, and puts a learner of B into it. */
function isoRelink(object $t): Learner
{
    $admin = User::factory()->create();
    $linker = app(OrganizationGroupLinker::class);
    $linker->unlink($t->a, $t->group, $admin);
    $linker->link($t->b, $t->group->refresh(), $admin);

    return Learner::factory()->memberOf($t->group)->create();
}

// --- #1 isolation after unlink / relink -------------------------------------------------------------

it('closes the active assignments of a group when it is unlinked, keeping the snapshot', function (): void {
    $done = Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'status' => Assignment::STATUS_CLOSED]);
    $otherGroup = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => $this->course->id]);
    $untouched = Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $otherGroup->id, 'lesson_id' => $this->lesson->id]);
    AssignmentResult::factory()->create(['assignment_id' => $this->assignment->id, 'learner_id' => $this->learnerA->id, 'progress_pct' => 50]);

    app(OrganizationGroupLinker::class)->unlink($this->a, $this->group, User::factory()->create());

    $fresh = $this->assignment->fresh();
    expect($fresh->status)->toBe(Assignment::STATUS_CLOSED)
        ->and($fresh->meta['closed_reason'])->toBe('group_unlinked')
        ->and($done->fresh()->meta)->not->toHaveKey('closed_reason')
        ->and($untouched->fresh()->status)->toBe(Assignment::STATUS_ACTIVE)
        ->and(AssignmentResult::query()->where('assignment_id', $this->assignment->id)->count())->toBe(1);
});

it('closes them on any other path that changes the group\'s organization (direct save)', function (): void {
    $this->group->update(['organization_id' => $this->b->id]);

    expect($this->assignment->fresh()->status)->toBe(Assignment::STATUS_CLOSED);
});

it('hides the old center\'s assignment from its owner after the group moved, even if it were still active', function (): void {
    isoRelink($this);
    // Belt and braces: the query-level rule must hold without the closing above.
    Assignment::query()->whereKey($this->assignment->id)->update(['status' => Assignment::STATUS_ACTIVE]);
    $scope = app(OrgTenantScope::class);

    expect($scope->assignments($this->ownerA, $this->a)->count())->toBe(0)
        ->and($scope->canSeeAssignment($this->ownerA, $this->a, $this->assignment->fresh()))->toBeFalse();

    $this->actingAs($this->ownerA, 'org')->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $this->ownerA->id]);
    $this->get("/org/center-a/assignments/{$this->assignment->id}")->assertNotFound();
});

it('does not measure, post to or show the new center\'s learners for the old assignment', function (): void {
    $learnerB = isoRelink($this);
    Assignment::query()->whereKey($this->assignment->id)->update(['status' => Assignment::STATUS_ACTIVE]);
    $assignment = $this->assignment->fresh();
    WordRepetition::factory()->create(['learner_id' => $learnerB->id, 'word_id' => $this->words[0]->id, 'repetitions' => 1]);

    expect($this->service->refreshResults($assignment))->toBe(0)
        ->and($this->service->refreshActive())->toBe(0)
        ->and($this->service->remindDue())->toBe(0)
        ->and($this->service->announcePending())->toBe(0)
        ->and($this->service->announce($assignment))->toBeFalse()
        ->and($this->service->forLearner($learnerB))->toBe([])
        ->and(AssignmentResult::query()->count())->toBe(0)
        ->and($this->tg->sent)->toBe([]);
});

// --- #3 a private course ---------------------------------------------------------------------------

it('refuses an assignment in a draft course and in an inactive group', function (): void {
    $this->course->update(['status' => 'draft']);
    $code = function (callable $call): string {
        try {
            $call();
        } catch (AssignmentException $e) {
            return $e->errorCode;
        }

        return 'none';
    };

    expect($code(fn () => $this->service->create($this->a, $this->group->refresh(), $this->lesson, now()->addDay())))->toBe(AssignmentException::LESSON_NOT_AVAILABLE);

    $this->course->update(['status' => 'published']);
    $this->group->update(['status' => 'disabled']);
    expect($code(fn () => $this->service->create($this->a, $this->group->refresh(), $this->lesson, now()->addDay())))->toBe(AssignmentException::GROUP_INACTIVE);
});

it('lets the learners of the group open the assigned lesson of a private course, nobody else', function (): void {
    $this->course->update(['visibility' => 'private']);
    $sessions = app(TrainingSessionService::class);
    $outsider = Learner::factory()->create();
    $otherGroupLearner = Learner::factory()->memberOf(TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => $this->course->id]))->create();
    $otherLesson = Lesson::factory()->create(['stage_id' => $this->stage->id, 'number' => 2]);

    expect($sessions->openPersonal($this->learnerA, 'lesson', $this->lesson)->lesson_id)->toBe($this->lesson->id);

    foreach ([[$outsider, $this->lesson], [$otherGroupLearner, $this->lesson], [$this->learnerA, $otherLesson]] as [$learner, $lesson]) {
        expect(fn () => $sessions->openPersonal($learner, 'lesson', $lesson))->toThrow(TrainingSessionException::class);
    }

    // A closed assignment, a left learner or a suspended center no longer opens it.
    $left = Learner::factory()->memberOf($this->group, 'left')->create();
    expect(fn () => $sessions->openPersonal($left, 'lesson', $this->lesson))->toThrow(TrainingSessionException::class);

    $this->a->update(['status' => 'suspended']);
    expect(fn () => $sessions->openPersonal($this->learnerA, 'lesson', $this->lesson))->toThrow(TrainingSessionException::class);
});

// --- #4 transport failure keeps the claim ----------------------------------------------------------------

it('keeps the claim when the post failed on the network, releases it when Telegram refused', function (): void {
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;

    expect($this->service->announce($this->assignment))->toBeFalse();
    expect($this->assignment->fresh()->announced_at)->not->toBeNull()
        ->and($this->assignment->fresh()->meta['announce_uncertain'])->toBeTrue();
    expect($this->service->announce($this->assignment->fresh()))->toBeFalse(); // not posted twice

    $second = Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id]);
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = false;

    expect($this->service->announce($second))->toBeFalse();
    expect($second->fresh()->announced_at)->toBeNull()->and($second->fresh()->meta['announced_at_failures'])->toBe(1);
});

it('tells an API answer from a transport failure', function (): void {
    $answer = fn (int $status): Throwable => (new TelegramResponse(
        new TelegramRequest('token', 'POST', 'sendMessage'),
        new PsrResponse($status, [], json_encode(['ok' => false, 'error_code' => $status, 'description' => 'x'], JSON_THROW_ON_ERROR)),
    ))->getThrownException();

    expect(TelegramApiException::fromSdk($answer(400))->isApiResponse())->toBeTrue()
        ->and(TelegramApiException::fromSdk($answer(429))->isApiResponse())->toBeTrue()
        ->and(TelegramApiException::fromSdk($answer(502))->isApiResponse())->toBeFalse()
        ->and(TelegramApiException::fromSdk(new RuntimeException('cURL error 28'))->isApiResponse())->toBeFalse()
        ->and((new TelegramApiException('x'))->isApiResponse())->toBeFalse()
        ->and(TelegramApiException::apiError('x')->isApiResponse())->toBeTrue();
});

// --- #7 the learner list is cheap and does not write on every call ---------------------------------

it('recounts only stale snapshots and its query count does not grow with the assignments', function (): void {
    foreach (range(1, 2) as $i) {
        Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'due_at' => now()->addDays($i + 1)]);
    }
    WordRepetition::factory()->create(['learner_id' => $this->learnerA->id, 'word_id' => $this->words[0]->id, 'repetitions' => 1]);

    $this->service->forLearner($this->learnerA); // cold: writes the snapshots

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->forLearner($this->learnerA);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($log as $entry) {
            expect($entry['query'])->not->toStartWith('insert')->not->toStartWith('update');
        }

        return count($log);
    };

    $before = $count();

    foreach (range(1, 6) as $i) {
        Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'due_at' => now()->addDays($i + 3)]);
    }
    $this->service->forLearner($this->learnerA);

    expect($count())->toBe($before);

    // A snapshot older than the stale window is recounted.
    WordRepetition::factory()->create(['learner_id' => $this->learnerA->id, 'word_id' => $this->words[1]->id, 'repetitions' => 1]);
    $items = $this->service->forLearner($this->learnerA, CarbonImmutable::now()->addMinutes(11));

    expect($items[0]->progressPct)->toBe(50);
});

// --- #8 a suspended center posts nothing -------------------------------------------------------------

it('does not announce, remind or recount for a suspended center', function (): void {
    $this->a->update(['status' => 'suspended']);

    expect($this->service->announcePending())->toBe(0)
        ->and($this->service->remindDue())->toBe(0)
        ->and($this->service->refreshActive())->toBe(0)
        ->and($this->service->announce($this->assignment))->toBeFalse()
        ->and($this->assignment->fresh()->announced_at)->toBeNull();

    $this->a->update(['status' => 'active']);
    expect($this->service->announcePending())->toBe(1);
});

// --- re-review: one dead chat does not stop the run -------------------------------------------------------------

it('lets one transport failure pass: the other assignments, reminders and auto-close still run', function (): void {
    $second = Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'due_at' => now()->addHours(10)]);
    $expired = Assignment::factory()->create([
        'organization_id' => $this->a->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id,
        'due_at' => now()->subDays(30), 'announced_at' => now()->subDays(40), 'reminded_at' => now()->subDays(31),
    ]);
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;

    $this->artisan('assignments:refresh')->assertSuccessful();

    $first = $this->assignment->fresh();
    expect($first->announced_at)->not->toBeNull()
        ->and($first->meta['announce_uncertain'])->toBeTrue()
        ->and($second->fresh()->announced_at)->not->toBeNull()
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($expired->fresh()->status)->toBe(Assignment::STATUS_CLOSED);
});

it('runs every step of assignments:refresh even if one throws, and ends with FAILURE', function (): void {
    $expired = Assignment::factory()->create([
        'organization_id' => $this->a->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id,
        'due_at' => now()->subDays(30), 'announced_at' => now()->subDays(40), 'reminded_at' => now()->subDays(31),
    ]);
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->andThrow(new RuntimeException('boom'));
    $this->app->instance(TelegramClient::class, $telegram);
    $this->app->forgetInstance(AssignmentService::class);

    $this->artisan('assignments:refresh')->assertFailed();

    expect($expired->fresh()->status)->toBe(Assignment::STATUS_CLOSED);
});

// --- re-review: fresh progress, lessons, suspended centers, group race ------------------------------------------

it('shows the progress right after the words were repeated, without waiting for the stale window', function (): void {
    expect($this->service->forLearner($this->learnerA)[0]->progressPct)->toBe(0);

    WordRepetition::factory()->create(['learner_id' => $this->learnerA->id, 'word_id' => $this->words[0]->id, 'repetitions' => 1, 'updated_at' => now()->addSecond()]);
    WordRepetition::factory()->create(['learner_id' => $this->learnerA->id, 'word_id' => $this->words[1]->id, 'repetitions' => 1, 'updated_at' => now()->addSecond()]);

    expect($this->service->forLearner($this->learnerA, CarbonImmutable::now()->addSeconds(5))[0]->progressPct)->toBe(50);
});

it('does not open a lesson of a learner\'s personal deck through an assignment', function (): void {
    $this->course->update(['visibility' => 'private', 'owner_type' => 'learner', 'owner_id' => Learner::factory()->create()->id]);

    expect(fn () => app(TrainingSessionService::class)->openPersonal($this->learnerA, 'lesson', $this->lesson))->toThrow(TrainingSessionException::class);
});

it('refuses to set an assignment for a suspended center', function (): void {
    $this->a->update(['status' => 'suspended']);

    try {
        $this->service->create($this->a->refresh(), $this->group, $this->lesson, now()->addDay());
        $code = 'none';
    } catch (AssignmentException $e) {
        $code = $e->errorCode;
    }

    expect($code)->toBe(AssignmentException::ORGANIZATION_SUSPENDED)->and(Assignment::query()->count())->toBe(1);
});

it('checks the group\'s organization on the locked row, not on the object it was handed', function (): void {
    $stale = $this->group; // still says "center A"
    TelegramGroup::query()->whereKey($this->group->id)->update(['organization_id' => $this->b->id]);

    expect(fn () => $this->service->create($this->a, $stale, $this->lesson, now()->addDay()))->toThrow(AssignmentException::class);
    expect(Assignment::query()->count())->toBe(1);
});

it('closes a running lesson session when the assignment is gone, with 404 lesson_not_available', function (): void {
    $this->course->update(['visibility' => 'private']);
    $session = app(TrainingSessionService::class)->openPersonal($this->learnerA, 'lesson', $this->lesson);
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($this->learnerA->id)['token']];

    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$session->id}/next")->assertOk();

    $this->service->close($this->assignment);

    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$session->id}/next")
        ->assertNotFound()->assertJsonPath('error.code', 'lesson_not_available');
    expect($session->fresh()->status)->toBe('closed');

    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$session->id}/review", ['word_id' => $this->words[0]->id, 'quality' => 4, 'time_spent_ms' => 1000])
        ->assertStatus(410);
});
