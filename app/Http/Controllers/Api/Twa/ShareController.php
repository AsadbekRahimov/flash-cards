<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Engagement\Services\ShareAnalytics;
use App\Domain\Engagement\Services\SharePreparer;
use App\Http\Requests\Twa\ShareEventRequest;
use App\Http\Requests\Twa\SharePrepareRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ShareMessageResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** "Share" through the bot from the Mini App (docs/36 §1в, docs/04 A.22): the prepared message and the client's events. */
final class ShareController
{
    public function __construct(
        private readonly SharePreparer $preparer,
        private readonly ShareAnalytics $analytics,
    ) {}

    /** POST /api/twa/share/prepare (throttle `twa-share`) - `{id, expires_at}`; 403 / 404 / 503 → the client opens `t.me/share/url`. */
    public function prepare(SharePrepareRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            return (new ShareMessageResource($this->preparer->prepare($learner, $request->kind(), $request->subject())))->response();
        } catch (ShareException $e) {
            $response = ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details());

            return $e->retryAfter === null ? $response : $response->header('Retry-After', (string) $e->retryAfter);
        }
    }

    /** POST /api/twa/share/events (throttle `twa-share-event`) - 204; analytics only. */
    public function event(ShareEventRequest $request): Response
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $this->analytics->record($learner, $request->event(), $request->kind(), $request->reason());

        return response()->noContent();
    }
}
