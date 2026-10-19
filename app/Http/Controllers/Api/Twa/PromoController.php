<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\PromoCodeService;
use App\Domain\Twa\Services\MePayloadBuilder;
use App\Http\Requests\Twa\RedeemPromoRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\MeResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;

final class PromoController
{
    public function __construct(
        private readonly PromoCodeService $promos,
        private readonly MePayloadBuilder $payload,
    ) {}

    /** POST /api/twa/promo/redeem — answers with the refreshed profile (new entitlement included). */
    public function redeem(RedeemPromoRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $this->promos->redeem($learner, $request->string('code')->toString());
        } catch (BillingException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new MeResource($this->payload->build($learner)))->response();
    }
}
