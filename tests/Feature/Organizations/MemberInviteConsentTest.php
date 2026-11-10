<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Events\OrganizationMemberInvited;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\LearnerOrganizationsService;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeTelegramClient;

/*
 * "Add a learner" from the cabinet is an OFFER (audit 2026-11-10 M2): a row `status = invited` with no seat and no Pro, a DM
 * "Join / Decline" to the learner, and a membership only on "Join". The staff gets one neutral answer whatever happened, so the
 * cabinet cannot be used to find out who uses LexiFlow.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);

    $this->org = Organization::factory()->create(['name' => 'Bright Center']);
    Subscription::factory()->forOrganization($this->org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
    $this->owner = User::factory()->create();
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id, 'role' => OrganizationRole::Owner->value]);
    $this->learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 9001, 'username' => 'Ali_Learner', 'interface_locale' => 'ru']);
    $this->service = app(OrganizationMembershipService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function micInvite(string $telegram = '@ali_learner'): ?OrganizationMember
{
    return test()->service->inviteByStaff(test()->org, $telegram, test()->owner, '10.0.0.1');
}

function micTap(int $tgId, string $data, int $messageId = 100): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$messageId.'-'.md5($data),
        // A tap is the person's own action: the registrar refreshes the profile from `from`, so the username must travel with it.
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'username' => $tgId === 9001 ? 'Ali_Learner' : null, 'language_code' => 'ru'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

/** @return list<string> */
function micTexts(): array
{
    return array_column(test()->tg->sent, 'text');
}

// --- the offer ----------------------------------------------------------------------------------------

it('creates an invited row without a seat, audits it, and DMs the learner "Join / Decline" — by @username (any case) or by Telegram id', function (string $input): void {
    $member = micInvite($input);

    expect($member)->not->toBeNull()
        ->and($member?->status)->toBe('invited')
        ->and($member?->seat_state)->toBe('none')
        ->and($member?->joined_via)->toBe('admin')
        ->and($member?->invited_by_user_id)->toBe($this->owner->id)
        ->and($member?->invited_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and($member?->isActive())->toBeFalse()
        ->and($member?->isInvited())->toBeTrue();

    $dm = end($this->tg->sent);
    expect($dm['chat_id'])->toBe(9001)
        ->and($dm['text'])->toBe('Учебный центр «Bright Center» приглашает вас вступить. Вступив, вы получите Pro от центра, если у него есть свободные места, а центр будет видеть вашу активность в LexiFlow. Вступить?')
        ->and($this->tg->lastCallbackData())->toBe(['org1:accept:'.$member?->id, 'org1:decline:'.$member?->id]);

    expect(AuditLog::query()->where('action', OrganizationMembershipService::AUDIT_MEMBER_INVITED)->sole()->user_id)->toBe($this->owner->id)
        ->and(ProductEvent::query()->where('name', 'org.member_joined')->count())->toBe(0)
        ->and($this->service->activeMembership($this->learner, $this->org))->toBeNull()
        ->and($this->service->seatsSummary($this->org)->seated)->toBe(0);
})->with(['@ali_learner', 'ALI_LEARNER', '9001']);

it('returns null and sends nothing for a learner who is unknown, blocked, unreachable, mid-onboarding, already active or already offered — the caller shows the same text', function (string $case): void {
    $telegram = '@ali_learner';
    match ($case) {
        'unknown username' => $telegram = '@nobody_here',
        'unknown id' => $telegram = '123456789012',
        'garbage' => $telegram = 'x!',
        'blocked' => $this->learner->forceFill(['blocked_at' => now()])->save(),
        'dm closed' => $this->learner->forceFill(['dm_blocked_at' => now()])->save(),
        'mid-onboarding' => $this->learner->forceFill(['onboarding_state' => 'goal'])->save(),
        'active member' => $this->service->joinByAdmin($this->learner, $this->org, $this->owner),
        'already offered' => micInvite(),
    };
    $sent = count($this->tg->sent);
    $rows = OrganizationMember::query()->count();

    expect(micInvite($telegram))->toBeNull()
        ->and(count($this->tg->sent))->toBe($sent)
        ->and(OrganizationMember::query()->count())->toBe($rows);
})->with(['unknown username', 'unknown id', 'garbage', 'blocked', 'dm closed', 'mid-onboarding', 'active member', 'already offered']);

it('limits offers per organization and per staff member, counting unknown learners too (no probing by @username)', function (): void {
    config(['organizations.member_invite.per_organization_per_hour' => 3, 'organizations.member_invite.per_staff_per_hour' => 10]);

    micInvite('@nobody1');
    micInvite('@nobody2');
    micInvite('@nobody3');

    expect(fn () => micInvite('@ali_learner'))->toThrow(OrganizationMembershipException::class, 'Too many');
    expect(OrganizationMember::query()->count())->toBe(0);

    // Another staff member of the same center shares the organization's counter; the staff counter is their own.
    $second = User::factory()->create();
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $second->id, 'role' => OrganizationRole::Owner->value]);
    expect(fn () => $this->service->inviteByStaff($this->org, '@ali_learner', $second))->toThrow(OrganizationMembershipException::class);

    RateLimiter::clear('org-member-invite:org:'.$this->org->id);
    config(['organizations.member_invite.per_staff_per_hour' => 3]);
    expect(fn () => micInvite('@ali_learner'))->toThrow(OrganizationMembershipException::class);
    expect(OrganizationMembershipException::rateLimited(5)->httpStatus())->toBe(429)
        ->and(OrganizationMembershipException::inviteNotPending(1)->httpStatus())->toBe(410);
});

it('refuses a suspended center before looking anybody up', function (): void {
    $this->org->update(['status' => 'suspended']);

    expect(fn () => micInvite())->toThrow(OrganizationMembershipException::class, 'suspended');
});

// --- the learner answers ------------------------------------------------------------------------------

it('"Join" makes the offer an active membership via admin with a seat, and the usual "you are in" DM follows', function (): void {
    $member = micInvite();

    micTap(9001, 'org1:accept:'.$member?->id, 200);

    $member = $member?->fresh();
    expect($member?->status)->toBe('active')
        ->and($member?->seat_state)->toBe('seated')
        ->and($member?->joined_via)->toBe('admin')
        ->and($member?->joined_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and($member?->invited_by_user_id)->toBe($this->owner->id)
        ->and(end($this->tg->edits)['message_id'])->toBe(200)
        ->and(end($this->tg->sent)['text'])->toBe('Вы в центре «Bright Center». Pro активен — занимайтесь без ограничений.')
        ->and(ProductEvent::query()->where('name', 'org.member_joined')->sole()->props)->toMatchArray(['via' => 'admin']);

    // A second tap: the offer is no longer pending — explained, nothing changes.
    $sent = count($this->tg->sent);
    micTap(9001, 'org1:accept:'.$member?->id, 200);
    expect(array_slice(micTexts(), $sent))->toBe(['Это приглашение больше не действует.'])
        ->and(OrganizationMember::query()->count())->toBe(1);
});

it('"Decline" closes the offer: declined, no seat, event recorded, the learner told; a new offer waits for the cooldown', function (): void {
    $member = micInvite();

    micTap(9001, 'org1:decline:'.$member?->id, 300);

    $member = $member?->fresh();
    expect($member?->status)->toBe('declined')
        ->and($member?->seat_state)->toBe('none')
        ->and($member?->left_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and(end($this->tg->sent)['text'])->toBe('Хорошо, вы отклонили приглашение центра «Bright Center». Ничего не изменилось.')
        ->and(ProductEvent::query()->where('name', 'org.member_declined')->count())->toBe(1)
        ->and($this->service->activeMembership($this->learner, $this->org))->toBeNull();

    // Within the cooldown the center cannot offer again (silent no-op); after it — a fresh offer on the same row.
    expect(micInvite())->toBeNull();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    $again = micInvite();
    expect($again?->id)->toBe($member?->id)->and($again?->status)->toBe('invited');
});

it('ignores the buttons of somebody else\'s offer, and a learner cannot accept an offer by guessing ids', function (): void {
    $member = micInvite();
    $stranger = Learner::factory()->onboarded()->create(['telegram_user_id' => 9002]);

    micTap(9002, 'org1:accept:'.$member?->id);

    expect($member?->fresh()?->status)->toBe('invited')
        ->and(end($this->tg->sent)['chat_id'])->toBe(9002)
        ->and(end($this->tg->sent)['text'])->toBe('Это приглашение больше не действует.')
        ->and(OrganizationMember::query()->where('learner_id', $stranger->id)->exists())->toBeFalse();

    micTap(9002, 'org1:accept:999999');
    expect(OrganizationMember::query()->count())->toBe(1);
});

it('does not accept an offer that expired, and lets the staff send a fresh one', function (): void {
    $member = micInvite();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 12:00:01', 'UTC'));

    micTap(9001, 'org1:accept:'.$member?->id);
    expect($member?->fresh()?->status)->toBe('invited')
        ->and(end($this->tg->sent)['text'])->toBe('Это приглашение больше не действует.');

    $fresh = micInvite();
    expect($fresh?->id)->toBe($member?->id)
        ->and($fresh?->invited_at?->toIso8601String())->toBe('2026-11-18T12:00:01+00:00');
    micTap(9001, 'org1:accept:'.$fresh?->id);
    expect($fresh?->fresh()?->status)->toBe('active');
});

it('does not accept for a suspended center', function (): void {
    $member = micInvite();
    $this->org->update(['status' => 'suspended']);

    micTap(9001, 'org1:accept:'.$member?->id);

    expect($member?->fresh()?->status)->toBe('invited')
        ->and(end($this->tg->sent)['text'])->toBe('Центр сейчас не принимает учеников. Обратитесь к администратору центра.');
});

// --- the staff takes the offer back ---------------------------------------------------------------

it('revoke deletes the pending row, audits it, and the old buttons then say the offer is gone; only an invited row of this center can be revoked', function (): void {
    $member = micInvite();

    $this->service->revokeInvite($this->org, $member ?? new OrganizationMember, $this->owner, '10.0.0.1');

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', OrganizationMembershipService::AUDIT_INVITE_REVOKED)->sole()->meta)->toMatchArray(['learner_id' => $this->learner->id]);

    micTap(9001, 'org1:accept:'.$member?->id);
    expect(end($this->tg->sent)['text'])->toBe('Это приглашение больше не действует.')
        ->and(OrganizationMember::query()->count())->toBe(0);

    $active = $this->service->joinByAdmin($this->learner, $this->org, $this->owner);
    expect(fn () => $this->service->revokeInvite($this->org, $active, $this->owner))->toThrow(OrganizationMembershipException::class, 'not waiting');

    $foreign = Organization::factory()->create();
    $pending = $this->service->inviteByStaff($foreign, '9001', $this->owner);
    $otherLearner = Learner::factory()->onboarded()->create(['telegram_user_id' => 9003]);
    $pendingOther = $this->service->inviteByStaff($foreign, '9003', $this->owner);
    expect(fn () => $this->service->revokeInvite($this->org, $pendingOther ?? new OrganizationMember, $this->owner))->toThrow(OrganizationMembershipException::class, 'not waiting');
    expect($pending?->fresh())->not->toBeNull()->and($otherLearner->fresh())->not->toBeNull();
});

// --- an invited row is not a member anywhere -----------------------------------------------------------

it('keeps an invited learner out of every membership read: seats, active membership, TWA "my centers", the pure "is member" checks', function (): void {
    micInvite();

    expect($this->service->activeMembership($this->learner, $this->org))->toBeNull()
        ->and($this->service->seatsSummary($this->org)->seated)->toBe(0)
        ->and($this->service->seatsSummary($this->org)->waitlisted)->toBe(0)
        ->and(app(LearnerOrganizationsService::class)->memberships($this->learner))->toHaveCount(0);
});

it('lets a join through a group of the center supersede a pending offer (the person entered the chat themselves)', function (): void {
    $member = micInvite();
    $group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);

    $this->service->joinFromGroup($this->learner, $group);

    expect($member?->fresh()?->status)->toBe('active')
        ->and($member?->fresh()?->joined_via)->toBe('group')
        ->and(OrganizationMember::query()->count())->toBe(1);
});

it('raises OrganizationMemberInvited after commit with the ids the listener needs', function (): void {
    Event::fake([OrganizationMemberInvited::class]);

    $member = micInvite();

    Event::assertDispatched(OrganizationMemberInvited::class, fn (OrganizationMemberInvited $e): bool => $e->memberId === $member?->id
        && $e->learnerId === $this->learner->id
        && $e->organizationId === $this->org->id);
});

it('sends the offer in the learner\'s language and skips a learner who became unreachable before the queued DM ran', function (): void {
    $this->learner->forceFill(['interface_locale' => 'uz'])->save();
    micInvite();
    expect(end($this->tg->sent)['text'])->toStartWith("«Bright Center» o'quv markazi Sizni qo'shilishga taklif qilmoqda.");

    $other = Learner::factory()->onboarded()->create(['telegram_user_id' => 9004]);
    Event::fake([OrganizationMemberInvited::class]);
    $member = $this->service->inviteByStaff($this->org, '9004', $this->owner);
    $other->forceFill(['dm_blocked_at' => now()])->save();
    $sent = count($this->tg->sent);

    app(OrganizationMessenger::class)->offerMembership((int) $member?->id);

    expect(count($this->tg->sent))->toBe($sent);
});
