<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Exceptions\ChallengeException;
use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\ChallengeService;
use App\Domain\Engagement\Services\FriendshipService;
use App\Http\Requests\Twa\ChallengeActionRequest;
use App\Http\Requests\Twa\ProposeChallengeRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ChallengeResource;
use App\Http\Resources\Twa\ChallengesResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Challenges between friends (docs/04 A.13, docs/13 §7.5): the lists, propose (to a friend, addressed by the id
 * of the friendship), accept and decline. Someone else's challenge or friendship id is "not found".
 */
final class ChallengeController
{
    public function __construct(
        private readonly ChallengeService $challenges,
        private readonly FriendshipService $friendships,
    ) {}

    /** GET /api/twa/challenges */
    public function index(Request $request): JsonResponse
    {
        return (new ChallengesResource($this->challenges->overview($this->learner($request))))->response();
    }

    /** POST /api/twa/challenges — body `{friendship_id, metric}`; 201 with the new (pending) challenge. */
    public function store(ProposeChallengeRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $friendship = $this->friendships->find($learner, $request->friendshipId());
            $friend = Learner::query()->find($friendship->otherId($learner->id));
            if (! $friend instanceof Learner) {
                throw FriendshipException::notFound(); // the friend deleted their profile
            }

            $challenge = $this->challenges->propose($learner, $friend, $request->metric());
        } catch (FriendshipException|ChallengeException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new ChallengeResource($this->challenges->entry($learner, $challenge)))->response()->setStatusCode(201);
    }

    /** POST /api/twa/challenges/{challenge}/accept */
    public function accept(ChallengeActionRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $challenge = $this->challenges->accept($learner, $request->challengeId());
        } catch (ChallengeException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new ChallengeResource($this->challenges->entry($learner, $challenge)))->response();
    }

    /** POST /api/twa/challenges/{challenge}/decline */
    public function decline(ChallengeActionRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $challenge = $this->challenges->decline($learner, $request->challengeId());
        } catch (ChallengeException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new ChallengeResource($this->challenges->entry($learner, $challenge)))->response();
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
