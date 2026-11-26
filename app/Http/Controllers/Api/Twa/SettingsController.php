<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Exceptions\DailyGoalLimitException;
use App\Domain\Learners\Exceptions\ProEmojiProRequiredException;
use App\Domain\Learners\Exceptions\RegionChangeTooSoonException;
use App\Domain\Learners\Exceptions\RegionRevertUnavailableException;
use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Learners\Exceptions\TimezoneRevertUnavailableException;
use App\Domain\Learners\Services\LearnerDeletionService;
use App\Domain\Learners\Services\LearnerRegionService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Twa\Services\MePayloadBuilder;
use App\Http\Requests\Twa\DeleteAccountRequest;
use App\Http\Requests\Twa\UpdateSettingsRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\AccountDeletedResource;
use App\Http\Resources\Twa\MeResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SettingsController
{
    public function __construct(
        private readonly LearnerSettingsService $settings,
        private readonly MePayloadBuilder $payload,
        private readonly LearnerDeletionService $deletion,
        private readonly LearnerRegionService $regions,
    ) {}

    /**
     * PATCH /api/twa/me/settings — answers with the refreshed profile, like `GET /me`;
     * 422 `timezone_change_too_soon` when the zone was changed less than 24 h ago (nothing is applied); 403 `daily_goal_pro_required`
     * `{max}` for a daily goal above the limit of the plan (docs/34 §2б.3; nothing is applied, the Mini App opens the paywall);
     * 422 `region_change_too_soon` `{available_at}` for a region chosen less than 30 days after the last one (docs/35 §2.3);
     * 403 `pro_emoji_pro_required` for an emoji of Pro chosen without Pro (docs/31 §5а).
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $this->settings->update($learner, $request->settings(), $request->regionVia());
        } catch (TimezoneChangeTooSoonException $e) {
            return ApiErrorResource::respond(422, $e->errorCode, $e->getMessage());
        } catch (RegionChangeTooSoonException $e) {
            return ApiErrorResource::respond(422, $e->errorCode, $e->getMessage(), $e->details);
        } catch (DailyGoalLimitException|ProEmojiProRequiredException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e instanceof DailyGoalLimitException ? $e->details : []);
        }

        return (new MeResource($this->payload->build($learner)))->response();
    }

    /**
     * POST /api/twa/me/settings/timezone/revert — takes back the last change of the time zone within 10 minutes
     * (docs/13 §9, 2R.7); answers with the refreshed profile, or 422 `timezone_revert_unavailable`.
     */
    public function revertTimezone(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $this->settings->revertTimezone($learner);
        } catch (TimezoneRevertUnavailableException $e) {
            return ApiErrorResource::respond(422, $e->errorCode, $e->getMessage());
        }

        return (new MeResource($this->payload->build($learner)))->response();
    }

    /**
     * POST /api/twa/me/settings/region/revert — takes back the last change of the region within 10 minutes (docs/35 §2.3); answers
     * with the refreshed profile, or 422 `region_revert_unavailable`.
     */
    public function revertRegion(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $this->regions->revert($learner);
        } catch (RegionRevertUnavailableException $e) {
            return ApiErrorResource::respond(422, $e->errorCode, $e->getMessage());
        }

        return (new MeResource($this->payload->build($learner)))->response();
    }

    /** POST /api/twa/me/region-prompt/dismiss — "Not now" on the region card of the Rating tab: hidden for 30 days; idempotent, 204. */
    public function dismissRegionPrompt(Request $request): Response
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $this->regions->dismissPrompt($learner);

        return response()->noContent();
    }

    /**
     * POST /api/twa/me/delete `{"confirm": true}` — "Delete account" (owner's decision of 03.11.2026): soft delete; the
     * token stops working at once and `/start` within `learners.purge.after_days` days brings the account back.
     * 422 without the confirmation. A repeated call never gets here: the token is already refused (401).
     */
    public function delete(DeleteAccountRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $this->deletion->delete($learner, null, $request->ip());

        return (new AccountDeletedResource($this->deletion->restorableUntil($learner)))->response();
    }
}
