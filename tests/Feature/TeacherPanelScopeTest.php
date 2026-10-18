<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\ExamSessionResource;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\StageResource;
use App\Filament\Resources\TelegramGroupResource;
use App\Filament\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\ExamSession;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function panelTeacher(TelegramGroup ...$groups): User
{
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    foreach ($groups as $i => $group) {
        $teacher->telegramGroups()->attach($group->id, ['is_primary' => $i === 0]);
    }

    return $teacher;
}

function panelAdmin(): User
{
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('limits teacher resources and dashboard queries to assigned groups', function (): void {
    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher = panelTeacher($ownGroup);

    $ownLearner = Learner::factory()->memberOf($ownGroup)->create();
    $otherLearner = Learner::factory()->memberOf($otherGroup)->create();
    $ownSession = TrainingSession::factory()->create(['telegram_group_id' => $ownGroup->id]);
    $otherSession = TrainingSession::factory()->create(['telegram_group_id' => $otherGroup->id]);
    $ownReview = TrainingReview::factory()->create(['training_session_id' => $ownSession->id, 'learner_id' => $ownLearner->id]);
    TrainingReview::factory()->create(['training_session_id' => $otherSession->id, 'learner_id' => $otherLearner->id]);
    $ownExam = ExamSession::factory()->create(['telegram_group_id' => $ownGroup->id]);
    ExamSession::factory()->create(['telegram_group_id' => $otherGroup->id]);

    $this->actingAs($teacher);

    expect(TelegramGroupResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownGroup->id]);
    expect(LearnerResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownLearner->id]);
    expect(ExamSessionResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownExam->id]);

    $scope = app(PanelDataScope::class);
    expect($scope->trainingReviews()->pluck('id')->all())->toBe([$ownReview->id]);
    expect($scope->exams()->pluck('id')->all())->toBe([$ownExam->id]);

    expect(UserResource::canViewAny())->toBeFalse();
    expect(StageResource::canViewAny())->toBeTrue();
    expect(StageResource::canCreate())->toBeFalse();

    $this->get(ExamSessionResource::getUrl('view', ['record' => $ownExam]))->assertOk();
});

it('learners(): a teacher sees only ACTIVE members of their groups', function (): void {
    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher = panelTeacher($ownGroup);

    $active = Learner::factory()->memberOf($ownGroup)->create();
    $left = Learner::factory()->memberOf($ownGroup, 'left')->create();
    $removed = Learner::factory()->memberOf($ownGroup, 'removed')->create();
    $foreign = Learner::factory()->memberOf($otherGroup)->create();
    $noGroup = Learner::factory()->create();

    $this->actingAs($teacher);

    $visible = app(PanelDataScope::class)->learners()->pluck('id')->all();
    expect($visible)->toBe([$active->id])
        ->and($visible)->not->toContain($left->id, $removed->id, $foreign->id, $noGroup->id);
    expect(app(PanelDataScope::class)->learnerIds()->pluck('id')->all())->toBe([$active->id]);
});

it('learners(): a learner in two groups is visible once to the teacher of either, and to nobody else', function (): void {
    $groupA = TelegramGroup::factory()->create();
    $groupB = TelegramGroup::factory()->create();
    $groupC = TelegramGroup::factory()->create();
    $learner = Learner::factory()->memberOf($groupA)->memberOf($groupB)->create();
    $teacherA = panelTeacher($groupA);
    $teacherBoth = panelTeacher($groupA, $groupB);
    $teacherC = panelTeacher($groupC);

    $this->actingAs($teacherA);
    expect(app(PanelDataScope::class)->learners()->pluck('id')->all())->toBe([$learner->id]);

    $this->actingAs($teacherBoth);
    expect(app(PanelDataScope::class)->learners()->pluck('id')->all())->toBe([$learner->id]);

    $this->actingAs($teacherC);
    expect(app(PanelDataScope::class)->learners()->count())->toBe(0);
});

it('learners(): an admin sees everybody, an anonymous caller nobody', function (): void {
    $group = TelegramGroup::factory()->create();
    Learner::factory()->memberOf($group)->create();
    Learner::factory()->memberOf($group, 'left')->create();
    Learner::factory()->create();

    expect(app(PanelDataScope::class)->learners()->count())->toBe(0);

    $this->actingAs(panelAdmin());
    expect(app(PanelDataScope::class)->learners()->count())->toBe(3);
});

it('hides a learner\'s personal sessions from the teacher but not from the admin', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = panelTeacher($group);
    $learner = Learner::factory()->memberOf($group)->create();

    $groupSession = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
    $groupReview = TrainingReview::factory()->create(['training_session_id' => $groupSession->id, 'learner_id' => $learner->id]);
    $personalReviews = collect(['lesson', 'daily_review'])->map(fn (string $mode) => TrainingReview::factory()->create([
        'training_session_id' => TrainingSession::factory()->personal($learner, $mode)->create()->id,
        'learner_id' => $learner->id,
    ]));

    $this->actingAs($teacher);
    $scope = app(PanelDataScope::class);
    expect($scope->trainingReviews()->pluck('id')->all())->toBe([$groupReview->id])
        ->and($scope->trainingSessions()->pluck('id')->all())->toBe([$groupSession->id]);

    $this->actingAs(panelAdmin());
    expect(app(PanelDataScope::class)->trainingReviews()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$groupReview->id, ...$personalReviews->pluck('id')->all()])->sort()->values()->all());
});

it('limits the teacher\'s view of a shared learner to reviews of the teacher\'s own groups', function (): void {
    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher = panelTeacher($ownGroup);
    $learner = Learner::factory()->memberOf($ownGroup)->memberOf($otherGroup)->create();

    foreach ([[$ownGroup, 2], [$otherGroup, 5]] as [$group, $count]) {
        $session = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
        TrainingReview::factory()->count($count)->create(['training_session_id' => $session->id, 'learner_id' => $learner->id]);
    }
    TrainingReview::factory()->count(7)->create([
        'training_session_id' => TrainingSession::factory()->personal($learner)->create()->id,
        'learner_id' => $learner->id,
    ]);

    $this->actingAs($teacher);

    $row = LearnerResource::getEloquentQuery()->sole();
    expect($row->training_reviews_count)->toBe(2)
        // Global progress is admin-only data: the teacher view still loads it but never renders it (see below).
        ->and($row->groups->pluck('id')->all())->toBe([$ownGroup->id]);
});

it('does not let a teacher open a learner from another group', function (): void {
    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher = panelTeacher($ownGroup);
    $otherLearner = Learner::factory()->memberOf($otherGroup)->create();

    $this->actingAs($teacher)
        ->get(LearnerResource::getUrl('view', ['record' => $otherLearner]))
        ->assertNotFound();
});

it('does not let a teacher open a learner who left their group', function (): void {
    $ownGroup = TelegramGroup::factory()->create();
    $teacher = panelTeacher($ownGroup);
    $leaver = Learner::factory()->memberOf($ownGroup, 'left')->create();

    $this->actingAs($teacher)
        ->get(LearnerResource::getUrl('view', ['record' => $leaver]))
        ->assertNotFound();
});

it('shows a teacher only name and username of a learner, never the Telegram ID or settings', function (): void {
    $ownGroup = TelegramGroup::factory()->create(['title' => 'Own English']);
    $otherGroup = TelegramGroup::factory()->create(['title' => 'Secret Other Class']);
    $teacher = panelTeacher($ownGroup);
    $learner = Learner::factory()->memberOf($ownGroup)->memberOf($otherGroup)->create([
        'first_name' => 'Zinaida',
        'username' => 'zina_visible',
        'telegram_user_id' => 424242424,
    ]);

    $this->actingAs($teacher)
        ->get(LearnerResource::getUrl('view', ['record' => $learner]))
        ->assertOk()
        ->assertSee('Zinaida')
        ->assertSee('zina_visible')
        ->assertSee('Own English')
        ->assertDontSee('424242424')
        ->assertDontSee('Secret Other Class')
        ->assertDontSee('Cards started');
});

it('shows an admin the full learner card', function (): void {
    $group = TelegramGroup::factory()->create(['title' => 'Class A']);
    $learner = Learner::factory()->memberOf($group)->create(['first_name' => 'Zinaida', 'telegram_user_id' => 424242424]);

    $this->actingAs(panelAdmin())
        ->withSession(['2fa.passed_at' => now()->timestamp])
        ->get(LearnerResource::getUrl('view', ['record' => $learner]))
        ->assertOk()
        ->assertSee('424242424')
        ->assertSee('Cards started');
});

it('lets only an admin block and unblock a learner, with an audit trail', function (): void {
    $group = TelegramGroup::factory()->create();
    $learner = Learner::factory()->memberOf($group)->create();

    $admin = panelAdmin();
    $this->actingAs($admin)->withSession(['2fa.passed_at' => now()->timestamp]);

    Livewire::test(LearnerResource\Pages\ListLearners::class)
        ->callTableAction('toggleBlocked', $learner);

    expect($learner->fresh()->blocked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.blocked')->where('entity_id', $learner->id)->where('user_id', $admin->id)->exists())->toBeTrue();

    Livewire::test(LearnerResource\Pages\ListLearners::class)
        ->callTableAction('toggleBlocked', $learner->fresh());

    expect($learner->fresh()->blocked_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.unblocked')->exists())->toBeTrue();
});

it('hides the block action from teachers', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = panelTeacher($group);
    $learner = Learner::factory()->memberOf($group)->create();

    $this->actingAs($teacher);

    Livewire::test(LearnerResource\Pages\ListLearners::class)
        ->assertTableActionHidden('toggleBlocked', $learner);
});

it('filters the learner table by group through active membership', function (): void {
    $admin = panelAdmin();
    $groupA = TelegramGroup::factory()->create();
    $groupB = TelegramGroup::factory()->create();
    $inA = Learner::factory()->memberOf($groupA)->create();
    $inB = Learner::factory()->memberOf($groupB)->create();
    $leftA = Learner::factory()->memberOf($groupA, 'left')->create();

    $this->actingAs($admin)->withSession(['2fa.passed_at' => now()->timestamp]);

    Livewire::test(LearnerResource\Pages\ListLearners::class)
        ->filterTable('telegram_group_id', $groupA->id)
        ->assertCanSeeTableRecords([$inA])
        ->assertCanNotSeeTableRecords([$inB, $leftA]);
});

it('counts active members per group in the group table', function (): void {
    $group = TelegramGroup::factory()->create();
    Learner::factory()->count(2)->memberOf($group)->create();
    Learner::factory()->memberOf($group, 'left')->create();

    $this->actingAs(panelAdmin())->withSession(['2fa.passed_at' => now()->timestamp]);

    expect(TelegramGroupResource::getEloquentQuery()->withCount([
        'members' => fn ($q) => $q->where('status', 'active'),
    ])->find($group->id)->members_count)->toBe(2);
});

it('does not let a teacher open an exam log from another group', function (): void {
    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher = panelTeacher($ownGroup);
    $otherExam = ExamSession::factory()->create(['telegram_group_id' => $otherGroup->id]);

    $this->actingAs($teacher)
        ->get(ExamSessionResource::getUrl('view', ['record' => $otherExam]))
        ->assertNotFound();
});

it('keeps GroupMember rows out of other teachers\' reach when a learner changes group', function (): void {
    $groupA = TelegramGroup::factory()->create();
    $groupB = TelegramGroup::factory()->create();
    $teacherA = panelTeacher($groupA);
    $learner = Learner::factory()->memberOf($groupA)->create();

    $this->actingAs($teacherA);
    expect(app(PanelDataScope::class)->learners()->count())->toBe(1);

    // The learner leaves A and joins B: teacher A loses sight of them at once.
    GroupMember::query()->where('learner_id', $learner->id)->update(['status' => 'left']);
    GroupMember::factory()->create(['telegram_group_id' => $groupB->id, 'learner_id' => $learner->id]);

    expect(app(PanelDataScope::class)->learners()->count())->toBe(0);
});
