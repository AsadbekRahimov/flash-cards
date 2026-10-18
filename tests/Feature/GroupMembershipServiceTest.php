<?php

declare(strict_types=1);

use App\Domain\Learners\Services\GroupMembershipService;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('joins a learner to a group and is idempotent', function (): void {
    $service = app(GroupMembershipService::class);
    $learner = Learner::factory()->create();
    $group = TelegramGroup::factory()->create(['status' => 'active']);

    $first = $service->join($learner, $group, 'group');
    $second = $service->join($learner, $group, 'group');

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and($first->status)->toBe('active')
        ->and($first->joined_at)->not->toBeNull()
        ->and(GroupMember::count())->toBe(1);
});

it('re-activates a left or removed membership but never a banned one', function (string $from, string $expected): void {
    $service = app(GroupMembershipService::class);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group, $from)->create();

    $member = $service->join($learner, $group, 'chat_member');

    expect($member->fresh()->status)->toBe($expected)
        ->and($member->fresh()->left_at === null)->toBe($expected === 'active')
        ->and(GroupMember::count())->toBe(1);
})->with([
    'left' => ['left', 'active'],
    'removed' => ['removed', 'active'],
    'banned' => ['banned', 'banned'],
]);

it('leaves a group: only an active membership changes, with a timestamp', function (): void {
    $service = app(GroupMembershipService::class);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $other = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->memberOf($other)->create();

    expect($service->leave($learner, $group))->toBeTrue();

    $row = GroupMember::where('learner_id', $learner->id)->where('telegram_group_id', $group->id)->sole();
    expect($row->status)->toBe('left')
        ->and($row->left_at)->not->toBeNull()
        ->and(GroupMember::where('learner_id', $learner->id)->where('telegram_group_id', $other->id)->value('status'))->toBe('active');

    // Already left: nothing to end, and the original left_at is not overwritten.
    $leftAt = $row->left_at;
    expect($service->leave($learner, $group))->toBeFalse()
        ->and($row->fresh()->left_at?->equalTo($leftAt))->toBeTrue();
});

it('records the status a removal ended with', function (): void {
    $service = app(GroupMembershipService::class);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();

    $service->leave($learner, $group, GroupMembershipService::REMOVED);

    expect(GroupMember::where('learner_id', $learner->id)->value('status'))->toBe('removed');
});

it('does nothing when leaving a group the learner never joined', function (): void {
    $service = app(GroupMembershipService::class);

    expect($service->leave(Learner::factory()->create(), TelegramGroup::factory()->create()))->toBeFalse()
        ->and(GroupMember::count())->toBe(0);
});

it('defines an active member as an active membership in an ACTIVE group', function (): void {
    $service = app(GroupMembershipService::class);
    $active = TelegramGroup::factory()->create(['status' => 'active']);
    $pending = TelegramGroup::factory()->create(['status' => 'pending']);
    $disabled = TelegramGroup::factory()->create(['status' => 'disabled']);

    $member = Learner::factory()->memberOf($active)->memberOf($pending)->memberOf($disabled)->create();
    $left = Learner::factory()->memberOf($active, 'left')->create();
    $stranger = Learner::factory()->create();

    expect($service->isActiveMember($member, $active))->toBeTrue()
        ->and($service->isActiveMember($member, $pending))->toBeFalse()
        ->and($service->isActiveMember($member, $disabled))->toBeFalse()
        ->and($service->isActiveMember($left, $active))->toBeFalse()
        ->and($service->isActiveMember($stranger, $active))->toBeFalse();
});

it('lists the ids and models of the active groups of a learner', function (): void {
    $service = app(GroupMembershipService::class);
    $a = TelegramGroup::factory()->create(['status' => 'active']);
    $b = TelegramGroup::factory()->create(['status' => 'active']);
    $inactive = TelegramGroup::factory()->create(['status' => 'disabled']);
    $left = TelegramGroup::factory()->create(['status' => 'active']);

    $learner = Learner::factory()->memberOf($a)->memberOf($b)->memberOf($inactive)->create();
    GroupMember::factory()->left()->create(['telegram_group_id' => $left->id, 'learner_id' => $learner->id]);

    expect($service->activeGroupIds($learner))->toBe([$a->id, $b->id])
        ->and($service->activeGroups($learner)->pluck('id')->all())->toBe([$a->id, $b->id])
        ->and($service->activeGroupIds(Learner::factory()->create()))->toBe([]);
});

it('explains why access is denied without disclosing foreign groups', function (): void {
    $service = app(GroupMembershipService::class);
    $active = TelegramGroup::factory()->create(['status' => 'active']);
    $disabled = TelegramGroup::factory()->create(['status' => 'disabled']);

    $member = Learner::factory()->memberOf($active)->memberOf($disabled)->create();
    $left = Learner::factory()->memberOf($active, 'left')->create();
    $banned = Learner::factory()->memberOf($active, 'banned')->create();
    $stranger = Learner::factory()->create();

    expect($service->denialReason($member, $active))->toBeNull()
        ->and($service->denialReason($member, $disabled))->toBe('group_inactive')
        ->and($service->denialReason($left, $active))->toBe('not_a_member')
        ->and($service->denialReason($banned, $active))->toBe('not_a_member')
        // A non-member of an inactive group gets the same answer as for an active one.
        ->and($service->denialReason($stranger, $disabled))->toBe('not_a_member')
        ->and($service->denialReason($stranger, $active))->toBe('not_a_member')
        ->and($service->denialReason($member, null))->toBe('not_a_member');
});

it('ignores a late join that is older than the leave already applied', function (): void {
    $service = app(GroupMembershipService::class);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->create();
    $t0 = CarbonImmutable::now()->subHour()->startOfSecond();

    $service->join($learner, $group, 'chat_member', $t0);
    expect($service->leave($learner, $group, GroupMembershipService::LEFT, $t0->addSeconds(10)))->toBeTrue();

    $member = $service->join($learner, $group, 'chat_member', $t0->addSeconds(5));

    expect($member->wasChanged('status'))->toBeFalse()
        ->and(GroupMember::sole()->status)->toBe('left')
        ->and(GroupMember::sole()->left_at?->equalTo($t0->addSeconds(10)))->toBeTrue();

    // A genuinely newer join still re-activates.
    $service->join($learner, $group, 'chat_member', $t0->addSeconds(20));
    expect(GroupMember::sole()->status)->toBe('active');
});

it('ignores a late leave that is older than the join already applied', function (): void {
    $service = app(GroupMembershipService::class);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->create();
    $t0 = CarbonImmutable::now()->subHour()->startOfSecond();

    $service->join($learner, $group, 'chat_member', $t0->addSeconds(10));

    expect($service->leave($learner, $group, GroupMembershipService::LEFT, $t0->addSeconds(5)))->toBeFalse()
        ->and(GroupMember::sole()->status)->toBe('active')
        ->and(GroupMember::sole()->joined_at?->equalTo($t0->addSeconds(10)))->toBeTrue();

    expect($service->leave($learner, $group, GroupMembershipService::REMOVED, $t0->addSeconds(30)))->toBeTrue()
        ->and(GroupMember::sole()->status)->toBe('removed');
});

it('re-activates a membership that left before the join event', function (): void {
    $service = app(GroupMembershipService::class);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->create();
    $t0 = CarbonImmutable::now()->subHour()->startOfSecond();

    $service->join($learner, $group, 'group', $t0);
    $service->leave($learner, $group, GroupMembershipService::LEFT, $t0->addSeconds(10));
    $service->join($learner, $group, 'group', $t0->addSeconds(10));

    expect(GroupMember::sole()->status)->toBe('active');
});
