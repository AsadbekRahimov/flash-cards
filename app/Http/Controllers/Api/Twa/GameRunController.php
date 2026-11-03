<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Game\DTO\RunSnapshot;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Runs\BossService;
use App\Domain\Game\Services\Runs\DailyCaravanService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Http\Requests\Twa\GameAbandonRequest;
use App\Http\Requests\Twa\GameAnswerRequest;
use App\Http\Requests\Twa\GameHintRequest;
use App\Http\Requests\Twa\StartGameRunRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\GameAnswerResource;
use App\Http\Resources\Twa\GameRunResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The runs of the game "Caravan" (docs/18 §12.8, docs/04 A.18): start, read, answer, hint, abandon, the audio of an element. Thin: the
 * request is validated by a FormRequest, the work is RunService's, the body is a Resource. Somebody else's run is "not found".
 */
final class GameRunController
{
    public function __construct(
        private readonly RunService $runs,
        private readonly DailyCaravanService $daily,
        private readonly BossService $bosses,
        private readonly PracticeAudioService $audio,
    ) {}

    /** POST /api/twa/game/runs - 201 with the run (or the one a parallel tab has just started on the same stop); `kind = daily`: the day's caravan; `kind = boss`: the fight with the boss of a city. */
    public function store(StartGameRunRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $run = match (true) {
                $request->isDaily() => $this->daily->start($learner, $request->replaceActive()),
                $request->isBoss() => $this->bosses->start($learner, $request->course(), $request->stageId(), $request->replaceActive()),
                default => $this->runs->start($learner, $request->course(), $request->lessonId(), $request->replaceActive()),
            };

            return (new GameRunResource($this->runs->view($run, $learner)))->response()->setStatusCode(201);
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    /** GET /api/twa/game/runs/{run} - a finished run answers 200 with its summary. */
    public function show(Request $request, int $run): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $found = $this->runs->find($learner, $run);

            return (new GameRunResource($this->runs->view($found, $learner)))->response();
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/game/runs/{run}/answer */
    public function answer(GameAnswerRequest $request, int $run): JsonResponse
    {
        try {
            $outcome = $this->runs->answer(
                $run,
                $this->learner($request),
                $request->requestId(),
                $request->elementId(),
                $request->answer(),
                $request->timeSpentMs(),
            );

            return (new GameAnswerResource($outcome))->response();
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/game/runs/{run}/hint */
    public function hint(GameHintRequest $request, int $run): JsonResponse
    {
        try {
            $outcome = $this->runs->hint($run, $this->learner($request), $request->requestId(), $request->elementId());

            return (new GameAnswerResource($outcome))->response();
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/game/runs/{run}/abandon */
    public function abandon(GameAbandonRequest $request, int $run): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $closed = $this->runs->abandon($run, $learner);

            return (new GameRunResource($this->runs->view($closed, $learner)))->response();
        } catch (GameException $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /api/twa/game/runs/{run}/audio/{element}: the clip of an element of the snapshot by its opaque id, streamed with
     * `Cache-Control: private, no-store`. An element further than the current one, one without a clip and one that does not exist look
     * the same (404): the next words of the run are not handed out. The file that is not there is 503.
     */
    public function audio(Request $request, int $run, string $element): Response
    {
        $learner = $this->learner($request);

        try {
            $found = $this->runs->find($learner, $run);
        } catch (GameException $e) {
            return $this->error($e);
        }

        $snapshot = RunSnapshot::fromArray($found->snapshot);
        $index = $snapshot->indexOf($element);
        $heard = $index !== null && $index <= RunState::fromArray($found->state)->current ? $snapshot->at($index) : null;
        $clip = $heard === null ? null : $this->audio->clipOf($heard);
        if ($clip === null) {
            return ApiErrorResource::respond(404, GameException::RUN_NOT_FOUND, 'No such run or element.');
        }

        $stream = $this->audio->open($clip);
        if ($stream === null) {
            return ApiErrorResource::respond(503, PracticeException::AUDIO_UNAVAILABLE, 'The audio is not available.');
        }

        return new StreamedResponse(
            static function () use ($stream): void {
                fpassthru($stream->stream);
                fclose($stream->stream);
            },
            200,
            [
                'Content-Type' => $stream->mime,
                'Content-Length' => (string) $stream->size,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function error(GameException $e): JsonResponse
    {
        return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
