<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Models\AuditLog;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creation of promo codes by an admin (Filament `PromoCodeResource`). Redeeming is
 * {@see PromoCodeService}. Who may create is decided by `PromoCodePolicy` at the call site.
 */
final class PromoCodeAdminService
{
    public const ACTION_CREATED = 'promo.created';

    /**
     * The code is stored normalized (upper-case, no spaces / dashes), so what the learner types
     * and what is stored always match; `created_by_user_id` is the creator.
     *
     * @param  array{code: string, plan: string, duration_days: int, max_redemptions?: int|null, expires_at?: mixed, is_active?: bool, note?: string|null}  $data
     */
    public function create(array $data, User $by, ?string $ip = null): PromoCode
    {
        return DB::transaction(function () use ($data, $by, $ip): PromoCode {
            $promo = PromoCode::query()->create([
                'code' => PromoCodeService::normalize($data['code']),
                'plan' => $data['plan'],
                'duration_days' => $data['duration_days'],
                'max_redemptions' => $data['max_redemptions'] ?? null,
                'redemptions_count' => 0,
                'expires_at' => $data['expires_at'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by_user_id' => $by->id,
                'note' => $data['note'] ?? null,
            ]);

            AuditLog::create([
                'user_id' => $by->id,
                'action' => self::ACTION_CREATED,
                'entity_type' => PromoCode::class,
                'entity_id' => $promo->id,
                'ip' => $ip,
                'meta' => [
                    'code' => $promo->code,
                    'plan' => $promo->plan,
                    'duration_days' => $promo->duration_days,
                    'max_redemptions' => $promo->max_redemptions,
                    'expires_at' => $promo->expires_at?->toIso8601String(),
                    'note' => $promo->note,
                ],
            ]);

            return $promo;
        });
    }
}
