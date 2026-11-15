<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgTenantScope;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * Platform account: superadmin / Phase-1 teacher of `/admin` (spatie roles, guard `web`) and / or
 * staff of learning centers in `/org` (tenant roles in `organization_users`, guard `org`;
 * docs/13 §1.3, §6.3). The two never mix: `canAccessPanel()` reads spatie roles for `admin` and
 * `organization_users` for `org`.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $telegram_user_id
 * @property Carbon|null $telegram_verified_at set only by the bot (`/start link_<token>`); a hand-typed id stays unverified
 * @property string|null $telegram_username
 * @property string|null $telegram_name
 * @property Carbon|null $telegram_superseded_at the unverified id was claimed through the bot by another user (W1); cleared by the next link / unlink
 * @property bool $is_active
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'telegram_user_id',
        'telegram_verified_at',
        'telegram_username',
        'telegram_name',
        'telegram_superseded_at',
        'is_active',
        'last_login_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'telegram_user_id' => 'integer',
            'telegram_verified_at' => 'datetime',
            'telegram_superseded_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * The Telegram account was connected by the person themselves through the bot (docs/07 §5, audit 2026-11-10 H1).
     * Only this proves the account is theirs; operations that rely on the id as proof of ownership (linking a group to
     * a center) require it. A hand-typed id (legacy rows, `/admin`) still works for the teacher's group commands.
     */
    public function hasVerifiedTelegram(): bool
    {
        return $this->telegram_user_id !== null && $this->telegram_verified_at !== null;
    }

    /** @return BelongsToMany<TelegramGroup, $this> */
    public function telegramGroups(): BelongsToMany
    {
        return $this->belongsToMany(TelegramGroup::class, 'teacher_groups')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /** @return HasMany<TrainingSession, $this> */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class, 'started_by_user_id');
    }

    /** @return HasMany<ExamSession, $this> */
    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'started_by_user_id');
    }

    /**
     * Tenant roles of the `/org` cabinet (`organization_users`, docs/13 §1.3).
     *
     * @return HasMany<OrganizationUser, $this>
     */
    public function organizationRoles(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Tenant role of this user in one organization (`null` = not staff there). Read through
     * {@see OrgTenantScope}, i.e. once per request: the tenant check and every policy of the `/org`
     * panel share that one lookup, and a staff row written in the same request drops it (OrgRequestMemo).
     */
    public function organizationRole(Organization|int $organization): ?OrganizationRole
    {
        return app(OrgTenantScope::class)->role($this, $organization);
    }

    public function isOrganizationOwner(Organization|int $organization): bool
    {
        return $this->organizationRole($organization) === OrganizationRole::Owner;
    }

    /** Owner of at least one organization: 2FA is mandatory in `/org` (docs/13 §6.3). */
    public function ownsAnyOrganization(): bool
    {
        return $this->organizationRoles()->where('role', OrganizationRole::Owner->value)->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_active !== true) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->hasAnyRole(['admin', 'teacher']),
            // Staff of at least one organization; the tenant itself is checked by canAccessTenant().
            'org' => $this->organizationRoles()->exists(),
            default => false,
        };
    }

    /**
     * Organizations this user may open in `/org` (Filament tenancy).
     *
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($panel->getId() !== 'org') {
            return new Collection;
        }

        return $this->organizations()->orderBy('organizations.name')->get();
    }

    /** A row in `organization_users` — nothing else (a platform admin has no implicit access). */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Organization
            && $this->is_active === true
            && $this->organizationRole($tenant) !== null;
    }
}
