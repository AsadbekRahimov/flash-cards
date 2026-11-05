<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TwoFactorEnrollmentService
{
    public function enroll(User $user, string $secret): bool
    {
        return DB::transaction(function () use ($user, $secret): bool {
            $account = User::query()->lockForUpdate()->find($user->id);

            if ($account === null || $account->hasTwoFactorEnabled()) {
                return false;
            }

            $account->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => array_map(
                    static fn (): string => Str::random(10).'-'.Str::random(10),
                    range(1, 8),
                ),
                'two_factor_confirmed_at' => now(),
            ])->save();

            return true;
        });
    }
}
