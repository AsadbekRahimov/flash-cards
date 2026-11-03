<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Economy\WalletOverview;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\GameWalletResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/twa/game/wallet (docs/18 §12.8, docs/04 A.18): the coins, the tickets and the last lines of the ledger. */
final class GameWalletController
{
    public function __construct(private readonly WalletOverview $wallets) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            return (new GameWalletResource($this->wallets->wallet($learner)))->response();
        } catch (GameException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }
    }
}
