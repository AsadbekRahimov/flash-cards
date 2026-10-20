<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA of the `/org` cabinet (docs/13 §6.3): mandatory for the owner of ANY organization (an owner
 * without a confirmed TOTP is sent to the setup page), optional for a plain teacher — but once a
 * teacher has 2FA enabled, the challenge is required like for everyone else.
 *
 * Differences from RequireTwoFactor of `/admin`: the user comes from the panel's own guard
 * (`org`), the redirect remembers where to come back to (`redirect()->guest()` stores the intended
 * URL, which TwoFactorController's `intended('/admin')` then honours), and the session mark must
 * have been set by THIS user (`2fa.user_id`): the session is shared between guards, so a mark
 * left by a different account logged in through `/admin` must not count here.
 */
final class RequireOrgTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return $user->ownsAnyOrganization()
                ? redirect()->guest(route('2fa.setup'))
                : $next($request);
        }

        $session = $request->session();

        if ($session->get('2fa.passed_at') === null || (int) $session->get('2fa.user_id', 0) !== $user->id) {
            return redirect()->guest(route('2fa.challenge'));
        }

        return $next($request);
    }
}
