<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Organizations\Enums\MemberInvitationState;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationMemberInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt of the center's staff to invite a learner by `@username` / Telegram id (docs/13 §6.2, audit 2026-11-10 M2 and the
 * review finding B1). Written only by OrganizationMembershipService.
 *
 * What the cabinet may show is `invited_handle`, `created_at`, `expires_at` and {@see self::state()} — the SAME for a handle that
 * matched nobody, a learner who never answered, a learner who declined and a learner the bot could not reach. `learner_id`,
 * `dm_sent_at` and `declined_at` are internal (the bot's buttons, the DM cooldown): they are `$hidden` from any serialisation
 * and no resource, column or export reads them.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $invited_handle `@username` (lower case) or the digits of a Telegram id — exactly what was typed, normalised
 * @property int|null $learner_id internal
 * @property int|null $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $dm_sent_at internal: the moment the "Join / Decline" DM was reserved for this learner
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at internal
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read Organization $organization
 */
class OrganizationMemberInvitation extends Model
{
    /** @use HasFactory<OrganizationMemberInvitationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'invited_handle',
        'learner_id',
        'invited_by_user_id',
        'expires_at',
        'dm_sent_at',
        'accepted_at',
        'declined_at',
        'revoked_at',
    ];

    /** Never leaves the server: whether the handle matched a learner and what that learner did must not be readable. */
    protected $hidden = ['learner_id', 'dm_sent_at', 'declined_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'learner_id' => 'integer',
            'invited_by_user_id' => 'integer',
            'expires_at' => 'datetime',
            'dm_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The state the staff may see. A decline is NOT a state of its own: the row stays "waiting" until `expires_at` and then
     * reads "expired", exactly like an invitation nobody answered or a handle that matched nobody.
     */
    public function state(?CarbonImmutable $now = null): MemberInvitationState
    {
        $now ??= CarbonImmutable::now();

        if ($this->accepted_at !== null) {
            return MemberInvitationState::Accepted;
        }

        if ($this->revoked_at !== null) {
            return MemberInvitationState::Revoked;
        }

        return $this->expires_at->lessThanOrEqualTo($now) ? MemberInvitationState::Expired : MemberInvitationState::Pending;
    }

    /** Still open for the staff: no answer that closed it, not revoked, not expired (a decline keeps it open for the staff). */
    public function isOpenAt(CarbonImmutable $now): bool
    {
        return $this->state($now) === MemberInvitationState::Pending;
    }

    /** Still answerable by the learner: open AND not declined (the bot's buttons act on this; the cabinet never asks). */
    public function isAnswerableAt(CarbonImmutable $now): bool
    {
        return $this->isOpenAt($now) && $this->declined_at === null;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
