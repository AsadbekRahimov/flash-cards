<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Content\Services\WordExamples;
use App\Domain\Content\Services\WordHookResolver;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Content\Support\Phrase;
use App\Domain\Learners\DTO\DayBounds;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Support\LearnerRowLock;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\DTO\RevealedCard;
use App\Domain\Learning\DTO\ReviewOutcome;
use App\Domain\Learning\DTO\SpellingVerdict;
use App\Domain\Learning\DTO\TrainingStart;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\LessonReviewException;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Learning\Support\LessonReviewState;
use App\Domain\Learning\Support\MemoryStrength;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The review of a lesson just studied (`lesson_review`, docs/04 A.3, docs/06 §15) - step 2 "Тренировка" of the day plan (docs/31 §5б).
 *
 * WHAT IS ASKED. The words of ONE lesson the learner met for the first time TODAY (their own `word_repetitions` row was born in the
 * learner's local day - {@see LearnerClock::dayBoundsUtc()}, the day of the goal and the streak - and was graded at least once:
 * `state <> new`), active, in study order (by id), at most `learning.lesson_review.max_words`. The set is fixed in `meta` when the round
 * opens ({@see LessonReviewState}). Each word is asked until it is answered right, at most `max_serves_per_word` times; a word answered
 * wrong comes back after the others. Objective exercises only: "pick the translation" (meaning), "pick the word" (reverse) and - when
 * the word has a file - "pick by ear" (audio), alternating by the position of the word and its serve; a card without three distractors
 * falls back to flash (the learner's own grade), as in every mode.
 *
 * WHAT IT DOES NOT DO - the round is a practice, not a repetition:
 *   - the schedule is not touched: no `word_repetitions` write at all (a pass would grow the interval of a word met an hour ago - SM-2
 *     multiplies the stored interval whatever the elapsed time - and a fail would put a lapse on a word that has not had a chance yet:
 *     its first real repetition is tomorrow's, in the daily round);
 *   - no `training_reviews`, no `learner_daily_stats`: no league points (old formula or v2 - docs/32 credits only an answer to an
 *     assigned repetition, and none is assigned here), no daily goal, no streak, no achievements. That is what makes it safe to play
 *     again: a second round the same day gives exactly as little as the first;
 *   - no new word: the Free quota is neither read nor spent.
 *
 * OPENING ({@see open()}): one transaction under the learner lock (`learners FOR NO KEY UPDATE`, then `training_sessions`, the order of
 * the picker and the round of hard words). One open round per learner (`training_sessions_one_open_lesson_review_per_learner` is the
 * source of truth): the live round of the same lesson begun today is returned (two tabs get one session); a round played to its end is
 * closed `finished` (with the side effects of `/finish`) and a fresh one opens; any other open round (another lesson, yesterday's,
 * expired) is closed `expired`. Refusals are thrown after the commit.
 *
 * ANSWERING ({@see nextStep()}, {@see answer()}): every write is ONE update of `training_sessions.meta` under `training_sessions FOR
 * UPDATE` - nothing else is locked. The card on screen is remembered with a one-time token (`serve_token` of the card): `/next` hands
 * the same card back until it is answered; an answer must carry the token of the card on screen, so a replay (the token is spent with
 * the answer) or an answer of a card replaced in another tab is 409 `card_not_due`, nothing counted.
 */
final class LessonReviewService
{
    public function __construct(
        private readonly LearnerClock $clock,
        private readonly TrainingSessionService $sessions,
        private readonly CourseCatalogService $catalog,
        private readonly DeckTitles $deckTitles,
        private readonly EntitlementResolver $entitlements,
        private readonly ExerciseSelector $selector,
        private readonly ExercisePromptBuilder $prompts,
        private readonly WordTranslationResolver $translations,
        private readonly LocaleResolver $locales,
        private readonly WordAudioService $audio,
        private readonly WordExamples $examples,
        private readonly SpellingGrader $grader,
        private readonly WordHookResolver $hooks,
        private readonly LadderPolicy $ladder,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('learning.lesson_review.enabled', true);
    }

    public function maxWords(): int
    {
        return max(1, (int) config('learning.lesson_review.max_words', 20));
    }

    public function maxServes(): int
    {
        return max(1, (int) config('learning.lesson_review.max_serves_per_word', 3));
    }

    /** The learner's local day "met today" is counted in: the day of the goal and the streak. */
    public function day(Learner $learner, ?CarbonImmutable $now = null): DayBounds
    {
        return $this->clock->dayBoundsUtc($learner, $now);
    }

    /**
     * The learner's rows of the active words of the lesson that were born in the day and graded at least once - the words the review
     * asks (`word_repetitions.word_id`, study order). For a caller that counts them among other scalars (the day plan, DayPlanService).
     */
    public function wordsMetTodayQuery(int $learnerId, int $lessonId, CarbonImmutable $dayStart): QueryBuilder
    {
        return DB::table('word_repetitions')
            ->join('words', 'words.id', '=', 'word_repetitions.word_id')
            ->where('word_repetitions.learner_id', $learnerId)
            ->where('words.lesson_id', $lessonId)
            ->where('words.is_active', true)
            ->where('word_repetitions.created_at', '>=', $dayStart)
            ->where('word_repetitions.state', '<>', RepetitionState::New->value)
            ->orderBy('word_repetitions.word_id')
            ->select('word_repetitions.word_id');
    }

    /** @return list<int> the set a round opened now would ask */
    public function wordsMetToday(int $learnerId, int $lessonId, CarbonImmutable $dayStart): array
    {
        /** @var list<int> $ids */
        $ids = $this->wordsMetTodayQuery($learnerId, $lessonId, $dayStart)
            ->limit($this->maxWords())
            ->pluck('word_repetitions.word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * Opens (or returns) the learner's review of the lesson. Must not be called inside an outer transaction (the lost-race recovery
     * re-reads the winner's row after a unique violation).
     *
     * @param  Lesson  $lesson  with `stage.course` loaded ({@see CourseCatalogService::lessonWithContext()})
     *
     * @throws LessonReviewException disabled | no_new_words | no_objective_exercise
     * @throws TrainingSessionException lesson_not_available (404 for the client, as `mode: lesson`)
     */
    public function open(Learner $learner, Lesson $lesson, string $source = 'self', ?CarbonImmutable $now = null): TrainingSession
    {
        $now ??= CarbonImmutable::now();

        if (! $this->enabled()) {
            throw new LessonReviewException(LessonReviewException::REASON_DISABLED);
        }
        $this->sessions->assertLessonOpenable($lesson, $learner);

        if (! $this->hasObjectiveExercise($learner, $this->entitlements->for($learner, $now))) {
            throw new LessonReviewException(LessonReviewException::REASON_NO_OBJECTIVE_EXERCISE);
        }

        $day = $this->day($learner, $now);

        try {
            $result = DB::transaction(fn (): TrainingSession|LessonReviewException => $this->openLocked($learner, $lesson, $source, $now, $day));
        } catch (UniqueConstraintViolationException) {
            // Lost the race against an open that bypassed the learner lock: the winner's row serves when it is this lesson.
            /** @var TrainingSession|null $winner */
            $winner = $this->openRound($learner)->first();
            if ($winner === null || (int) $winner->lesson_id !== (int) $lesson->id) {
                throw new LessonReviewException(LessonReviewException::REASON_NO_NEW_WORDS);
            }

            return $winner;
        }

        if ($result instanceof LessonReviewException) {
            throw $result;
        }

        return $result;
    }

    private function openLocked(Learner $learner, Lesson $lesson, string $source, CarbonImmutable $now, DayBounds $day): TrainingSession|LessonReviewException
    {
        LearnerRowLock::take((int) $learner->id);

        /** @var TrainingSession|null $existing */
        $existing = $this->openRound($learner)->lockForUpdate()->first();
        if ($existing !== null) {
            $sameLesson = (int) $existing->lesson_id === (int) $lesson->id;
            if ($existing->completed_at !== null) {
                // Played to the end but never closed: what `/finish` would have done, then a fresh round.
                $this->sessions->finish($existing, TrainingSession::DONE_FINISHED, $now, $learner);
            } elseif ($sameLesson && $existing->isLiveAt($now) && ! $existing->started_at->lt($day->startUtc)) {
                return $existing;
            } else {
                // Another lesson, yesterday's or expired, left in the middle: closed the way the scheduler closes it.
                $existing->update(['status' => 'closed', 'ended_at' => $now, 'done_reason' => TrainingSession::DONE_EXPIRED]);
            }
        }

        // Read under the lock: the set is the words met today as they are now.
        $words = $this->wordsMetToday((int) $learner->id, (int) $lesson->id, $day->startUtc);
        if ($words === []) {
            return new LessonReviewException(LessonReviewException::REASON_NO_NEW_WORDS);
        }

        $attributes = [
            'learner_id' => $learner->id,
            'mode' => TrainingSession::MODE_LESSON_REVIEW,
            'lesson_id' => $lesson->id,
            'course_id' => null,
            'telegram_group_id' => null,
            'started_by_user_id' => null,
            'source' => $source,
            'status' => 'open',
            'started_at' => $now,
            'expires_at' => $now->addHours(max(1, (int) config('learning.session_ttl_hours', 12))),
            'meta' => LessonReviewState::fresh($words)->toMeta(),
        ];
        TrainingSessionService::assertModeShape($attributes);

        return TrainingSession::query()->create($attributes);
    }

    /**
     * The answer of `POST /training/sessions` and `/start` for a review: the lesson header, the size of the set, the card on screen.
     *
     * @param  Lesson|null  $lesson  the session's lesson with `stage.course`, when the caller has it; read here otherwise
     */
    public function start(TrainingSession $session, Learner $learner, ?Lesson $lesson = null): ?TrainingStart
    {
        if ($lesson === null || (int) $lesson->id !== (int) $session->lesson_id || ! $lesson->relationLoaded('stage')) {
            $lesson = $this->catalog->lessonWithContext((int) $session->lesson_id);
        }
        if ($lesson === null) {
            return null;
        }
        $session->setRelation('lesson', $lesson);

        $step = $this->nextStep($session, $learner);

        return new TrainingStart($session, $lesson, $step->progress['total'], $step, $this->deckTitles->forLesson($lesson, $learner));
    }

    /**
     * The card on screen, or the next one (remembered with a fresh token), or "done". One transaction on the session row.
     */
    public function nextStep(TrainingSession $session, Learner $learner, ?Entitlement $entitlement = null): TrainingStep
    {
        $now = CarbonImmutable::now();
        $entitlement ??= $this->entitlements->for($learner, $now);
        $locale = $this->locales->forTrainingSession($session, $learner);
        $settings = LearnerExerciseSettings::fromLearner($learner);
        $maxServes = $this->maxServes();

        /** @var array{0: LessonReviewState, 1: array{word: Word, repetition: WordRepetition, exercise: ExerciseType, token: string, seed: int, translation: ResolvedTranslation, example: string|null, exampleTranslation: string|null, audioUrl: string|null}|null} $picked */
        $picked = DB::transaction(function () use ($session, $learner, $entitlement, $settings, $locale, $maxServes, $now): array {
            /** @var TrainingSession|null $locked */
            $locked = TrainingSession::query()->select(['id', 'status', 'expires_at', 'meta'])->lockForUpdate()->find($session->id);
            $state = LessonReviewState::of($locked ?? $session);
            if ($locked === null || ! $locked->isLiveAt($now)) {
                return [$state, null];
            }

            $dirty = false;
            $card = null;
            // Each turn either returns a card or uses up a position for good: the loop ends within the size of the set.
            for ($guard = 0; $guard <= $state->total(); $guard++) {
                $serve = $state->serve();
                $pos = $serve['pos'] ?? $state->next($maxServes);
                if ($pos === null) {
                    break;
                }

                $loaded = $this->load((int) $learner->id, $state->words[$pos]);
                if ($loaded === null) {
                    $state->exhaust($pos, $maxServes);
                    $dirty = true;

                    continue;
                }
                [$word, $repetition] = $loaded;

                $translation = $this->translations->resolve($word, $locale);
                ['text' => $example, 'translation' => $exampleTranslation] = $this->examples->pickRow($word, $repetition, $locale, $translation->exampleTranslation);
                $audioUrl = $this->audio->urlFor($word);
                $seed = self::seed((int) $session->id, (int) $word->id, $state->triesAt($pos));

                if ($serve !== null) {
                    $exercise = ExerciseType::tryFrom($serve['exercise']) ?? ExerciseType::Flash;
                    $token = $serve['token'];
                } else {
                    $exercise = $this->prompts->effectiveType(
                        $this->exerciseFor($pos, $state->triesAt($pos), $entitlement, $settings, $audioUrl !== null),
                        $word,
                        $locale,
                        $seed,
                        $example,
                    );
                    $token = CardPicker::newToken();
                    $state->startServe($pos, $exercise->value, $token);
                    $dirty = true;
                }

                $card = compact('word', 'repetition', 'exercise', 'token', 'seed', 'translation', 'example', 'exampleTranslation', 'audioUrl');
                break;
            }

            if ($dirty) {
                $this->save((int) $session->id, $state, $now);
            }

            return [$state, $card];
        });

        [$state, $card] = $picked;
        $session->forceFill(['meta' => $state->toMeta()])->syncOriginalAttribute('meta');
        $progress = ['done' => $state->wonCount(), 'total' => $state->total()];

        if ($card === null) {
            if ($state->answers() > 0) {
                $this->sessions->markCompleted($session, $now);
            }

            return TrainingStep::done(DoneReason::LessonReviewComplete, $progress, null);
        }

        $word = $card['word'];

        return new TrainingStep(
            $word,
            CardPicker::kindOf($card['repetition']),
            $progress,
            $card['token'],
            $card['translation'],
            newWordsRemaining: null,
            prompt: $this->prompts->build($card['exercise'], $word, $card['translation'], $locale, $card['audioUrl'], $card['seed'], $card['example']),
            audioUrl: $card['audioUrl'],
            example: $card['example'],
            exampleTranslation: $card['exampleTranslation'],
            exampleAudioUrl: $this->audio->exampleUrlFor($word, $card['example']),
            isHard: (bool) $card['repetition']->is_hard,
            // The method of the serve, as on a card in the minute steps: the hook of the word (hidden by the resource on a choice card).
            hook: $this->ladder->hooksEnabled ? $this->hooks->for($word, $card['translation']->locale) : null,
            // The row as it is (this mode does not move the schedule): the Mini App hides the dots here (docs/40 §2.2), the contract keeps the field.
            memory: MemoryStrength::reading($card['repetition'], $now),
        );
    }

    /**
     * An answer to the card on screen: the picked option (`$answer`) of a four-option card, or the learner's own `$quality` of a flash
     * card (the fallback of a card without distractors). Counted in the round only - the schedule, the day, the points are not touched.
     *
     * @throws ReviewSubmissionException 410 session_closed | 409 card_not_due | 422 out_of_scope | 409 exercise_mismatch |
     *                                   422 quality_not_accepted | 422 answer_not_accepted
     */
    public function answer(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        ?string $answer,
        ?int $quality,
        ?string $serveToken,
        ?ExerciseType $declared = null,
    ): ReviewOutcome {
        $now = CarbonImmutable::now();
        $entitlement = $this->entitlements->for($learner, $now);
        $locale = $this->locales->forTrainingSession($session, $learner);

        /** @var array{0: WordRepetition, 1: SpellingVerdict|null, 2: RevealedCard|null} $accepted */
        $accepted = DB::transaction(function () use ($session, $learner, $wordId, $answer, $quality, $serveToken, $declared, $now, $locale): array {
            /** @var TrainingSession|null $locked */
            $locked = TrainingSession::query()->select(['id', 'status', 'expires_at', 'meta'])->lockForUpdate()->find($session->id);
            if ($locked === null || ! $locked->isLiveAt($now)) {
                throw new ReviewSubmissionException(410, 'session_closed', 'This training session is closed.');
            }

            $state = LessonReviewState::of($locked);
            if (! in_array($wordId, $state->words, true)) {
                throw new ReviewSubmissionException(422, 'out_of_scope', 'Word is outside the scope of this training session.');
            }

            $serve = $state->serve();
            if ($serve === null || $serve['word_id'] !== $wordId || $serveToken === null || $serveToken === '' || ! hash_equals($serve['token'], $serveToken)) {
                // Answered already (a replay), never served, or replaced by another tab: nothing is counted, the client asks /next.
                throw new ReviewSubmissionException(409, 'card_not_due', 'This card was already answered; fetch the next one.');
            }

            $exercise = ExerciseType::tryFrom($serve['exercise']) ?? ExerciseType::Flash;
            if ($declared !== null && $declared !== $exercise) {
                throw new ReviewSubmissionException(409, 'exercise_mismatch', 'The exercise of this card has changed; fetch the card again.');
            }

            $loaded = $this->load((int) $learner->id, $wordId);
            if ($loaded === null) {
                throw new ReviewSubmissionException(422, 'out_of_scope', 'Word is outside the scope of this training session.');
            }
            [$word, $repetition] = $loaded;
            $translation = $this->translations->resolve($word, $locale);

            $verdict = null;
            if ($exercise->selfGraded()) {
                if ($answer !== null || $quality === null) {
                    throw new ReviewSubmissionException(422, 'answer_not_accepted', 'This card is graded by the client: send `quality`, not `answer`.');
                }
                $right = $quality >= SpacedRepetitionEngine::PASS_QUALITY;
            } else {
                if ($answer === null) {
                    throw new ReviewSubmissionException(422, 'quality_not_accepted', 'This card is graded by the server: send `answer`, not `quality`.');
                }
                $expected = $this->grader->normalize($this->prompts->correctOption($exercise, $word, $translation));
                $picked = $this->grader->normalize($answer);
                $right = $expected !== '' && ($expected === $picked || $this->prompts->acceptsPick($exercise, $word, $translation, $locale, $answer));
                $verdict = new SpellingVerdict(
                    $right ? ReviewSubmissionService::CHOICE_PASS_QUALITY : ReviewSubmissionService::CHOICE_FAIL_QUALITY,
                    $right ? 0 : 1,
                    $expected,
                    $picked,
                );
            }

            // The example the card showed: the same pick as the serve's (the row did not move - nothing writes it here).
            ['text' => $example, 'translation' => $exampleTranslation] = $this->examples->pickRow($word, $repetition, $locale, $translation->exampleTranslation);

            $state->record($right);
            $this->save((int) $session->id, $state, $now);

            // A phrase on gap_fill / pretest comes back too, for its literal translation (docs/06 §16): no example, no hook.
            $choice = $exercise->hidesChoiceAnswer();
            $revealed = $exercise->revealsAfterAnswer(Phrase::isPhraseWord($word)) ? new RevealedCard(
                word: $word->word,
                translation: $translation->text,
                correct: RevealedCard::correctOf($exercise, $word->word, $translation->text),
                example: $choice ? $example : null,
                exampleTranslation: $choice ? $exampleTranslation : null,
                transcription: $word->transcription,
                audioUrl: $this->audio->urlFor($word),
                hook: $choice && $this->ladder->hooksEnabled ? $this->hooks->for($word, $translation->locale) : null,
                note: null,
                confusedWith: null,
                exampleAudioUrl: $choice ? $this->audio->exampleUrlFor($word, $example) : null,
                phrase: Phrase::forCard($word, $translation->locale, true),
            ) : null;

            return [$repetition, $verdict, $revealed];
        });

        [$repetition, $verdict, $revealed] = $accepted;

        // The row as it is: the schedule did not move (`next_review_at`, `new_interval_days` of the answer say so).
        return new ReviewOutcome($repetition, $this->nextStep($session, $learner, $entitlement), $verdict, $revealed);
    }

    /**
     * The exercise of a serve: the objective ones the plan, the config and the learner allow, by turns - the position of the word and
     * its serve pick one, so a word asked again comes in another format. Audio only with a file of the word.
     */
    private function exerciseFor(int $pos, int $tries, Entitlement $entitlement, LearnerExerciseSettings $settings, bool $hasAudio): ExerciseType
    {
        $types = [];
        foreach ([ExerciseType::Meaning, ExerciseType::Reverse, ExerciseType::Audio] as $type) {
            if ($type === ExerciseType::Audio && ! $hasAudio) {
                continue;
            }
            if ($this->selector->available($type, $entitlement, $settings)) {
                $types[] = $type;
            }
        }

        return $types === [] ? ExerciseType::Flash : $types[($pos + $tries) % count($types)];
    }

    private function hasObjectiveExercise(Learner $learner, Entitlement $entitlement): bool
    {
        $settings = LearnerExerciseSettings::fromLearner($learner);

        return $this->selector->available(ExerciseType::Meaning, $entitlement, $settings)
            || $this->selector->available(ExerciseType::Reverse, $entitlement, $settings);
    }

    /** @return array{0: Word, 1: WordRepetition}|null the active word and the learner's row of it; null when either is gone */
    private function load(int $learnerId, int $wordId): ?array
    {
        /** @var WordRepetition|null $repetition */
        $repetition = WordRepetition::query()->where('learner_id', $learnerId)->where('word_id', $wordId)->first();
        /** @var Word|null $word */
        $word = $repetition === null ? null : Word::query()->whereKey($wordId)->where('is_active', true)->first();

        return $repetition === null || $word === null ? null : [$word, $repetition];
    }

    private function save(int $sessionId, LessonReviewState $state, CarbonImmutable $now): void
    {
        TrainingSession::query()->whereKey($sessionId)->update([
            'meta' => json_encode($state->toMeta(), JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);
    }

    /** @return Builder<TrainingSession> */
    private function openRound(Learner $learner): Builder
    {
        return TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', TrainingSession::MODE_LESSON_REVIEW)
            ->where('status', 'open');
    }

    /** The seed of the options of a serve: stable for the card on screen, another one for the next serve of the word. */
    private static function seed(int $sessionId, int $wordId, int $tries): int
    {
        return crc32("lesson_review:{$sessionId}:{$wordId}:{$tries}");
    }
}
