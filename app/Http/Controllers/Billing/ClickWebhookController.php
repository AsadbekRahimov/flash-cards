<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Providers\Click\ClickException;
use App\Domain\Billing\Providers\Click\ClickMerchantService;
use App\Domain\Billing\Providers\Click\ClickRequest;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /billing/webhook/click/prepare` and `/complete` — the two form-encoded calls Click makes
 * (docs/13 §4.3, docs/04 D.2). Body size -> ClickMerchantService (signature, codes). Every
 * protocol-level answer is HTTP 200 with `{..., error, error_note}` (Click reads the body); only
 * an unexpected exception is HTTP 500 + `-7` so that Click retries the call.
 */
final class ClickWebhookController extends Controller
{
    /** Bodies above this are refused before parsing (docs/13 §4). */
    public const MAX_BODY_BYTES = 65_536;

    public function prepare(Request $request, ClickMerchantService $service): JsonResponse
    {
        return $this->handle($request, ClickRequest::ACTION_PREPARE, static fn (array $input): array => $service->prepare($input));
    }

    public function complete(Request $request, ClickMerchantService $service): JsonResponse
    {
        return $this->handle($request, ClickRequest::ACTION_COMPLETE, static fn (array $input): array => $service->complete($input));
    }

    /** @param Closure(array<array-key, mixed>): array<string, mixed> $call */
    private function handle(Request $request, int $action, Closure $call): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(ClickMerchantService::errorResponse([], $action, ClickException::requestError('body too large')));
        }

        // Click posts application/x-www-form-urlencoded; the query string is never read, and a JSON
        // body is refused explicitly (Laravel would otherwise serve it through post() as well).
        if ($request->isJson()) {
            return response()->json(ClickMerchantService::errorResponse([], $action, ClickException::requestError('body must be form-encoded')));
        }

        /** @var array<array-key, mixed> $input */
        $input = $request->post();

        try {
            return response()->json($call($input));
        } catch (Throwable $e) {
            report($e);
            Log::error('billing.click.webhook_failed', ['action' => $action, 'exception' => $e::class]);

            return response()->json(ClickMerchantService::errorResponse($input, $action, ClickException::updateFailed($e::class)), 500);
        }
    }
}
