<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrganizationStaffException;
use App\Domain\Organizations\Services\OrganizationStaffService;
use App\Mail\OrgStaffInvitationMail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/*
 * OrganizationStaffService (docs/13 §2.3, §6.3): invitation by email with a single-use, expiring
 * password link; roles; group assignment; removal — audited, and never leaving a center without an owner.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    $this->org = Organization::factory()->create(['locale' => 'uz']);
    $this->owner = User::factory()->create();
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id]);
    $this->service = app(OrganizationStaffService::class);
});

function staffTokenFromUrl(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['token'] ?? '');
}

it('invites an unknown email: creates the account, the teacher row, the audit record and a letter with a reset link in the center language', function (): void {
    $staff = $this->service->invite($this->org, ' New.Teacher@Example.com ', 'Nilufar', OrganizationRole::Teacher, $this->owner, '10.0.0.1');

    $user = $staff->user()->firstOrFail();
    expect($user->email)->toBe('new.teacher@example.com')
        ->and($user->name)->toBe('Nilufar')
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($staff->role)->toBe('teacher')
        ->and($staff->invited_by_user_id)->toBe($this->owner->id);

    $audit = AuditLog::query()->where('action', OrganizationStaffService::AUDIT_INVITED)->firstOrFail();
    expect($audit->user_id)->toBe($this->owner->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and($audit->meta)->toMatchArray(['organization_id' => $this->org->id, 'email' => 'new.teacher@example.com', 'new_account' => true]);

    Mail::assertSent(OrgStaffInvitationMail::class, function (OrgStaffInvitationMail $mail) use ($user): bool {
        $token = staffTokenFromUrl($mail->url);

        expect($mail->hasTo('new.teacher@example.com'))->toBeTrue()
            ->and($mail->withResetLink)->toBeTrue()
            ->and($mail->expiresHours)->toBe(24)
            ->and($mail->locale)->toBe('uz')
            ->and($mail->url)->toContain('/org/password-reset/reset')
            ->and($token)->not->toBe('')
            ->and(Password::broker('org')->tokenExists($user, $token))->toBeTrue();

        $rendered = $mail->render();
        expect($rendered)->toContain(e($this->org->name))->toContain(e($mail->url));

        return true;
    });
});

it('invites a known account without a reset link and refuses a repeated invitation', function (): void {
    $existing = User::factory()->create(['email' => 'known@example.com']);

    $staff = $this->service->invite($this->org, 'KNOWN@example.com', null, OrganizationRole::Teacher, $this->owner);

    expect($staff->user_id)->toBe($existing->id)
        ->and(User::query()->count())->toBe(2);
    Mail::assertSent(OrgStaffInvitationMail::class, fn (OrgStaffInvitationMail $mail): bool => ! $mail->withResetLink && str_contains($mail->url, '/org/'.$this->org->slug));

    expect(fn () => $this->service->invite($this->org, 'known@example.com', null, OrganizationRole::Owner, $this->owner))
        ->toThrow(OrganizationStaffException::class, 'already staff');
    expect(OrganizationUser::query()->where('organization_id', $this->org->id)->count())->toBe(2);
    Mail::assertSent(OrgStaffInvitationMail::class, 1);

    // The same account may be staff of another center.
    $other = Organization::factory()->create();
    $otherOwner = User::factory()->create();
    OrganizationUser::factory()->create(['organization_id' => $other->id, 'user_id' => $otherOwner->id]);
    $this->service->invite($other, 'known@example.com', null, OrganizationRole::Teacher, $otherOwner);
    expect($existing->organizationRole($other))->toBe(OrganizationRole::Teacher);
});

it('rejects an invalid email and exposes HTTP statuses for the codes', function (): void {
    expect(fn () => $this->service->invite($this->org, 'not-an-email', null, OrganizationRole::Teacher, $this->owner))
        ->toThrow(OrganizationStaffException::class);

    expect(OrganizationStaffException::invalidEmail('x')->httpStatus())->toBe(422)
        ->and(OrganizationStaffException::alreadyStaff('x', 1)->httpStatus())->toBe(409)
        ->and(OrganizationStaffException::lastOwner(1)->httpStatus())->toBe(409)
        ->and(OrganizationStaffException::notStaff(1)->httpStatus())->toBe(404)
        ->and(OrganizationStaffException::foreignGroup(1, 1)->httpStatus())->toBe(422);
});

it('expires the invitation link with the org broker and replaces it on resend', function (): void {
    $staff = $this->service->invite($this->org, 't@example.com', null, OrganizationRole::Teacher, $this->owner);
    $user = $staff->user()->firstOrFail();

    $token = null;
    Mail::assertSent(OrgStaffInvitationMail::class, function (OrgStaffInvitationMail $mail) use (&$token): bool {
        $token = staffTokenFromUrl($mail->url);

        return true;
    });

    expect(Password::broker('org')->tokenExists($user, (string) $token))->toBeTrue();

    $this->travel(25)->hours();
    expect(Password::broker('org')->tokenExists($user, (string) $token))->toBeFalse();

    $this->service->resendInvitation($this->org, $staff, $this->owner);
    Mail::assertSent(OrgStaffInvitationMail::class, 2);
    expect(AuditLog::query()->where('action', OrganizationStaffService::AUDIT_INVITATION_RESENT)->exists())->toBeTrue();

    $fresh = null;
    Mail::assertSent(OrgStaffInvitationMail::class, function (OrgStaffInvitationMail $mail) use (&$fresh): bool {
        $fresh = staffTokenFromUrl($mail->url);

        return true;
    });
    expect($fresh)->not->toBe($token)
        ->and(Password::broker('org')->tokenExists($user, (string) $fresh))->toBeTrue()
        ->and(Password::broker('org')->tokenExists($user, (string) $token))->toBeFalse();
});

it('keeps at least one owner: the last owner is neither demoted nor removed', function (): void {
    $ownRow = OrganizationUser::query()->where('user_id', $this->owner->id)->firstOrFail();

    expect(fn () => $this->service->changeRole($this->org, $ownRow, OrganizationRole::Teacher, $this->owner))
        ->toThrow(OrganizationStaffException::class, 'at least one owner');
    expect(fn () => $this->service->remove($this->org, $ownRow, $this->owner))
        ->toThrow(OrganizationStaffException::class, 'at least one owner');

    $second = $this->service->invite($this->org, 'second@example.com', null, OrganizationRole::Owner, $this->owner);

    $this->service->changeRole($this->org, $ownRow, OrganizationRole::Teacher, $this->owner, '10.0.0.2');
    expect($ownRow->fresh()?->role)->toBe('teacher')
        ->and(AuditLog::query()->where('action', OrganizationStaffService::AUDIT_ROLE_CHANGED)->value('meta'))->toMatchArray(['from' => 'owner', 'to' => 'teacher']);

    // Now `second` is the last owner.
    expect(fn () => $this->service->remove($this->org, $second, $this->owner))->toThrow(OrganizationStaffException::class);
});

it('refuses staff rows of another organization', function (): void {
    $other = Organization::factory()->create();
    $foreign = OrganizationUser::factory()->create(['organization_id' => $other->id]);

    expect(fn () => $this->service->changeRole($this->org, $foreign, OrganizationRole::Teacher, $this->owner))->toThrow(OrganizationStaffException::class, 'not staff');
    expect(fn () => $this->service->remove($this->org, $foreign, $this->owner))->toThrow(OrganizationStaffException::class, 'not staff');
    expect(fn () => $this->service->syncGroups($this->org, $foreign, [], $this->owner))->toThrow(OrganizationStaffException::class, 'not staff');
    expect($foreign->fresh())->not->toBeNull();
});

it('assigns only the organization groups, keeps assignments elsewhere, and detaches them on removal', function (): void {
    $g1 = TelegramGroup::factory()->create(['organization_id' => $this->org->id]);
    $g2 = TelegramGroup::factory()->create(['organization_id' => $this->org->id]);
    $foreignGroup = TelegramGroup::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $staff = $this->service->invite($this->org, 't@example.com', null, OrganizationRole::Teacher, $this->owner);
    $user = $staff->user()->firstOrFail();
    $user->telegramGroups()->attach($foreignGroup->id, ['is_primary' => true]);

    $this->service->syncGroups($this->org, $staff, [$g1->id, $g2->id], $this->owner);
    expect($this->service->groupIdsOf($this->org, $staff))->toBe([$g1->id, $g2->id])
        ->and(DB::table('teacher_groups')->where('user_id', $user->id)->count())->toBe(3)
        ->and(AuditLog::query()->where('action', OrganizationStaffService::AUDIT_GROUPS_CHANGED)->value('meta'))->toMatchArray(['attached' => [$g1->id, $g2->id], 'detached' => []]);

    expect(fn () => $this->service->syncGroups($this->org, $staff, [$g1->id, $foreignGroup->id], $this->owner))
        ->toThrow(OrganizationStaffException::class, 'does not belong');
    expect($this->service->groupIdsOf($this->org, $staff))->toBe([$g1->id, $g2->id]);

    $this->service->syncGroups($this->org, $staff, [$g2->id], $this->owner);
    expect($this->service->groupIdsOf($this->org, $staff))->toBe([$g2->id]);

    // Idempotent: no audit row for a no-op (two real changes so far; the refused one wrote nothing).
    $this->service->syncGroups($this->org, $staff, [$g2->id], $this->owner);
    expect(AuditLog::query()->where('action', OrganizationStaffService::AUDIT_GROUPS_CHANGED)->count())->toBe(2);

    $this->service->remove($this->org, $staff, $this->owner);
    expect($staff->fresh())->toBeNull()
        ->and($user->fresh())->not->toBeNull()
        ->and(DB::table('teacher_groups')->where('user_id', $user->id)->pluck('telegram_group_id')->all())->toBe([$foreignGroup->id])
        ->and(AuditLog::query()->where('action', OrganizationStaffService::AUDIT_REMOVED)->value('meta'))->toMatchArray(['role' => 'teacher', 'groups_detached' => 1]);
});
