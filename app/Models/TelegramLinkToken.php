<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Services\TelegramLinkTokens;
use Carbon\CarbonImmutable;
use Database\Factories\TelegramLinkTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time deep-link token of the `/org` cabinet (docs/13 §6.2, audit 2026-11-10 H1): connects a staff user's Telegram
 * account (`account`) or links a Telegram group to the organization (`group`). Only the SHA-256 of the token is stored.
 * Written only by {@see TelegramLinkTokens}.
 *
 * @property int $id
 * @property string $kind
 * @property string $token_hash
 * @property int $user_id
 * @property int|null $organization_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $last_result
 * @property Carbon|null $last_attempt_at
 * @property-read User $user
 * @property-read Organization|null $organization
 */
class TelegramLinkToken extends Model
{
    /** @use HasFactory<TelegramLinkTokenFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'token_hash',
        'user_id',
        'organization_id',
        'expires_at',
        'used_at',
        'last_result',
        'last_attempt_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'organization_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function kind(): ?LinkTokenKind
    {
        return LinkTokenKind::tryFrom($this->kind);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpiredAt(CarbonImmutable $now): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now);
    }

    /** Still redeemable: not used, not expired. */
    public function isLiveAt(CarbonImmutable $now): bool
    {
        return ! $this->isUsed() && ! $this->isExpiredAt($now);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
