<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Cup\Exceptions\CupException;
use App\Http\Resources\ApiErrorResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The switch of the cup on the routes of the Mini App (docs/33 §9.1, K7): with `CUP_ACCESS=off` every route of `/cups/*` and
 * `/cup-rounds/*` answers the same 403 `cup_unavailable` before anything is read - whether the cup, the attempt or the code exists or
 * not. With `pilot` the routes are open and the core refuses the cups of the groups out of the pilot (after the door of membership, so
 * a stranger still learns nothing).
 */
final class EnsureCupAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((string) config('cup.access', 'off') === 'off') {
            $e = CupException::unavailable();

            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }

        return $next($request);
    }
}
