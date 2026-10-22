<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Administration\Services\TwoFactorSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** `/admin`: the mark must belong to the CURRENT `web` user (`2fa.web.*`), not to any account in the shared session. */
final class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if ($user === null || ! $user->hasRole('admin')) {
            return $next($request);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.setup', ['guard' => 'web']);
        }

        if (! TwoFactorSession::hasPassed($request->session(), 'web', $user->id)) {
            return redirect()->route('2fa.challenge', ['guard' => 'web']);
        }

        return $next($request);
    }
}
