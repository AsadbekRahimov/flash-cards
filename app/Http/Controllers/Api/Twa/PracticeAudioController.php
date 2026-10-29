<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Http\Resources\ApiErrorResource;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audio of an element of a session (docs/16 §7.2, §10.2): the file is read from the disk the element of the snapshot names,
 * by the opaque id of the element, and streamed with `Cache-Control: private, no-store`. Only the owner gets it; an id of
 * somebody else's session and an id of an element without audio look the same (404); a file that is not there is 503.
 * The audio of a finished session stays available until its `expires_at` (to listen again in the review).
 */
final class PracticeAudioController
{
    public function __construct(
        private readonly PracticeSessionService $sessions,
        private readonly PracticeAudioService $audio,
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
