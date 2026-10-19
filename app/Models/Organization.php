<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string $status
 * @property string $timezone
 * @property string $locale
 * @property array<string, mixed> $settings
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'timezone',
        'locale',
        'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** @return HasMany<TelegramGroup, $this> */
    public function telegramGroups(): HasMany
    {
        return $this->hasMany(TelegramGroup::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'subscriber_id')
            ->where('subscriber_type', 'organization');
    }

    /**
     * Every membership row, whatever its status (docs/13 §1.3).
     *
     * @return HasMany<OrganizationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /** @return HasMany<OrganizationInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(OrganizationInvite::class);
    }

    /**
     * Staff rows (`organization_users`): owners and teachers of the cabinet.
     *
     * @return HasMany<OrganizationUser, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
