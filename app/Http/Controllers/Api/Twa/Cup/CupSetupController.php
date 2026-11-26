<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa\Cup;

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupMiniApp;
use App\Http\Requests\Twa\Cup\CupPublishRequest;
use App\Http\Requests\Twa\Cup\CupSettingsRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\Cup\CupSetupResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administrator of the chat in the Mini App (docs/33 §3.3, §6.5, docs/04 A.23, K7): the setup and the publication of a draft by
 * the code of its link, the settings and the cancel by the id. Telegram is asked by the core every time; the Telegram id is the JWT
 * learner's, never the client's.
 */
final class CupSetupController
{
    public function __construct(private readonly CupMiniApp $cups) {}

    /** GET /api/twa/cups/setup/{code} */
    public function show(Request $request, string $code): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupSetupResource($this->cups->setup($code, $this->learner($request))->cup))->response());
    }

    /** POST /api/twa/cups/setup/{code}/publish - a second press answers the published cup as it is. */
    public function publish(CupPublishRequest $request, string $code): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupSetupResource($this->cups->publish($code, $this->learner($request), $request->level(), $request->locale())))->response());
    }

    /** PATCH /api/twa/cups/{id}/settings */
    public function update(CupSettingsRequest $request, int $id): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupSetupResource($this->cups->settings($id, $this->learner($request), $request->settings())))->response());
    }

    /** POST /api/twa/cups/{id}/cancel - the cup after it; a cup already over is answered as it is. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => (new CupSetupResource($this->cups->cancel($id, $this->learner($request))))->response());
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
