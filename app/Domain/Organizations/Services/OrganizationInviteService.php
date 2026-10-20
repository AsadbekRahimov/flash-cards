<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Invite codes of an organization (docs/13 §1.3, §6.2): create / deactivate / deep-link.
 * Redeeming a code is OrganizationMembershipService::joinByInvite.
 */
final class OrganizationInviteService
{
    /** Unambiguous alphabet: no 0/O/1/I (docs/13 §1.3). */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_ATTEMPTS = 5;

    /**
     * @param  array{label?: string|null, telegram_group_id?: int|null, max_uses?: int|null, expires_at?: CarbonInterface|null}  $attrs
     */
    public function create(Organization $organization, ?User $by, array $attrs = []): OrganizationInvite
    {
        $maxUses = $attrs['max_uses'] ?? null;
        $expiresAt = $attrs['expires_at'] ?? null;

        for ($attempt = 1; ; $attempt++) {
            try {
                return OrganizationInvite::query()->create([
                    'organization_id' => $organization->id,
                    'code' => self::generateCode(),
                    'label' => self::label($attrs['label'] ?? null),
                    'telegram_group_id' => $attrs['telegram_group_id'] ?? null,
                    'max_uses' => $maxUses === null ? null : max(1, $maxUses),
                    'uses_count' => 0,
                    'expires_at' => $expiresAt === null ? null : CarbonImmutable::instance($expiresAt),
                    'is_active' => true,
                    'created_by_user_id' => $by?->id,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // 32^10 codes: a collision is a curiosity, not a plan; a few retries cover it.
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    public function deactivate(OrganizationInvite $invite, ?User $by = null): OrganizationInvite
    {
        if ($invite->is_active) {
            $invite->update(['is_active' => false]);

            Log::info('organizations.invite_deactivated', [
                'invite_id' => $invite->id,
                'organization_id' => $invite->organization_id,
                'by_user_id' => $by?->id,
            ]);
        }

        return $invite;
    }

    /** Deep-link `https://t.me/<bot>?start=org_<code>` (StartPayload already accepts `org_…`). */
    public function link(OrganizationInvite $invite): string
    {
        $bot = ltrim(trim((string) config('telegram.bot_username', '')), '@');

        return "https://t.me/{$bot}?start=".self::startPayload($invite->code);
    }

    public static function startPayload(string $code): string
    {
        return 'org_'.$code;
    }

    /** Lookup form of what a user typed / a deep-link carried: upper case, no surrounding blanks. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function generateCode(): string
    {
        $length = max(6, min(16, (int) config('organizations.invite_code_length', 10)));
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private static function label(?string $label): ?string
    {
        $label = $label === null ? '' : trim($label);

        return $label === '' ? null : mb_substr($label, 0, 64);
    }
}
