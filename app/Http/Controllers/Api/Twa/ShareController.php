<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Engagement\Services\ShareAnalytics;
use App\Domain\Engagement\Services\SharePreparer;
use App\Domain\Engagement\Services\StoryShareService;
use App\Http\Requests\Twa\ShareEventRequest;
use App\Http\Requests\Twa\SharePrepareRequest;
use App\Http\Requests\Twa\StoryShareRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ShareMessageResource;
use App\Http\Resources\Twa\StoryShareResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * "Share" from the Mini App (docs/36 §1в, docs/04 A.22; docs/40 §2.4, docs/04 A.26): the prepared message through the bot, the
 * story card, and the client's events.
 */
final class ShareController
{
    public function __construct(
        private readonly SharePreparer $preparer,
        private readonly StoryShareService $stories,
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
            return $this->error($e);
        }
    }

    /** POST /api/twa/share/story (throttle `twa-share`) - `{media_url, text, widget_link}`; 403 / 404 → the client shares through the bot. */
    public function story(StoryShareRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            return (new StoryShareResource($this->stories->prepare($learner, $request->kind(), $request->subject())))->response();
        } catch (ShareException $e) {
            return $this->error($e);
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

    private function error(ShareException $e): JsonResponse
    {
        $response = ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details());

        return $e->retryAfter === null ? $response : $response->header('Retry-After', (string) $e->retryAfter);
    }
}
