<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrganizationInviteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Invite code of an organization (docs/13 §1.3): `t.me/<bot>?start=org_<code>`.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string|null $label
 * @property int|null $telegram_group_id
 * @property int|null $max_uses
 * @property int $uses_count
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property-read Organization $organization FK is NOT NULL with cascade: an invite always has its organization
 */
class OrganizationInvite extends Model
{
    /** @use HasFactory<OrganizationInviteFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'code',
        'label',
        'telegram_group_id',
        'max_uses',
        'uses_count',
        'expires_at',
        'is_active',
        'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'telegram_group_id' => 'integer',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
        ];
    }

    public function isExpiredAt(CarbonImmutable $now): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($now);
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses_count >= $this->max_uses;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<TelegramGroup, $this> */
    public function telegramGroup(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<OrganizationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class, 'invite_id');
    }
}
