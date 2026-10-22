<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use Illuminate\Contracts\Session\Session;

/**
 * Per-guard 2FA marks in the (shared) session: `2fa.{guard}.passed_at|user_id|pending_secret|...`.
 * The `web` (/admin) and `org` (/org) guards can be logged in at the same time by different
 * accounts, so a mark is only valid for the account recorded next to it in the SAME guard.
 */
final class TwoFactorSession
{
    public const GUARDS = ['web', 'org'];

    public static function key(string $guard, string $name): string
    {
        return "2fa.{$guard}.{$name}";
    }

    public static function hasPassed(Session $session, string $guard, int $userId): bool
    {
        return $session->get(self::key($guard, 'passed_at')) !== null
            && (int) $session->get(self::key($guard, 'user_id'), 0) === $userId;
    }

    public static function markPassed(Session $session, string $guard, int $userId): void
    {
        $session->put(self::key($guard, 'passed_at'), now()->timestamp);
        $session->put(self::key($guard, 'user_id'), $userId);
    }

    public static function pendingSecret(Session $session, string $guard, int $userId): ?string
    {
        $secret = $session->get(self::key($guard, 'pending_secret'));

        return is_string($secret) && $secret !== ''
            && (int) $session->get(self::key($guard, 'pending_user_id'), 0) === $userId
            ? $secret
            : null;
    }

    public static function putPendingSecret(Session $session, string $guard, int $userId, string $secret): void
    {
        $session->put(self::key($guard, 'pending_secret'), $secret);
        $session->put(self::key($guard, 'pending_user_id'), $userId);
    }

    public static function forgetPending(Session $session, string $guard): void
    {
        $session->forget([self::key($guard, 'pending_secret'), self::key($guard, 'pending_user_id')]);
    }

    public static function allowRecoveryCodes(Session $session, string $guard, int $userId): void
    {
        $session->put(self::key($guard, 'show_recovery_codes'), $userId);
    }

    /** One-shot: true only if the codes were unlocked for THIS user in this guard. */
    public static function pullRecoveryCodesPermission(Session $session, string $guard, int $userId): bool
    {
        return (int) $session->pull(self::key($guard, 'show_recovery_codes'), 0) === $userId;
    }
}
