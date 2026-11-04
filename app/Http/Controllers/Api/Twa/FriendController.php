<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\DTO\FriendEntry;
use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Game\Services\FriendCosmetics;
use App\Http\Requests\Twa\FriendshipActionRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\FriendsResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Friends (docs/04 A.13, docs/13 §7.5): the list with open requests, and accept / decline / remove. A friend
 * request is created by opening the inviter's `f_<code>` link in the bot, not here. Every action answers with
 * the refreshed list; someone else's friendship id is "not found".
 */
final class FriendController
{
    public function __construct(
        private readonly FriendshipService $friendships,
        private readonly FriendCosmetics $cosmetics,
    ) {}

    /** GET /api/twa/friends */
    public function index(Request $request): JsonResponse
    {
        return $this->overview($this->learner($request));
    }

    /** POST /api/twa/friends/{friendship}/accept */
    public function accept(FriendshipActionRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->friendships->accept($learner, $request->friendshipId());
        } catch (FriendshipException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return $this->overview($learner);
    }

    /** POST /api/twa/friends/{friendship}/decline */
    public function decline(FriendshipActionRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->friendships->decline($learner, $request->friendshipId());
        } catch (FriendshipException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return $this->overview($learner);
    }

    /** POST /api/twa/friends/{friendship}/remove — unfriend / withdraw a request; open challenges of the pair are canceled. */
    public function remove(FriendshipActionRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->friendships->remove($learner, $request->friendshipId());
        } catch (FriendshipException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return $this->overview($learner);
    }

    private function overview(Learner $learner): JsonResponse
    {
        $overview = $this->friendships->overview($learner);
        // What the friends wear (G2.6): one statement while the game is open to this learner, none while it is closed.
        $camels = $this->cosmetics->camelsForViewer($learner, array_map(static fn (FriendEntry $entry): int => $entry->learnerId, $overview->friends));

        return (new FriendsResource($overview, $camels))->response();
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
