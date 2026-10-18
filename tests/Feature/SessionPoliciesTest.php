<?php

declare(strict_types=1);

use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Policies\ExamSessionPolicy;
use App\Policies\LearnerPolicy;
use App\Policies\TrainingSessionPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- TrainingSessionPolicy ----------------------------------------------------

it('lets a group training be used by active members of an active group only', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $session = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
    $policy = app(TrainingSessionPolicy::class);

    $member = Learner::factory()->memberOf($group)->create();
    $left = Learner::factory()->memberOf($group, 'left')->create();
    $stranger = Learner::factory()->create();

    expect($policy->canAccess($member, $session))->toBeTrue()
        ->and($policy->denial($member, $session))->toBeNull()
        ->and($policy->canAccess($left, $session))->toBeFalse()
        ->and($policy->denial($left, $session))->toBe('not_a_member')
        ->and($policy->denial($stranger, $session))->toBe('not_a_member');

    $group->update(['status' => 'disabled']);
    expect($policy->denial($member->fresh(), $session->fresh()))->toBe('group_inactive');
});

it('requires an open session to participate in a training', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $member = Learner::factory()->memberOf($group)->create();
    $policy = app(TrainingSessionPolicy::class);

    $open = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
    $closed = TrainingSession::factory()->closed()->create(['telegram_group_id' => $group->id]);

    expect($policy->canParticipate($member, $open))->toBeTrue()
        ->and($policy->canParticipate($member, $closed))->toBeFalse()
        // A closed session is still visible (410 vs 403 is the controller's call).
        ->and($policy->canAccess($member, $closed))->toBeTrue();
});

it('lets only the owner use a personal training session, whatever their groups are', function (): void {
    $owner = Learner::factory()->create();
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $classmate = Learner::factory()->memberOf($group)->create();
    $session = TrainingSession::factory()->personal($owner, 'daily_review')->create();
    $policy = app(TrainingSessionPolicy::class);

    expect($policy->canAccess($owner, $session))->toBeTrue()
        ->and($policy->canParticipate($owner, $session))->toBeTrue()
        ->and($policy->canAccess($classmate, $session))->toBeFalse()
        ->and($policy->denial($classmate, $session))->toBe('forbidden');
});

it('refuses a banned learner everywhere', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $banned = Learner::factory()->memberOf($group)->create(['blocked_at' => now()]);
    $training = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
    $personal = TrainingSession::factory()->personal($banned)->create();
    $exam = ExamSession::factory()->create(['telegram_group_id' => $group->id]);

    expect(app(TrainingSessionPolicy::class)->canAccess($banned, $training))->toBeFalse()
        ->and(app(TrainingSessionPolicy::class)->canAccess($banned, $personal))->toBeFalse()
        ->and(app(ExamSessionPolicy::class)->canAccess($banned, $exam))->toBeFalse();
});

// --- ExamSessionPolicy --------------------------------------------------------

it('applies the membership rule to exams: access, participation and results', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $member = Learner::factory()->memberOf($group)->create();
    $left = Learner::factory()->memberOf($group, 'left')->create();
    $policy = app(ExamSessionPolicy::class);

    // (At most one open exam per group: walk ONE session through its life.)
    $exam = ExamSession::factory()->create(['telegram_group_id' => $group->id, 'status' => 'open', 'ends_at' => now()->addMinutes(5)]);

    expect($policy->canAccess($member, $exam))->toBeTrue()
        ->and($policy->canParticipate($member, $exam))->toBeTrue()
        ->and($policy->canViewResult($member, $exam))->toBeFalse()
        ->and($policy->canAccess($left, $exam))->toBeFalse();

    $exam->update(['ends_at' => now()->subMinute()]);
    expect($policy->canParticipate($member, $exam->fresh()))->toBeFalse();

    $exam->update(['status' => 'closed', 'closed_at' => now()]);
    $exam = $exam->fresh();
    expect($policy->canParticipate($member, $exam))->toBeFalse()
        ->and($policy->canViewResult($member, $exam))->toBeTrue()
        ->and($policy->canViewResult($left, $exam))->toBeFalse()
        ->and($policy->denial($left, $exam))->toBe('not_a_member');
});

it('reports an inactive group of an exam as group_inactive to its members', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'pending']);
    $member = Learner::factory()->memberOf($group)->create();
    $exam = ExamSession::factory()->create(['telegram_group_id' => $group->id]);

    expect(app(ExamSessionPolicy::class)->denial($member, $exam))->toBe('group_inactive');
});

// --- LearnerPolicy (Filament) -------------------------------------------------

it('lets an admin view and update any learner and a teacher only view their active members', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $group = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($group->id);

    $own = Learner::factory()->memberOf($group)->create();
    $left = Learner::factory()->memberOf($group, 'left')->create();
    $foreign = Learner::factory()->memberOf($otherGroup)->create();
    $policy = app(LearnerPolicy::class);

    expect($policy->viewAny($teacher))->toBeTrue()
        ->and($policy->view($teacher, $own))->toBeTrue()
        ->and($policy->view($teacher, $left))->toBeFalse()
        ->and($policy->view($teacher, $foreign))->toBeFalse()
        ->and($policy->update($teacher, $own))->toBeFalse()
        ->and($policy->view($admin, $foreign))->toBeTrue()
        ->and($policy->update($admin, $foreign))->toBeTrue()
        ->and($policy->create($admin))->toBeFalse()
        ->and($policy->delete($admin, $own))->toBeFalse()
        ->and($policy->viewAny(User::factory()->create(['is_active' => true])))->toBeFalse();
});
