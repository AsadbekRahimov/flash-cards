<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null || ! $user->hasRole('admin')) {
            return $next($request);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.setup');
        }

        if ($request->session()->get('2fa.passed_at') === null) {
            return redirect()->route('2fa.challenge');
        }

        return $next($request);
    }
}
