<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleReader;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Http\Requests\Twa\BattleAnswerRequest;
use App\Http\Requests\Twa\BattleAudioFailedRequest;
use App\Http\Requests\Twa\BattleInviteRequest;
use App\Http\Requests\Twa\BattleOptionsRequest;
use App\Http\Requests\Twa\BattleRoundRequest;
use App\Http\Requests\Twa\BattleSurrenderRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\BattleAnswerResource;
use App\Http\Resources\Twa\BattleAudioFailedResource;
use App\Http\Resources\Twa\BattleListResource;
use App\Http\Resources\Twa\BattleOptionsResource;
use App\Http\Resources\Twa\BattlePollResource;
use App\Http\Resources\Twa\BattleResource;
use App\Http\Resources\Twa\BattleRoundResource;
use App\Models\Battle;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The 1-on-1 battle (docs/16 §7.3, §7.4): the options and the list, the invitation, the room and the match. Thin: the request is
 * validated by a FormRequest, the work is {@see BattleService}'s (the actions) and {@see BattleReader}'s (the reads), the body is a
 * Resource. The battle of somebody else is "not found" (the reader decides who may look).
 *
 * The poll, the heartbeat of the player, and the audio are on routes OUTSIDE `twa-api` (their own limiters, docs/16 §7.5). Every
 * refusal of the domain - the battle's, the practice's, the friendship's - is the one envelope of {@see ApiErrorResource}.
 */
final class BattleController
{
    public function __construct(
        private readonly BattleService $battles,
        private readonly BattleReader $reader,
    ) {}

    /** GET /api/twa/practice/battles/options */
    public function options(BattleOptionsRequest $request): JsonResponse
    {
        try {
            $options = $this->reader->options($this->learner($request), $request->friendshipId(), $request->course(), $request->lessonId());
        } catch (BattleException|PracticeException|FriendshipException $e) {
            return $this->error($e);
        }

        return (new BattleOptionsResource($options))->response();
    }

    /** GET /api/twa/practice/battles */
    public function index(Request $request): JsonResponse
    {
        try {
            $list = $this->reader->list($this->learner($request), CarbonImmutable::now());
        } catch (PracticeException $e) {
            return $this->error($e);
        }

        return (new BattleListResource($list))->response();
    }

    /** POST /api/twa/practice/battles — 201 with the new battle (an invitation, the friend is told in a DM). */
    public function store(BattleInviteRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $battle = $this->battles->invite(
                $learner,
                $request->friendshipId(),
                $request->mode(),
                $request->course(),
                $request->lessonId(),
                $request->rematchOf(),
            );

            return (new BattleResource($this->reader->view($battle, $learner, CarbonImmutable::now())))->response()->setStatusCode(201);
        } catch (BattleException|PracticeException|FriendshipException $e) {
            return $this->error($e);
        }
    }

    /** GET /api/twa/practice/battles/{battle} */
    public function show(Request $request, int $battle): JsonResponse
    {
        return $this->respondWithView($request, fn (Learner $learner): Battle => $this->reader->find($learner, $battle));
    }

    /** GET /api/twa/practice/battles/{battle}/poll — the heartbeat and the small answer (≤ 600 bytes); outside `twa-api`. */
    public function poll(Request $request, int $battle): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $poll = $this->reader->poll($this->reader->find($learner, $battle), $learner, CarbonImmutable::now());
        } catch (BattleException|PracticeException $e) {
            return $this->error($e);
        }

        return (new BattlePollResource($poll))->response();
    }

    /** GET /api/twa/practice/battles/{battle}/round?no= */
    public function round(BattleRoundRequest $request, int $battle): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $round = $this->reader->round($this->reader->find($learner, $battle), $learner, $request->no(), CarbonImmutable::now());
        } catch (BattleException|PracticeException $e) {
            return $this->error($e);
        }

        return (new BattleRoundResource($round))->response();
    }

    /** POST /api/twa/practice/battles/{battle}/accept */
    public function accept(Request $request, int $battle): JsonResponse
    {
        return $this->respondWithView($request, fn (Learner $learner): Battle => $this->battles->accept($learner, $battle));
    }

    /** POST /api/twa/practice/battles/{battle}/decline */
    public function decline(Request $request, int $battle): JsonResponse
    {
        return $this->respondWithView($request, fn (Learner $learner): Battle => $this->battles->decline($learner, $battle));
    }

    /** POST /api/twa/practice/battles/{battle}/cancel — withdraws an invitation, or leaves the room / the countdown. */
    public function cancel(Request $request, int $battle): JsonResponse
    {
        return $this->respondWithView($request, fn (Learner $learner): Battle => $this->battles->cancel($learner, $battle));
    }

    /** POST /api/twa/practice/battles/{battle}/ready */
    public function ready(Request $request, int $battle): JsonResponse
    {
        return $this->respondWithView($request, fn (Learner $learner): Battle => $this->battles->ready($learner, $battle));
    }

    /** POST /api/twa/practice/battles/{battle}/surrender — `{confirm: true}`. */
    public function surrender(BattleSurrenderRequest $request, int $battle): JsonResponse
    {
        return $this->respondWithView($request, fn (Learner $learner): Battle => $this->battles->surrender($learner, $battle));
    }

    /** POST /api/twa/practice/battles/{battle}/answer */
    public function answer(BattleAnswerRequest $request, int $battle): JsonResponse
    {
        try {
            $outcome = $this->battles->answer($this->learner($request), $battle, $request->requestId(), $request->round(), $request->answer());
        } catch (BattleException|PracticeException $e) {
            return $this->error($e);
        }

        return (new BattleAnswerResource($outcome))->response();
    }

    /** POST /api/twa/practice/battles/{battle}/audio-failed */
    public function audioFailed(BattleAudioFailedRequest $request, int $battle): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $outcome = $this->battles->audioFailed($learner, $battle, $request->requestId(), $request->elementId());
            $view = $this->reader->view($outcome->battle, $learner, CarbonImmutable::now());
        } catch (BattleException|PracticeException $e) {
            return $this->error($e);
        }

        return (new BattleAudioFailedResource(['verified' => $outcome->verified, 'battle' => $view]))->response();
    }

    /**
     * An action (or a find) that gives back a battle, answered with the view of the battle for the one who acts.
     *
     * @param  Closure(Learner): Battle  $act
     */
    private function respondWithView(Request $request, Closure $act): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $view = $this->reader->view($act($learner), $learner, CarbonImmutable::now());
        } catch (BattleException|PracticeException|FriendshipException $e) {
            return $this->error($e);
        }

        return (new BattleResource($view))->response();
    }

    private function error(BattleException|PracticeException|FriendshipException $e): JsonResponse
    {
        return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e instanceof FriendshipException ? [] : $e->details);
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
