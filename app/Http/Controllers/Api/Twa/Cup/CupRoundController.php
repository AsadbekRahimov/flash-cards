<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa\Cup;

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Practice\DTO\AudioClip;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Http\Requests\Twa\Cup\CupAnswerRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\AudioLocationResource;
use App\Http\Resources\Twa\Cup\CupRoundAnswerResource;
use App\Http\Resources\Twa\Cup\CupRoundResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The attempt of a round of a cup (docs/33 §5.2-§5.3, docs/04 A.23, K7): resume, answer, the clip of a `listen` task. The attempt is
 * the learner's own or 404 `cup_round_not_found` - a stranger's and a missing one look the same (the core reads it by id AND learner).
 */
final class CupRoundController
{
    public function __construct(
        private readonly CupRoundService $rounds,
        private readonly PracticeAudioService $audio,
    ) {}

    /** GET /api/twa/cup-rounds/{attempt} */
    public function show(Request $request, int $attempt): JsonResponse
    {
        try {
            return (new CupRoundResource($this->rounds->find($this->learner($request), $attempt)))->response();
        } catch (CupException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/cup-rounds/{attempt}/answer - 200; a repeated `request_id` answers the stored body again. */
    public function answer(CupAnswerRequest $request, int $attempt): JsonResponse
    {
        try {
            $outcome = $this->rounds->answer($this->learner($request), $attempt, $request->requestId(), $request->elementId(), $request->answer());

            return (new CupRoundAnswerResource($outcome))->response();
        } catch (CupException $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /api/twa/cup-rounds/{attempt}/audio/{element}: the clip of a `listen` task of the learner's own attempt, by the opaque id of
     * the element - only the current task or one already answered (the next words of the attempt are not handed out early). Anything
     * else is 404, the same as a stranger's attempt. A word on R2 answers its public address in JSON; a local file is streamed.
     */
    public function audio(Request $request, int $attempt, string $element): Response
    {
        $learner = $this->learner($request);

        try {
            $view = $this->rounds->find($learner, $attempt);
        } catch (CupException $e) {
            return $this->error($e);
        }

        $reached = ($view->current['id'] ?? null) === $element || in_array($element, array_column($view->answers, 'element_id'), true);
        $clip = $reached ? $this->rounds->audioOf($learner, $attempt, $element) : null;
        if ($clip === null || ! in_array($clip['disk'], [$this->audio->wordDiskName(), $this->audio->diskName()], true)) {
            return $this->error(CupException::roundNotFound());
        }

        $location = new AudioClip($clip['disk'], $clip['path']);
        $url = $this->audio->publicUrl($location);
        if ($url !== null) {
            return AudioLocationResource::respond($url);
        }

        $stream = $this->audio->open($location);
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

    private function error(CupException $e): JsonResponse
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
