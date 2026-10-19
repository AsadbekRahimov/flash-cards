<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Twa\Services\MePayloadBuilder;
use App\Http\Requests\Twa\CheckoutRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\BillingCatalogResource;
use App\Http\Resources\Twa\CheckoutResource;
use App\Http\Resources\Twa\MeResource;
use App\Http\Resources\Twa\OrderStatusResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Purchase and auto-renewal of the learner's own Pro (docs/13 §5, docs/04 A.8). */
final class BillingController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly LearnerBillingService $billing,
        private readonly MePayloadBuilder $payload,
    ) {}

    /** GET /api/twa/billing/catalog */
    public function catalog(Request $request): JsonResponse
    {
        return (new BillingCatalogResource($this->checkout->catalog($this->learner($request))))->response();
    }

    /** POST /api/twa/billing/checkout (throttle `twa-checkout`) */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $checkout = $this->billing->checkout($this->learner($request), $request->plan(), $request->period(), $request->provider(), CheckoutService::VIA_TWA);
        } catch (BillingException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new CheckoutResource($checkout))->response()->setStatusCode(201);
    }

    /** GET /api/twa/billing/orders/{token} */
    public function order(Request $request, string $token): JsonResponse
    {
        $order = $this->billing->orderFor($this->learner($request), $token);

        if ($order === null) {
            return ApiErrorResource::respond(404, 'order_not_found', 'Order not found.');
        }

        return (new OrderStatusResource($order))->response();
    }

    /** POST /api/twa/billing/subscription/cancel — answers with the refreshed profile. */
    public function cancel(Request $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->billing->cancelAutoRenew($learner, $request->ip());
        } catch (BillingException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new MeResource($this->payload->build($learner)))->response();
    }

    /** POST /api/twa/billing/subscription/resume */
    public function resume(Request $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->billing->resumeAutoRenew($learner, $request->ip());
        } catch (BillingException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new MeResource($this->payload->build($learner)))->response();
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
