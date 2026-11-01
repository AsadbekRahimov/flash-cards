<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Practice\DTO\AudioStream;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleReader;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Http\Resources\ApiErrorResource;
use App\Models\Battle;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audio of an element of a session or of a battle (docs/16 §7.2, §7.4, §10.2): the file is read from the disk the element of
 * the snapshot names, by the opaque id of the element, and streamed with `Cache-Control: private, no-store`. Only the owner of a
 * session / a player of a battle gets it; an id of somebody else's session or battle and an id of an element without audio look the
 * same (404); a file that is not there is 503.
 *
 * The audio of a finished session stays available until its `expires_at` (to listen again in the review). In a battle the clip of
 * a round that has not begun is not handed out - 404 like an element that does not exist - except in the room and the countdown of
 * a dictation, where all five are loaded before "Ready" (the text does not follow from the sound, §10.3).
 */
final class PracticeAudioController
{
    public function __construct(
        private readonly PracticeSessionService $sessions,
        private readonly PracticeAudioService $audio,
        private readonly BattleReader $battles,
    ) {}

    /** GET /api/twa/practice/sessions/{session}/audio/{element} */
    public function session(Request $request, int $session, string $element): Response
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $now = CarbonImmutable::now();

        try {
            $found = $this->sessions->find($learner, $session, $now);
        } catch (PracticeException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }

        $snapshot = Snapshot::fromArray($found->snapshot);
        $clip = $found->expires_at->gt($now) ? $this->audio->clipFor($snapshot, $element) : null;
        if ($clip === null) {
            return $this->notFound();
        }

        $stream = $this->audio->open($clip);
        if ($stream === null) {
            $this->audio->recordMissing($learner, $found->id, $snapshot, $element);

            return ApiErrorResource::respond(503, PracticeException::AUDIO_UNAVAILABLE, 'The audio is not available.');
        }

        return $this->stream($stream);
    }

    /**
     * GET /api/twa/practice/battles/{battle}/audio/{element}. A file that is not there is only told (503): the client reports it
     * with `audio-failed`, which looks at the file itself and voids the match (§10.3) - one report, not two.
     */
    public function battle(Request $request, int $battle, string $element): Response
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $found = $this->battles->find($learner, $battle);
        } catch (BattleException|PracticeException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }

        $snapshot = Snapshot::fromArray($found->snapshot);
        $clip = $this->heard($found, $snapshot, $element) ? $this->audio->clipFor($snapshot, $element) : null;
        if ($clip === null) {
            return ApiErrorResource::respond(404, BattleException::NOT_FOUND, 'No such battle.');
        }

        $stream = $this->audio->open($clip);
        if ($stream === null) {
            return ApiErrorResource::respond(503, PracticeException::AUDIO_UNAVAILABLE, 'The audio is not available.');
        }

        return $this->stream($stream);
    }

    /**
     * The clip of this element may be handed out now: the round of the element has begun (`current_round` - a match that is over
     * keeps its last round), or the battle is in the room / the countdown, where the five clips are preloaded.
     */
    private function heard(Battle $battle, Snapshot $snapshot, string $elementId): bool
    {
        if ($battle->status === BattleStatus::Room || $battle->status === BattleStatus::Countdown) {
            return true;
        }

        $index = $snapshot->indexOf($elementId);

        return $index !== null && $index + 1 <= $battle->current_round;
    }

    private function stream(AudioStream $stream): StreamedResponse
    {
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

    private function notFound(): JsonResponse
    {
        return ApiErrorResource::respond(404, PracticeException::SESSION_NOT_FOUND, 'No such practice session.');
    }
}
