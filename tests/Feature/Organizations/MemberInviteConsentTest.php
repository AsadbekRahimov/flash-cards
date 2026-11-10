<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\MemberInvitationState;
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
use App\Models\OrganizationMemberInvitation;
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
 * "Add a learner" from the cabinet is an INVITATION (audit 2026-11-10 M2, review finding B1): a row of
 * `organization_member_invitations` for EVERY attempt, a DM "Join / Decline" to the learner when there is one to message, and
 * a membership only on "Join". Whatever the staff can read of the row is the same for a handle that matched nobody, a learner who
 * never answered, a learner who declined and a learner the bot could not reach — the cabinet cannot be used to find out who uses
 * LexiFlow or what they answered. W7: one DM per learner per center per cooldown window, whatever the staff do in between.
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

function micInvite(string $telegram = '@ali_learner'): OrganizationMemberInvitation
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

/** What the cabinet may read of an invitation: handle, dates, state. Nothing else. */
function micVisible(OrganizationMemberInvitation $invitation): array
{
    return [
        'handle' => $invitation->invited_handle,
        'created' => $invitation->created_at->toIso8601String(),
        'expires' => $invitation->expires_at->toIso8601String(),
        'state' => $invitation->state()->value,
        'json' => array_keys($invitation->fresh()?->toArray() ?? []),
    ];
}

// --- the invitation ---------------------------------------------------------------------------------

it('writes an invitation with the normalised handle, audits it, and DMs the learner "Join / Decline" — by @username (any case) or by Telegram id', function (string $input, string $handle): void {
    $invitation = micInvite($input);

    expect($invitation->invited_handle)->toBe($handle)
        ->and($invitation->organization_id)->toBe($this->org->id)
        ->and($invitation->invited_by_user_id)->toBe($this->owner->id)
        ->and($invitation->learner_id)->toBe($this->learner->id)
        ->and($invitation->expires_at->toIso8601String())->toBe('2026-11-17T12:00:00+00:00')
        ->and($invitation->dm_sent_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and($invitation->state())->toBe(MemberInvitationState::Pending);

    $dm = end($this->tg->sent);
    expect($dm['chat_id'])->toBe(9001)
        ->and($dm['text'])->toBe('Учебный центр «Bright Center» приглашает вас вступить. Вступив, вы получите Pro от центра, если у него есть свободные места, а центр будет видеть вашу активность в LexiFlow. Вступить?')
        ->and($this->tg->lastCallbackData())->toBe(['org1:accept:'.$invitation->id, 'org1:decline:'.$invitation->id]);

    expect(AuditLog::query()->where('action', OrganizationMembershipService::AUDIT_MEMBER_INVITED)->sole()->user_id)->toBe($this->owner->id)
        ->and(ProductEvent::query()->where('name', 'org.member_joined')->count())->toBe(0)
        ->and(OrganizationMember::query()->count())->toBe(0)
        ->and($this->service->activeMembership($this->learner, $this->org))->toBeNull()
        ->and($this->service->seatsSummary($this->org)->seated)->toBe(0);
})->with([
    ['@ali_learner', '@ali_learner'],
    ['ALI_LEARNER', '@ali_learner'],
    ['  @Ali_Learner ', '@ali_learner'],
    ['9001', '9001'],
]);

it('refuses only a MALFORMED value — a format check, before any lookup', function (string $input): void {
    expect(fn () => micInvite($input))->toThrow(OrganizationMembershipException::class, 'Expected a Telegram @username');
    expect(OrganizationMemberInvitation::query()->count())->toBe(0)
        ->and(OrganizationMembershipException::telegramInvalid()->httpStatus())->toBe(422);
})->with(['', 'x!', 'ab', '@', '1234567890123456789', 'имя']);

it('writes the SAME visible row and sends nothing for a learner who is unknown, blocked, unreachable, mid-onboarding or already active', function (string $case): void {
    $telegram = '@ali_learner';
    match ($case) {
        'unknown username' => $telegram = '@nobody_here',
        'unknown id' => $telegram = '123456789012',
        'blocked' => $this->learner->forceFill(['blocked_at' => now()])->save(),
        'dm closed' => $this->learner->forceFill(['dm_blocked_at' => now()])->save(),
        'mid-onboarding' => $this->learner->forceFill(['onboarding_state' => 'goal'])->save(),
        'active member' => $this->service->joinByAdmin($this->learner, $this->org, $this->owner),
    };
    $sent = count($this->tg->sent);

    $invitation = micInvite($telegram);

    expect(count($this->tg->sent))->toBe($sent)
        ->and($invitation->dm_sent_at)->toBeNull()
        ->and(micVisible($invitation))->toBe([
            'handle' => str_starts_with($telegram, '@') ? strtolower($telegram) : $telegram,
            'created' => '2026-11-10T12:00:00+00:00',
            'expires' => '2026-11-17T12:00:00+00:00',
            'state' => 'pending',
            'json' => ['id', 'organization_id', 'invited_handle', 'invited_by_user_id', 'expires_at', 'accepted_at', 'revoked_at', 'created_at', 'updated_at'],
        ]);

    // The row of a found learner, visible part: identical to the row of an unknown handle written the same way.
    $unknown = micInvite('@ghost_user');
    expect(micVisible($unknown))->toBe([...micVisible($invitation), 'handle' => '@ghost_user']);
})->with(['unknown username', 'unknown id', 'blocked', 'dm closed', 'mid-onboarding', 'active member']);

it('returns the open invitation of the same handle instead of a second row or a second DM, whatever its internal state', function (string $case): void {
    $first = micInvite();
    match ($case) {
        'unanswered' => null,
        'declined' => micTap(9001, 'org1:decline:'.$first->id),
        'dm failed' => $this->learner->forceFill(['dm_blocked_at' => now()])->save(),
    };
    $sent = count($this->tg->sent);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13 12:00:00', 'UTC'));
    $again = micInvite('ALI_LEARNER');

    expect($again->id)->toBe($first->id)
        ->and(OrganizationMemberInvitation::query()->count())->toBe(1)
        ->and(count($this->tg->sent))->toBe($sent)
        ->and(micVisible($again))->toBe(micVisible($first));
})->with(['unanswered', 'declined', 'dm failed']);

it('limits invitations per organization and per staff member, counting unknown learners too (no probing by @username)', function (): void {
    config(['organizations.member_invite.per_organization_per_hour' => 3, 'organizations.member_invite.per_staff_per_hour' => 10]);

    micInvite('@nobody1');
    micInvite('@nobody2');
    micInvite('@nobody3');

    expect(fn () => micInvite('@ali_learner'))->toThrow(OrganizationMembershipException::class, 'Too many');
    expect(OrganizationMemberInvitation::query()->count())->toBe(3);

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
    expect(OrganizationMemberInvitation::query()->count())->toBe(0);
});

// --- the learner answers ------------------------------------------------------------------------------

it('"Join" creates an active membership via admin with a seat, marks the invitation accepted, and the usual "you are in" DM follows', function (): void {
    $invitation = micInvite();

    micTap(9001, 'org1:accept:'.$invitation->id, 200);

    $member = OrganizationMember::query()->sole();
    expect($member->learner_id)->toBe($this->learner->id)
        ->and($member->status)->toBe('active')
        ->and($member->seat_state)->toBe('seated')
        ->and($member->joined_via)->toBe('admin')
        ->and($member->joined_at->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and($invitation->fresh()?->state())->toBe(MemberInvitationState::Accepted)
        ->and(end($this->tg->edits)['message_id'])->toBe(200)
        ->and(end($this->tg->sent)['text'])->toBe('Вы в центре «Bright Center». Pro активен — занимайтесь без ограничений.')
        ->and(ProductEvent::query()->where('name', 'org.member_joined')->sole()->props)->toMatchArray(['via' => 'admin']);

    // A second tap: the invitation is no longer answerable — explained, nothing changes.
    $sent = count($this->tg->sent);
    micTap(9001, 'org1:accept:'.$invitation->id, 200);
    expect(array_slice(micTexts(), $sent))->toBe(['Это приглашение больше не действует.'])
        ->and(OrganizationMember::query()->count())->toBe(1);
});

it('"Decline" closes the invitation for the learner only: the staff still see "waiting", then "expired"; the event is recorded', function (): void {
    $invitation = micInvite();

    micTap(9001, 'org1:decline:'.$invitation->id, 300);

    $invitation = $invitation->fresh() ?? $invitation;
    expect($invitation->declined_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and($invitation->state())->toBe(MemberInvitationState::Pending)
        ->and(end($this->tg->sent)['text'])->toBe('Хорошо, вы отклонили приглашение центра «Bright Center». Ничего не изменилось.')
        ->and(ProductEvent::query()->where('name', 'org.member_declined')->count())->toBe(1)
        ->and(OrganizationMember::query()->count())->toBe(0)
        ->and($this->service->activeMembership($this->learner, $this->org))->toBeNull();

    // The buttons are dead now, and the staff learn nothing from the row.
    $sent = count($this->tg->sent);
    micTap(9001, 'org1:accept:'.$invitation->id, 300);
    expect(array_slice(micTexts(), $sent))->toBe(['Это приглашение больше не действует.']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    expect($invitation->state())->toBe(MemberInvitationState::Expired);
});

it('ignores the buttons of somebody else\'s invitation, and a learner cannot accept one by guessing ids', function (): void {
    $invitation = micInvite();
    $stranger = Learner::factory()->onboarded()->create(['telegram_user_id' => 9002]);

    micTap(9002, 'org1:accept:'.$invitation->id);

    expect($invitation->fresh()?->accepted_at)->toBeNull()
        ->and(end($this->tg->sent)['chat_id'])->toBe(9002)
        ->and(end($this->tg->sent)['text'])->toBe('Это приглашение больше не действует.')
        ->and(OrganizationMember::query()->where('learner_id', $stranger->id)->exists())->toBeFalse();

    micTap(9002, 'org1:accept:999999');
    expect(OrganizationMember::query()->count())->toBe(0);
});

it('does not accept an invitation that expired; a fresh one after the DM cooldown messages the learner again', function (): void {
    $invitation = micInvite();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 12:00:01', 'UTC'));

    micTap(9001, 'org1:accept:'.$invitation->id);
    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(end($this->tg->sent)['text'])->toBe('Это приглашение больше не действует.')
        ->and($invitation->fresh()?->state())->toBe(MemberInvitationState::Expired);

    $sent = count($this->tg->sent);
    $fresh = micInvite();
    expect($fresh->id)->not->toBe($invitation->id)
        ->and($fresh->dm_sent_at?->toIso8601String())->toBe('2026-11-17T12:00:01+00:00')
        ->and(count($this->tg->sent))->toBe($sent + 1);
    micTap(9001, 'org1:accept:'.$fresh->id);
    expect(OrganizationMember::query()->where('learner_id', $this->learner->id)->value('status'))->toBe('active');
});

it('does not accept for a suspended center', function (): void {
    $invitation = micInvite();
    $this->org->update(['status' => 'suspended']);

    micTap(9001, 'org1:accept:'.$invitation->id);

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(end($this->tg->sent)['text'])->toBe('Центр сейчас не принимает учеников. Обратитесь к администратору центра.');
});

// --- the staff takes the invitation back (W7) -----------------------------------------------------------

it('revoke keeps the row as "revoked", audits it, and the old buttons then say the invitation is gone; only an open row of this center can be revoked', function (): void {
    $invitation = micInvite();

    $this->service->revokeInvite($this->org, $invitation, $this->owner, '10.0.0.1');

    expect($invitation->fresh()?->state())->toBe(MemberInvitationState::Revoked)
        ->and(AuditLog::query()->where('action', OrganizationMembershipService::AUDIT_INVITE_REVOKED)->sole()->meta)->toMatchArray(['handle' => '@ali_learner']);

    micTap(9001, 'org1:accept:'.$invitation->id);
    expect(end($this->tg->sent)['text'])->toBe('Это приглашение больше не действует.')
        ->and(OrganizationMember::query()->count())->toBe(0);

    expect(fn () => $this->service->revokeInvite($this->org, $invitation, $this->owner))->toThrow(OrganizationMembershipException::class, 'not waiting');

    $foreign = Organization::factory()->create();
    $pendingOther = $this->service->inviteByStaff($foreign, '9001', $this->owner);
    expect(fn () => $this->service->revokeInvite($this->org, $pendingOther, $this->owner))->toThrow(OrganizationMembershipException::class, 'not waiting');
    expect($pendingOther->fresh()?->state())->toBe(MemberInvitationState::Pending);
});

it('invite → revoke → invite sends ONE DM per cooldown window; the second row looks exactly like the first to the staff', function (): void {
    $first = micInvite();
    $this->service->revokeInvite($this->org, $first, $this->owner);
    $sent = count($this->tg->sent);

    $second = micInvite();
    expect($second->id)->not->toBe($first->id)
        ->and($second->dm_sent_at)->toBeNull()
        ->and(count($this->tg->sent))->toBe($sent)
        ->and(micVisible($second))->toBe([...micVisible($first), 'state' => 'pending']);

    // The buttons of the first message are dead (revoked) and the second invitation has no message of its own. After the
    // window a third invitation messages the learner again.
    micTap(9001, 'org1:accept:'.$first->id);
    expect(OrganizationMember::query()->count())->toBe(0);

    $this->service->revokeInvite($this->org, $second, $this->owner);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 12:00:01', 'UTC'));
    $sent = count($this->tg->sent);
    $third = micInvite();
    expect($third->dm_sent_at)->not->toBeNull()
        ->and(count($this->tg->sent))->toBe($sent + 1)
        ->and($this->tg->lastCallbackData())->toBe(['org1:accept:'.$third->id, 'org1:decline:'.$third->id]);
});

it('a learner who declined gets no DM from the same center within the decline cooldown, even after the row expired', function (): void {
    config(['organizations.member_invite.dm_cooldown_days' => 1, 'organizations.member_invite.redecline_cooldown_days' => 10]);
    $first = micInvite();
    micTap(9001, 'org1:decline:'.$first->id);
    $sent = count($this->tg->sent);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    $second = micInvite();
    expect($second->id)->not->toBe($first->id)
        ->and($second->dm_sent_at)->toBeNull()
        ->and(count($this->tg->sent))->toBe($sent);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC'));
    $third = micInvite();
    expect($third->dm_sent_at)->not->toBeNull()
        ->and(count($this->tg->sent))->toBe($sent + 1);
});

// --- an invitation is not a member anywhere -----------------------------------------------------------

it('keeps an invited learner out of every membership read: seats, active membership, TWA "my centers", the pure "is member" checks', function (): void {
    micInvite();

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and($this->service->activeMembership($this->learner, $this->org))->toBeNull()
        ->and($this->service->seatsSummary($this->org)->seated)->toBe(0)
        ->and($this->service->seatsSummary($this->org)->waitlisted)->toBe(0)
        ->and(app(LearnerOrganizationsService::class)->memberships($this->learner))->toHaveCount(0);
});

it('marks the open invitation accepted when the learner joins through a group of the center (they entered the chat themselves)', function (): void {
    $invitation = micInvite();
    $group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);

    $this->service->joinFromGroup($this->learner, $group);

    expect(OrganizationMember::query()->sole()->joined_via)->toBe('group')
        ->and($invitation->fresh()?->state())->toBe(MemberInvitationState::Accepted);
});

it('raises OrganizationMemberInvited after commit with the ids the listener needs — and not for a handle nobody has', function (): void {
    Event::fake([OrganizationMemberInvited::class]);

    $invitation = micInvite();
    micInvite('@nobody_here');

    Event::assertDispatchedTimes(OrganizationMemberInvited::class, 1);
    Event::assertDispatched(OrganizationMemberInvited::class, fn (OrganizationMemberInvited $e): bool => $e->invitationId === $invitation->id
        && $e->learnerId === $this->learner->id
        && $e->organizationId === $this->org->id);
});

it('sends the invitation in the learner\'s language and skips a learner who became unreachable before the queued DM ran', function (): void {
    $this->learner->forceFill(['interface_locale' => 'uz'])->save();
    micInvite();
    expect(end($this->tg->sent)['text'])->toStartWith("«Bright Center» o'quv markazi Sizni qo'shilishga taklif qilmoqda.");

    $other = Learner::factory()->onboarded()->create(['telegram_user_id' => 9004]);
    Event::fake([OrganizationMemberInvited::class]);
    $invitation = $this->service->inviteByStaff($this->org, '9004', $this->owner);
    $other->forceFill(['dm_blocked_at' => now()])->save();
    $sent = count($this->tg->sent);

    app(OrganizationMessenger::class)->offerMembership($invitation->id);

    expect(count($this->tg->sent))->toBe($sent);
});

it('never serialises the internal columns of an invitation', function (): void {
    $invitation = micInvite();

    expect($invitation->toArray())->not->toHaveKeys(['learner_id', 'dm_sent_at', 'declined_at'])
        ->and(json_encode($invitation->fresh()))->not->toContain('learner_id')->not->toContain('dm_sent_at')->not->toContain('declined_at');
});
