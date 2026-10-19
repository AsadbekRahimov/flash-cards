<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff row: a `users` account with a tenant role in one organization (docs/13 §1.3).
 * Read by the `/org` panel (2B.3) for `getTenants()` / `canAccessTenant()`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $role
 * @property int|null $invited_by_user_id
 */
class OrganizationUser extends Model
{
    /** @use HasFactory<OrganizationUserFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'user_id', 'role', 'invited_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'user_id' => 'integer',
            'invited_by_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
