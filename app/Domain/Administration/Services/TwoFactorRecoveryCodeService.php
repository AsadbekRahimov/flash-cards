<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TwoFactorRecoveryCodeService
{
    public function consume(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            /** @var User|null $lockedUser */
            $lockedUser = User::query()
                ->lockForUpdate()
                ->find($user->getKey());

            if ($lockedUser === null) {
                return false;
            }

            /** @var list<string> $recoveryCodes */
            $recoveryCodes = (array) $lockedUser->two_factor_recovery_codes;
            $index = array_search($code, $recoveryCodes, true);

            if ($index === false) {
                return false;
            }

            unset($recoveryCodes[$index]);

            $lockedUser->forceFill([
                'two_factor_recovery_codes' => array_values($recoveryCodes),
            ])->save();

            return true;
        });
    }
}
