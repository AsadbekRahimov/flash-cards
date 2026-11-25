<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\Services\TodayService;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Exceptions\HardRoundException;
use App\Domain\Learning\Exceptions\LessonReviewException;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\HardRoundService;
use App\Domain\Learning\Services\LessonReviewService;
use App\Domain\Learning\Services\PracticeEntryResolver;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Http\Requests\Twa\OpenTrainingSessionRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ReviewResultResource;
use App\Http\Resources\Twa\SessionSummaryResource;
use App\Http\Resources\Twa\TrainingNextResource;
use App\Http\Resources\Twa\TrainingStartResource;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Policies\TrainingSessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TrainingController
{
    public function __construct(
        private readonly TrainingFlowService $flow,
        private readonly TrainingSessionService $sessions,
        private readonly TrainingSessionPolicy $policy,
        private readonly ProductEventRecorder $events,
        private readonly TodayService $today,
        private readonly PracticeEntryResolver $practiceEntries,
        private readonly CourseCatalogService $catalog,
        private readonly HardRoundService $hardRound,
        private readonly LessonReviewService $lessonReview,
    ) {}

    /**
     * POST /api/twa/training/sessions — opens (or returns the already open) personal session
     * and answers like `/start`, so the client needs one request to show the first card.
     */
    public function open(OpenTrainingSessionRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        // The round of hard words spans the learner's words: it takes no lesson (whether the lesson exists is not asked).
        if ($request->string('mode')->toString() === TrainingSession::MODE_HARD_REVIEW && $request->lessonId() !== null) {
            $e = TrainingSessionException::lessonNotAllowed();

            return ApiErrorResource::respond(422, $e->reason, $e->getMessage());
        }
        // The review of a lesson (docs/06 §15) is always of one lesson.
        if ($request->string('mode')->toString() === TrainingSession::MODE_LESSON_REVIEW && $request->lessonId() === null) {
            $e = TrainingSessionException::lessonRequired();

            return ApiErrorResource::respond(422, $e->reason, $e->getMessage());
        }

        // The lesson with its stage and course in one read: the availability check of openPersonal() and the
        // start payload both need them, and neither reads them again.
        $lesson = null;
        if ($request->lessonId() !== null) {
            $lesson = $this->catalog->lessonWithContext((int) $request->lessonId());
            if ($lesson === null) {
                return $this->lessonNotFound();
            }
        }

        $mode = $request->string('mode')->toString();
        $source = $request->source();

        try {
            $session = match (true) {
                $mode === TrainingSession::MODE_HARD_REVIEW => $this->hardRound->open($learner, $source),
                $mode === TrainingSession::MODE_LESSON_REVIEW && $lesson !== null => $this->lessonReview->open($learner, $lesson, $source),
                default => $this->sessions->openPersonal($learner, $mode, $lesson, $source),
            };
        } catch (HardRoundException $e) {
            return ApiErrorResource::respond(409, HardRoundException::CODE, $e->getMessage(), $e->details());
        } catch (LessonReviewException $e) {
            return ApiErrorResource::respond(409, LessonReviewException::CODE, $e->getMessage(), $e->details());
        } catch (TrainingSessionException $e) {
            return match ($e->reason) {
                TrainingSessionException::REASON_LESSON_NOT_AVAILABLE => $this->lessonNotFound(),
                TrainingSessionException::REASON_INVALID_MODE,
                TrainingSessionException::REASON_LESSON_MISMATCH => ApiErrorResource::respond(422, $e->reason, $e->getMessage()),
                default => throw $e,
            };
        }

        $this->events->record(
            ProductEventName::TrainingSessionOpened,
            [
                'training_session_id' => $session->id,
                'mode' => $mode,
                'source' => $source,
                'reused' => ! $session->wasRecentlyCreated,
            ],
            learner: $learner,
        );

        $start = $this->flow->start($session, $learner, $lesson);
        if ($start === null) {
            return $this->lessonNotFound();
        }

        return (new TrainingStartResource($start))->response();
    }

    /**
     * POST /api/twa/training/sessions/{id}/finish — idempotent: a repeat answers 200 with the
     * same numbers. Unlike the other session routes a closed session is not 410 here.
     */
    public function finish(Request $request, int $sessionId): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        /** @var TrainingSession|null $session */
        $session = TrainingSession::query()->find($sessionId);
        if ($session === null) {
            return ApiErrorResource::respond(404, 'session_not_found', 'Training session not found.');
        }

        $denial = $this->policy->denial($learner, $session);
        if ($denial !== null) {
            return ApiErrorResource::respond(403, $denial, 'You cannot access this session.');
        }

        // Group sessions are closed by the teacher / scheduler, never by a learner.
        if (! $this->policy->canFinish($learner, $session)) {
            return ApiErrorResource::respond(403, 'forbidden', 'You cannot finish this session.');
        }

        try {
            // The event `training.session_finished` and the referral evaluation are the service's (after its commit, once):
            // the re-open of a round played to the end without `/finish` closes it the same way (docs/06 §13.2).
            // The round of hard words may tell one mistake of an earlier session corrected in it (`corrected`, docs/06 §14).
            $summary = $this->sessions->finish($session, learner: $learner, withCorrected: true);
        } catch (TrainingSessionException $e) {
            return ApiErrorResource::respond(403, $e->reason, $e->getMessage());
        }

        return (new SessionSummaryResource([
            'summary' => $summary,
            'progress' => $this->today->progress($learner),
            'practice_entry' => $this->practiceEntries->forSession($summary->session, $learner),
            // The invitation to the round of hard words after a daily round / a lesson (docs/06 §14); none after the round itself, after the
            // review of a lesson (its step 3 is the game, docs/31 §5б) or while it is off.
            'hard_round_offer' => in_array($summary->session->mode, [TrainingSession::MODE_HARD_REVIEW, TrainingSession::MODE_LESSON_REVIEW], true) || ! $this->hardRound->enabled()
                ? null
                : $this->hardRound->availability($learner)->toArray(),
        ]))->response();
    }

    public function start(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        $start = $this->flow->start($session, $learner);
        if ($start === null) {
            return $this->lessonNotFound();
        }

        return (new TrainingStartResource($start))->response();
    }

    public function next(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        return (new TrainingNextResource($this->flow->nextStep($session, $learner)))->response();
    }

    public function review(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        // `quality` (flash) or `answer` (the picked option of meaning / reverse / audio / pretest, the typed text of spelling / gap-fill),
        // never both (docs/04 A.3, docs/06 §12.2). An `answer` key that is present but empty ("" → null) is an empty answer, graded as wrong.
        $typed = $request->has('answer');

        $validated = $request->validate([
            'word_id' => ['required', 'integer'],
            'quality' => $typed ? ['prohibited'] : ['required', 'integer', 'min:0', 'max:5'],
            // A picked option is a translation of up to 500 characters (the import's cap).
            'answer' => $typed ? ['nullable', 'string', 'max:500'] : ['prohibited'],
            'time_spent_ms' => ['required', 'integer', 'min:0', 'max:600000'],
            'serve_token' => ['nullable', 'string', 'max:64'],
            // The exercise the client showed (`card.prompt.type`): another one than the server's now → 409 `exercise_mismatch`.
            'exercise' => ['nullable', 'string', Rule::enum(ExerciseType::class)],
        ]);

        $declared = isset($validated['exercise']) ? ExerciseType::from((string) $validated['exercise']) : null;
        $serveToken = isset($validated['serve_token']) ? (string) $validated['serve_token'] : null;

        try {
            $outcome = $typed
                ? $this->flow->answer(
                    $session,
                    $learner,
                    (int) $validated['word_id'],
                    isset($validated['answer']) ? (string) $validated['answer'] : '',
                    (int) $validated['time_spent_ms'],
                    $serveToken,
                    $declared,
                )
                : $this->flow->review(
                    $session,
                    $learner,
                    (int) $validated['word_id'],
                    (int) $validated['quality'],
                    (int) $validated['time_spent_ms'],
                    $serveToken,
                    $declared,
                );
        } catch (ReviewSubmissionException $e) {
            return ApiErrorResource::respond($e->status, $e->errorCode, $e->getMessage());
        }

        return (new ReviewResultResource($outcome))->response();
    }

    private function lessonNotFound(): JsonResponse
    {
        return ApiErrorResource::respond(404, 'lesson_not_found', 'Training lesson not found.');
    }

    private function lessonNotAvailable(): JsonResponse
    {
        return ApiErrorResource::respond(404, 'lesson_not_available', 'This lesson is no longer available.');
    }

    private function unauthorizedLearner(): JsonResponse
    {
        return ApiErrorResource::respond(401, 'learner_not_found', 'Unauthorized');
    }

    /**
     * @return array{0: Learner, 1: TrainingSession}|array{0: null, 1: JsonResponse}
     */
    private function resolve(Request $request, int $sessionId): array
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $session = $this->sessions->findForStep($sessionId);

        if ($session === null) {
            return [null, ApiErrorResource::respond(404, 'session_not_found', 'Training session not found.')];
        }

        $denial = $this->policy->denial($learner, $session);
        if ($denial !== null) {
            return [null, ApiErrorResource::respond(403, $denial, 'You cannot access this session.')];
        }

        if (! $session->isLiveAt(CarbonImmutable::now())) {
            return [null, ApiErrorResource::respond(410, 'session_closed', 'This training session is closed.')];
        }

        // A center's private lesson stops opening once the assignment or the membership is gone.
        if (! $this->sessions->ensureStillAvailable($session, $learner)) {
            return [null, $this->lessonNotAvailable()];
        }

        return [$learner, $session];
    }
}
