<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

/**
 * Random `learners.referral_code` values (docs/13 §1.5): `code_length` characters of an alphabet
 * without 0/O/1/I — read aloud and typed by hand. A separate class so tests can force a collision.
 */
class ReferralCodeGenerator
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 12;

    public function generate(): string
    {
        $length = max(self::MIN_LENGTH, min(self::MAX_LENGTH, (int) config('referrals.code_length', 8)));
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
