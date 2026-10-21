<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Providers\Payme\PaymeAuth;
use App\Domain\Billing\Providers\Payme\PaymeRpcException;
use App\Domain\Billing\Providers\Payme\PaymeRpcService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /billing/webhook/payme` — the one JSON-RPC endpoint Payme calls (docs/13 §4.2, docs/04 D.1).
 *
 * Order of checks: body size -> Basic auth (-32504) -> JSON (-32700) -> PaymeRpcService. Every
 * protocol-level answer is HTTP 200 with a JSON-RPC body (Payme reads the body, not the status);
 * only an unexpected exception is HTTP 500 (-32400) so that Payme retries the call.
 */
final class PaymeWebhookController extends Controller
{
    /** Bodies above this are refused before parsing (docs/13 §4). */
    public const MAX_BODY_BYTES = 65_536;

    public function __invoke(Request $request, PaymeAuth $auth, PaymeRpcService $rpc): JsonResponse
    {
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error(null, PaymeRpcException::invalidRequest('body too large'));
        }

        $decoded = json_decode($body, true);
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;
        $id = is_int($id) || is_string($id) ? $id : null;

        $mode = $auth->verify($request->header('Authorization'));
        if ($mode === null) {
            Log::warning('billing.payme.auth_failed', ['ip' => $request->ip(), 'has_header' => $request->hasHeader('Authorization')]);

            return $this->error($id, PaymeRpcException::insufficientPrivileges());
        }

        if (! is_array($decoded)) {
            return $this->error($id, PaymeRpcException::parseError());
        }

        try {
            return response()->json($rpc->handle($decoded, mode: $mode));
        } catch (Throwable $e) {
            report($e);
            Log::error('billing.payme.rpc_failed', ['method' => is_string($decoded['method'] ?? null) ? $decoded['method'] : null, 'exception' => $e::class, 'mode' => $mode]);

            return $this->error($id, PaymeRpcException::systemError(), 500);
        }
    }

    private function error(int|string|null $id, PaymeRpcException $e, int $status = 200): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $e->toArray()], $status);
    }
}
