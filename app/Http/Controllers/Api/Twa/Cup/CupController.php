<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa\Cup;

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupMiniApp;
use App\Http\Requests\Twa\Cup\CupJoinRequest;
use App\Http\Requests\Twa\Cup\CupRenameRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\Cup\CupInviteResource;
use App\Http\Resources\Twa\Cup\CupListResource;
use App\Http\Resources\Twa\Cup\CupParticipantResource;
use App\Http\Resources\Twa\Cup\CupResource;
use App\Http\Resources\Twa\Cup\CupRoundResource;
use App\Http\Resources\Twa\Cup\CupTodayResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cup of a group in the Mini App (docs/33 §7.2, docs/04 A.23, K7): the invitation, my cups, the table, joining, the name, the round
 * of the day. Thin: the body is validated by a FormRequest, who may see and do what is {@see CupMiniApp} and the core, the body is a
 * Resource, a refusal is the envelope of {@see CupException}.
 */
final class CupController
{
    public function __construct(private readonly CupMiniApp $cups) {}

    /** GET /api/twa/cups/by-code/{code} */
    public function invite(Request $request, string $code): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupInviteResource($this->cups->invite($code, $this->learner($request))))->response());
    }

    /** GET /api/twa/cups */
    public function index(Request $request): JsonResponse
    {
        return (new CupListResource($this->cups->mine($this->learner($request))))->response();
    }

    /** GET /api/twa/cups/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupResource($this->cups->board($id, $this->learner($request))))->response());
    }

    /** POST /api/twa/cups/{id}/join - 201 joined now, 200 already in (a returning participant is active again). */
    public function join(CupJoinRequest $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id): JsonResponse {
            $outcome = $this->cups->join($id, $this->learner($request), $request->displayName());

            return (new CupParticipantResource($outcome->participant))->response()->setStatusCode($outcome->created ? 201 : 200);
        });
    }

    /** PATCH /api/twa/cups/{id}/me */
    public function rename(CupRenameRequest $request, int $id): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupParticipantResource($this->cups->rename($id, $this->learner($request), $request->displayName())))->response());
    }

    /** GET /api/twa/cups/{id}/rounds/today */
    public function today(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupTodayResource($this->cups->today($id, $this->learner($request))))->response());
    }

    /** POST /api/twa/cups/{id}/rounds/today - 201 started now, 200 the attempt already open (a second tab, a double tap). */
    public function start(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id): JsonResponse {
            $start = $this->cups->startRound($id, $this->learner($request));

            return (new CupRoundResource($start->view))->response()->setStatusCode($start->created ? 201 : 200);
        });
    }

    /** @param \Closure(): JsonResponse $action */
    private function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (CupException $e) {
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
