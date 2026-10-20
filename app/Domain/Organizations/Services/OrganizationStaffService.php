<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrganizationStaffException;
use App\Mail\OrgStaffInvitationMail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use LogicException;

/**
 * Staff of a learning center in the `/org` cabinet (docs/13 §1.3, §2.3, §6.3): the owner invites
 * a teacher by email, changes roles, assigns groups (`teacher_groups`) and removes staff. Every
 * change is audited with the acting user.
 *
 * Invitation: an unknown email becomes a `users` row with a random password and gets a letter with
 * a password-reset link of the `org` broker (single-use, stored hashed, 24 h — config
 * `auth.passwords.org`); a known account only gets "you were added, log in here". Either way the
 * owner learns nothing about whether the address existed before: the response is the same row.
 * Repeating an invitation for an address that is already staff is refused (`already_staff`); an
 * expired link is refused by the broker on the reset page (nothing to do here).
 *
 * Invariants kept under the organization row lock (`organizations FOR UPDATE`, first in the lock
 * order of docs/13 §3): at least one owner remains; a group is only ever assigned to a teacher
 * of the SAME organization. Mail goes out after the commit.
 */
final class OrganizationStaffService
{
    public const AUDIT_INVITED = 'organization.staff_invited';

    public const AUDIT_INVITATION_RESENT = 'organization.staff_invitation_resent';

    public const AUDIT_ROLE_CHANGED = 'organization.staff_role_changed';

    public const AUDIT_REMOVED = 'organization.staff_removed';

    public const AUDIT_GROUPS_CHANGED = 'organization.staff_groups_changed';

    public const PASSWORD_BROKER = 'org';

    /**
     * @throws OrganizationStaffException invalid_email | already_staff
     */
    public function invite(Organization $organization, string $email, ?string $name, OrganizationRole $role, User $by, ?string $ip = null): OrganizationUser
    {
        $email = Str::lower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw OrganizationStaffException::invalidEmail($email);
        }

        /** @var array{staff: OrganizationUser, user: User, created: bool} $result */
        $result = DB::transaction(function () use ($organization, $email, $name, $role, $by, $ip): array {
            $this->lockOrganization($organization);

            /** @var User|null $user */
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $created = false;

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $this->nameFor($name, $email),
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'is_active' => true,
                ]);
                $created = true;
            } elseif ($user->organizationRole($organization) !== null) {
                throw OrganizationStaffException::alreadyStaff($email, $organization->id);
            }

            $staff = OrganizationUser::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $role->value,
                'invited_by_user_id' => $by->id,
            ]);

            $this->audit(self::AUDIT_INVITED, $organization, $staff, $by, $ip, [
                'email' => $email,
                'role' => $role->value,
                'new_account' => $created,
            ]);

            return ['staff' => $staff, 'user' => $user, 'created' => $created];
        });

        $this->sendInvitation($organization, $result['user'], $by, $result['created']);

        return $result['staff'];
    }

    /**
     * A new letter with a fresh reset link (the previous token of the address is replaced).
     *
     * @throws OrganizationStaffException not_staff
     */
    public function resendInvitation(Organization $organization, OrganizationUser $staff, User $by, ?string $ip = null): void
    {
        $this->assertStaffOf($organization, $staff);

        /** @var User $user */
        $user = $staff->user()->firstOrFail();

        $this->audit(self::AUDIT_INVITATION_RESENT, $organization, $staff, $by, $ip, ['email' => $user->email]);
        $this->sendInvitation($organization, $user, $by, true);
    }

    /**
     * @throws OrganizationStaffException not_staff | last_owner
     */
    public function changeRole(Organization $organization, OrganizationUser $staff, OrganizationRole $role, User $by, ?string $ip = null): OrganizationUser
    {
        $this->assertStaffOf($organization, $staff);

        return DB::transaction(function () use ($organization, $staff, $role, $by, $ip): OrganizationUser {
            $this->lockOrganization($organization);

            /** @var OrganizationUser $current */
            $current = OrganizationUser::query()->whereKey($staff->id)->firstOrFail();

            if ($current->role === $role->value) {
                return $current;
            }

            if ($current->role === OrganizationRole::Owner->value && $this->ownersCount($organization) <= 1) {
                throw OrganizationStaffException::lastOwner($organization->id);
            }

            $from = $current->role;
            $current->update(['role' => $role->value]);

            $this->audit(self::AUDIT_ROLE_CHANGED, $organization, $current, $by, $ip, ['from' => $from, 'to' => $role->value]);

            return $current;
        });
    }

    /**
     * Removes the staff row and the user's assignments to the organization's groups
     * (`teacher_groups`); the `users` account itself stays (it may be staff elsewhere).
     *
     * @throws OrganizationStaffException not_staff | last_owner
     */
    public function remove(Organization $organization, OrganizationUser $staff, User $by, ?string $ip = null): void
    {
        $this->assertStaffOf($organization, $staff);

        DB::transaction(function () use ($organization, $staff, $by, $ip): void {
            $this->lockOrganization($organization);

            /** @var OrganizationUser|null $current */
            $current = OrganizationUser::query()->whereKey($staff->id)->first();

            if ($current === null) {
                return;
            }

            if ($current->role === OrganizationRole::Owner->value && $this->ownersCount($organization) <= 1) {
                throw OrganizationStaffException::lastOwner($organization->id);
            }

            $detached = DB::table('teacher_groups')
                ->where('user_id', $current->user_id)
                ->whereIn('telegram_group_id', $this->organizationGroupIds($organization))
                ->delete();

            $this->audit(self::AUDIT_REMOVED, $organization, $current, $by, $ip, [
                'role' => $current->role,
                'groups_detached' => $detached,
            ]);

            $current->delete();
        });
    }

    /**
     * Sets the organization's groups the staff member teaches (`teacher_groups`), leaving their
     * assignments in other organizations untouched.
     *
     * @param  list<int>  $groupIds
     *
     * @throws OrganizationStaffException not_staff | foreign_group
     */
    public function syncGroups(Organization $organization, OrganizationUser $staff, array $groupIds, User $by, ?string $ip = null): void
    {
        $this->assertStaffOf($organization, $staff);

        $groupIds = array_values(array_unique(array_map(intval(...), $groupIds)));

        DB::transaction(function () use ($organization, $staff, $groupIds, $by, $ip): void {
            $this->lockOrganization($organization);

            $ownGroupIds = $this->organizationGroupIds($organization);

            foreach ($groupIds as $groupId) {
                if (! in_array($groupId, $ownGroupIds, true)) {
                    throw OrganizationStaffException::foreignGroup($groupId, $organization->id);
                }
            }

            /** @var list<int> $current */
            $current = DB::table('teacher_groups')
                ->where('user_id', $staff->user_id)
                ->whereIn('telegram_group_id', $ownGroupIds)
                ->pluck('telegram_group_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            $toDetach = array_values(array_diff($current, $groupIds));
            $toAttach = array_values(array_diff($groupIds, $current));

            if ($toDetach !== []) {
                DB::table('teacher_groups')
                    ->where('user_id', $staff->user_id)
                    ->whereIn('telegram_group_id', $toDetach)
                    ->delete();
            }

            if ($toAttach !== []) {
                $hasAny = DB::table('teacher_groups')->where('user_id', $staff->user_id)->exists();
                $now = now();

                DB::table('teacher_groups')->insert(array_map(fn (int $groupId, int $i): array => [
                    'user_id' => $staff->user_id,
                    'telegram_group_id' => $groupId,
                    // The first group a teacher ever gets is their primary one (Phase-1 semantics of the column).
                    'is_primary' => ! $hasAny && $i === 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $toAttach, array_keys($toAttach)));
            }

            if ($toDetach !== [] || $toAttach !== []) {
                $this->audit(self::AUDIT_GROUPS_CHANGED, $organization, $staff, $by, $ip, [
                    'attached' => $toAttach,
                    'detached' => $toDetach,
                ]);
            }
        });
    }

    /**
     * Ids of the organization's groups the staff member teaches.
     *
     * @return list<int>
     */
    public function groupIdsOf(Organization $organization, OrganizationUser $staff): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('teacher_groups')
            ->where('user_id', $staff->user_id)
            ->whereIn('telegram_group_id', $this->organizationGroupIds($organization))
            ->pluck('telegram_group_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    // ---------------------------------------------------------------------------------------------

    private function sendInvitation(Organization $organization, User $user, User $by, bool $withResetLink): void
    {
        $panel = Filament::getPanel(self::PASSWORD_BROKER);

        if ($withResetLink) {
            $broker = Password::broker(self::PASSWORD_BROKER);

            if (! $broker instanceof PasswordBroker) {
                throw new LogicException('The org password broker must be the database-backed PasswordBroker (createToken).');
            }

            $url = $panel->getResetPasswordUrl($broker->createToken($user), $user);
        } else {
            $url = (string) $panel->getUrl($organization);
        }

        $expireMinutes = (int) config('auth.passwords.'.self::PASSWORD_BROKER.'.expire', 60);

        Mail::to($user->email)->send(
            (new OrgStaffInvitationMail(
                organizationName: $organization->name,
                inviterName: $by->name,
                url: $url,
                withResetLink: $withResetLink,
                expiresHours: max(1, intdiv($expireMinutes, 60)),
            ))->locale($organization->locale),
        );
    }

    /** @throws OrganizationStaffException not_staff */
    private function assertStaffOf(Organization $organization, OrganizationUser $staff): void
    {
        if ($staff->organization_id !== $organization->id) {
            throw OrganizationStaffException::notStaff($organization->id);
        }
    }

    private function lockOrganization(Organization $organization): void
    {
        Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
    }

    private function ownersCount(Organization $organization): int
    {
        return OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::Owner->value)
            ->count();
    }

    /** @return list<int> */
    private function organizationGroupIds(Organization $organization): array
    {
        /** @var list<int> $ids */
        $ids = TelegramGroup::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    private function nameFor(?string $name, string $email): string
    {
        $name = $name === null ? '' : trim($name);

        return $name !== '' ? mb_substr($name, 0, 255) : Str::before($email, '@');
    }

    /** @param array<string, mixed> $meta */
    private function audit(string $action, Organization $organization, OrganizationUser $staff, User $by, ?string $ip, array $meta): void
    {
        AuditLog::create([
            'user_id' => $by->id,
            'action' => $action,
            'entity_type' => OrganizationUser::class,
            'entity_id' => $staff->id,
            'ip' => $ip,
            'meta' => ['organization_id' => $organization->id, 'staff_user_id' => $staff->user_id, ...$meta],
        ]);
    }
}
