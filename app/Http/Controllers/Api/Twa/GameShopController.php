<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Economy\ShopService;
use App\Domain\Game\Services\Economy\WalletOverview;
use App\Http\Requests\Twa\GameBuyRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\GameShopResource;
use App\Http\Resources\Twa\GameWalletResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The shop of the game (docs/18 §12.8, docs/04 A.18): the catalogue with the learner's state, and the purchase. Thin: the work is ShopService's. */
final class GameShopController
{
    public function __construct(
        private readonly ShopService $shop,
        private readonly WalletOverview $wallets,
    ) {}

    /** GET /api/twa/game/shop */
    public function index(Request $request): JsonResponse
    {
        try {
            return (new GameShopResource($this->shop->shop($this->learner($request))))->response();
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/game/shop/{key}/buy - the wallet after the purchase (a repeat of the request answers the same, nothing is paid twice). */
    public function buy(GameBuyRequest $request, string $key): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->shop->buy($learner, $key, $request->requestId());

            return (new GameWalletResource($this->wallets->wallet($learner)))->response();
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner */
        return $request->attributes->get('learner');
    }

    private function error(GameException $e): JsonResponse
    {
        return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
    }
}
