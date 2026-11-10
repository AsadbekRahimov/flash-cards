<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgOnboardingChecklist;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberInvitation;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * "First steps" of the /org dashboard (audit 2026-11-10 §4 P1): five facts in a fixed number of queries (docs/20 §1.3).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->org = Organization::factory()->create();
    $this->owner = User::factory()->create(['telegram_user_id' => null]);
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id, 'role' => OrganizationRole::Owner->value]);
    $this->checklist = app(OrgOnboardingChecklist::class);
});

it('starts with nothing done and ticks the steps one by one; a hand-typed Telegram id does not count as connected', function (): void {
    $list = $this->checklist->build($this->owner, $this->org);
    expect($list->steps())->toBe(['telegram' => false, 'group' => false, 'course' => false, 'learners' => false, 'training' => false])
        ->and($list->isComplete())->toBeFalse()
        ->and($list->doneCount())->toBe(0);

    $this->owner->forceFill(['telegram_user_id' => 4242])->save();
    expect($this->checklist->build($this->owner->fresh() ?? $this->owner, $this->org)->telegramConnected)->toBeFalse();
    $this->owner->forceFill(['telegram_verified_at' => now()])->save();
    $owner = $this->owner->fresh() ?? $this->owner;
    expect($this->checklist->build($owner, $this->org)->telegramConnected)->toBeTrue();

    $group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => null]);
    $list = $this->checklist->build($owner, $this->org);
    expect($list->groupLinked)->toBeTrue()->and($list->courseChosen)->toBeFalse();

    $group->update(['course_id' => Course::factory()->published()->create()->id]);
    expect($this->checklist->build($owner, $this->org)->courseChosen)->toBeTrue();

    // An invitation not yet accepted counts as "learners invited" (found learner or not); a left member does not.
    $left = Learner::factory()->create();
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => $left->id, 'status' => 'left', 'seat_state' => 'none']);
    expect($this->checklist->build($owner, $this->org)->learnersInvited)->toBeFalse();
    OrganizationMemberInvitation::factory()->create(['organization_id' => $this->org->id, 'invited_handle' => '@nobody_yet']);
    expect($this->checklist->build($owner, $this->org)->learnersInvited)->toBeTrue();

    // A personal session of a learner is not a group training; one in a group of another center neither.
    TrainingSession::factory()->create(['telegram_group_id' => null, 'mode' => 'daily_review', 'started_by_user_id' => null, 'learner_id' => Learner::factory()->create()->id]);
    TrainingSession::factory()->create(['telegram_group_id' => TelegramGroup::factory()->create()->id, 'started_by_user_id' => $owner->id]);
    expect($this->checklist->build($owner, $this->org)->trainingStarted)->toBeFalse();
    TrainingSession::factory()->create(['telegram_group_id' => $group->id, 'started_by_user_id' => $owner->id]);

    $list = $this->checklist->build($owner, $this->org);
    expect($list->trainingStarted)->toBeTrue()->and($list->isComplete())->toBeTrue()->and($list->doneCount())->toBe(5);
});

it('costs three queries whatever the size of the center', function (): void {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->checklist->build($this->owner, $this->org);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $empty = $count();

    $groups = TelegramGroup::factory()->count(12)->create(['organization_id' => $this->org->id]);
    foreach (Learner::factory()->count(40)->create() as $learner) {
        OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => $learner->id]);
    }
    foreach ($groups->take(5) as $group) {
        TrainingSession::factory()->count(3)->create(['telegram_group_id' => $group->id, 'started_by_user_id' => $this->owner->id]);
    }

    expect($empty)->toBeLessThanOrEqual(3)
        ->and($count())->toBe($empty);
});
