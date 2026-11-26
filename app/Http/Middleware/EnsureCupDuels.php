<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupDuelService;
use App\Http\Resources\ApiErrorResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The switch of the duels on their routes (docs/04 A.23d, D2): with `CUP_DUELS` off - or the battles off, or the practice off - every
 * route of `/cups/{id}/duels…` answers the same 409 `cup_duel_disabled` before anything is read, whether the cup or the duel exists or
 * not. Behind {@see EnsureCupAccess}: the switch of the whole cup answers first.
 */
final class EnsureCupDuels
{
    public function __construct(private readonly CupDuelService $duels) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->duels->enabled()) {
            $e = CupException::duelDisabled();

            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }

        return $next($request);
    }
}
