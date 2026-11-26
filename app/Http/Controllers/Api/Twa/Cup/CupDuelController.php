<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa\Cup;

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupDuelMiniApp;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Http\Requests\Twa\Cup\CupDuelAcceptRequest;
use App\Http\Requests\Twa\Cup\CupDuelChallengeRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\Cup\CupDuelListResource;
use App\Http\Resources\Twa\Cup\CupDuelResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The duels of a cup in the Mini App (docs/04 A.23d, docs/33 §11.9.4; D2): the list, one duel, the challenge, the acceptance, the
 * decline and the withdrawal. Thin: the body is validated by a FormRequest, the door and the rules are {@see CupDuelMiniApp} and the
 * core, the body is a Resource, a refusal is the envelope of {@see CupException} / {@see BattleException} / {@see PracticeException}.
 */
final class CupDuelController
{
    public function __construct(private readonly CupDuelMiniApp $duels) {}

    /** GET /api/twa/cups/{id}/duels */
    public function index(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupDuelListResource($this->duels->board($id, $this->learner($request))))->response());
    }

    /** GET /api/twa/cups/{id}/duels/{duel} */
    public function show(Request $request, int $id, int $duel): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupDuelResource($this->duels->show($id, $duel, $this->learner($request))))->response());
    }

    /** POST /api/twa/cups/{id}/duels - 201 made now, 200 the same `request_id` again. */
    public function store(CupDuelChallengeRequest $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id): JsonResponse {
            [$view, $created] = $this->duels->challenge(
                $id,
                $this->learner($request),
                $request->opponentParticipantId(),
                $request->isPublic(),
                $request->mode(),
                $request->requestId(),
            );

            return (new CupDuelResource($view))->response()->setStatusCode($created ? 201 : 200);
        });
    }

    /** POST /api/twa/cups/{id}/duels/{duel}/accept - 201 accepted now (the match is open: `battle_id`), 200 accepted before. */
    public function accept(CupDuelAcceptRequest $request, int $id, int $duel): JsonResponse
    {
        return $this->guard(function () use ($request, $id, $duel): JsonResponse {
            [$view, $created] = $this->duels->accept($id, $duel, $this->learner($request), $request->expectRated());

            return (new CupDuelResource($view))->response()->setStatusCode($created ? 201 : 200);
        });
    }

    /** POST /api/twa/cups/{id}/duels/{duel}/decline */
    public function decline(Request $request, int $id, int $duel): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupDuelResource($this->duels->decline($id, $duel, $this->learner($request))))->response());
    }

    /** POST /api/twa/cups/{id}/duels/{duel}/cancel */
    public function cancel(Request $request, int $id, int $duel): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupDuelResource($this->duels->cancel($id, $duel, $this->learner($request))))->response());
    }

    /** @param \Closure(): JsonResponse $action */
    private function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (CupException|BattleException|PracticeException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
