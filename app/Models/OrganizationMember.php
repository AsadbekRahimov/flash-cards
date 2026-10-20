<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Organizations\Enums\JoinedVia;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\SeatState;
use Database\Factories\OrganizationMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Membership of a learner in an organization and its seat (docs/13 §1.3).
 * Written only by OrganizationMembershipService (under the organization row lock).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $learner_id
 * @property string $status
 * @property string $seat_state
 * @property string $joined_via
 * @property int|null $invite_id
 * @property Carbon|null $seated_at
 * @property Carbon|null $waitlisted_at
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property array<string, mixed> $meta
 * @property-read Organization $organization both FKs are NOT NULL with cascade: the parents of a membership always exist
 * @property-read Learner $learner
 */
class OrganizationMember extends Model
{
    /** @use HasFactory<OrganizationMemberFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'learner_id',
        'status',
        'seat_state',
        'joined_via',
        'invite_id',
        'seated_at',
        'waitlisted_at',
        'joined_at',
        'left_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'learner_id' => 'integer',
            'invite_id' => 'integer',
            'seated_at' => 'datetime',
            'waitlisted_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active->value;
    }

    /** Holds a seat: active and seated. */
    public function isSeated(): bool
    {
        return $this->isActive() && $this->seat_state === SeatState::Seated->value;
    }

    /**
     * Whether the learner may end this membership themselves: invite / admin ones. A `group`
     * membership comes back with the next entry to a group chat of the center (docs/13 §6.2).
     */
    public function isLeavableByLearner(): bool
    {
        return $this->joined_via !== JoinedVia::Group->value;
    }

    public function isWaitlisted(): bool
    {
        return $this->isActive() && $this->seat_state === SeatState::Waitlisted->value;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<OrganizationInvite, $this> */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvite::class, 'invite_id');
    }
}
