<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/**
 * Brute-force protection of the 2FA endpoints (per account+guard and per IP) and TOTP replay
 * protection (a time step accepted once is never accepted again).
 */
final class TwoFactorAttemptService
{
    public const ACCOUNT_MAX_ATTEMPTS = 5;

    public const IP_MAX_ATTEMPTS = 20;

    private const DECAY_SECONDS = 60;

    /** > the TOTP validity window (±1 step of 30 s), so an accepted step cannot come back. */
    private const STEP_TTL_SECONDS = 180;

    public function __construct(private readonly Google2FA $g2fa) {}

    /** Seconds to wait, or null if the attempt is allowed. */
    public function retryAfter(string $guard, User $user, string $ip): ?int
    {
        foreach ([[$this->accountKey($guard, $user), self::ACCOUNT_MAX_ATTEMPTS], [$this->ipKey($ip), self::IP_MAX_ATTEMPTS]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return null;
    }

    public function recordFailure(string $guard, User $user, string $ip, string $reason): void
    {
        RateLimiter::hit($this->accountKey($guard, $user), self::DECAY_SECONDS);
        RateLimiter::hit($this->ipKey($ip), self::DECAY_SECONDS);

        Log::warning('2FA attempt failed', [
            'user_id' => $user->id,
            'guard' => $guard,
            'ip' => $ip,
            'reason' => $reason,
        ]);
    }

    public function recordSuccess(string $guard, User $user): void
    {
        RateLimiter::clear($this->accountKey($guard, $user));
    }

    public function throttled(string $guard, User $user, string $ip): void
    {
        Log::warning('2FA attempts throttled', ['user_id' => $user->id, 'guard' => $guard, 'ip' => $ip]);
    }

    /** TOTP check that also rejects the same or an earlier time step than the last accepted one. */
    public function verifyTotp(string $guard, User $user, string $secret, string $code): bool
    {
        $cacheKey = "2fa:last_step:{$guard}:{$user->id}";
        $last = Cache::get($cacheKey);

        // A non-null old timestamp makes the library return the matched step (null would give `true`).
        $step = $this->g2fa->verifyKeyNewer($secret, $code, is_int($last) ? $last : 0);

        if (! is_int($step)) {
            return false;
        }

        Cache::put($cacheKey, $step, self::STEP_TTL_SECONDS);

        return true;
    }

    private function accountKey(string $guard, User $user): string
    {
        return "2fa:acct:{$guard}:{$user->id}";
    }

    private function ipKey(string $ip): string
    {
        return "2fa:ip:{$ip}";
    }
}
