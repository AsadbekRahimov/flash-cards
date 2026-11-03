<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Economy\ShopService;
use App\Domain\Game\Services\Economy\WalletOverview;
use App\Http\Requests\Twa\GameEquipRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\GameProfileResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The game profile (docs/18 §12.8, docs/04 A.18): what the learner wears, and putting a cosmetic on / taking it off. */
final class GameProfileController
{
    public function __construct(
        private readonly WalletOverview $wallets,
        private readonly ShopService $shop,
    ) {}

    /** GET /api/twa/game/profile */
    public function show(Request $request): JsonResponse
    {
        try {
            return (new GameProfileResource($this->wallets->profile($this->learner($request))))->response();
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/game/profile/equip - the profile after the change. */
    public function equip(GameEquipRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->shop->equip($learner, $request->slot(), $request->itemKey());

            return (new GameProfileResource($this->wallets->profile($learner)))->response();
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
