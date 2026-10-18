<?php

declare(strict_types=1);

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\DTO\CardPick;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\CardPicker;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Policies\TrainingSessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function personalHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** A published course with `$lessons` lessons of `$wordsPerLesson` words each, in study order. */
function publishedCourse(int $lessons = 2, int $wordsPerLesson = 2, int $stageNumber = 1): Course
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => $stageNumber]);
    for ($i = 1; $i <= $lessons; $i++) {
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $i]);
        Word::factory()->count($wordsPerLesson)->for($lesson)->create();
    }

    return $course;
}

/** @return Collection<int, Lesson> */
function courseLessons(Course $course): Collection
{
    return Lesson::query()
        ->select('lessons.*')
        ->join('stages', 'stages.id', '=', 'lessons.stage_id')
        ->where('stages.course_id', $course->id)
        ->orderBy('stages.number')->orderBy('lessons.number')
        ->get();
}

function dueRow(Learner $learner, Word $word, bool $hard = false, int $daysAgo = 1): WordRepetition
{
    return WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'state' => RepetitionState::Review->value,
        'repetitions' => 2,
        'interval_days' => 6,
        'is_hard' => $hard,
        'next_review_at' => now()->subDays($daysAgo),
        'served_ahead_token' => null,
    ]);
}

function pick(Learner $learner, TrainingScope $scope, ?NewCardBudget $budget = null): CardPick
{
    return app(CardPicker::class)->pickNext((int) $learner->id, $scope, $budget);
}

// --- openPersonal ----------------------------------------------------------------

it('opens a personal lesson session once per (learner, lesson) and returns it on repeat', function (): void {
    $course = publishedCourse(2);
    [$lessonA, $lessonB] = courseLessons($course)->all();
    $learner = Learner::factory()->create();
    $service = app(TrainingSessionService::class);
    $now = CarbonImmutable::parse('2026-10-18 09:00:00');

    $first = $service->openPersonal($learner, 'lesson', $lessonA, 'picker', $now);
    $again = $service->openPersonal($learner, 'lesson', $lessonA, 'self', $now->addMinutes(5));
    $other = $service->openPersonal($learner, 'lesson', $lessonB, 'self', $now);

    expect($again->id)->toBe($first->id)
        ->and($again->source)->toBe('picker')
        ->and($other->id)->not->toBe($first->id)
        ->and($first->mode)->toBe('lesson')
        ->and($first->learner_id)->toBe($learner->id)
        ->and($first->lesson_id)->toBe($lessonA->id)
        ->and($first->telegram_group_id)->toBeNull()
        ->and($first->started_by_user_id)->toBeNull()
        ->and($first->course_id)->toBeNull()
        ->and($first->status)->toBe('open')
        ->and($first->started_at->toIso8601String())->toBe($now->toIso8601String())
        ->and($first->expires_at?->toIso8601String())->toBe($now->addHours(12)->toIso8601String())
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->count())->toBe(2);
});

it('opens ONE daily review per learner, snapshotting and refreshing the active course', function (): void {
    $courseA = publishedCourse(1);
    $courseB = publishedCourse(1);
    $learner = Learner::factory()->create(['active_course_id' => $courseA->id]);
    $stranger = Learner::factory()->create(['active_course_id' => $courseA->id]);
    $service = app(TrainingSessionService::class);

    $daily = $service->openPersonal($learner, 'daily_review');
    expect($daily->mode)->toBe('daily_review')
        ->and($daily->lesson_id)->toBeNull()
        ->and($daily->course_id)->toBe($courseA->id)
        ->and($daily->source)->toBe('self')
        ->and($daily->expires_at)->not->toBeNull();

    expect($service->openPersonal($learner, 'daily_review', null, 'reminder')->id)->toBe($daily->id);

    // The learner switches course: the still-open session follows (new words come from the new course).
    $learner->update(['active_course_id' => $courseB->id]);
    expect($service->openPersonal($learner->fresh(), 'daily_review')->id)->toBe($daily->id)
        ->and($daily->fresh()?->course_id)->toBe($courseB->id);

    // Another learner gets their own session, never this one.
    expect($service->openPersonal($stranger, 'daily_review')->id)->not->toBe($daily->id)
        ->and(TrainingSession::query()->where('mode', 'daily_review')->where('status', 'open')->count())->toBe(2);

    // No active course: a daily review still opens (due cards only).
    $noCourse = Learner::factory()->create(['active_course_id' => null]);
    expect($service->openPersonal($noCourse, 'daily_review')->course_id)->toBeNull();
});

it('validates the personal mode, the lesson argument and the course status', function (): void {
    $learner = Learner::factory()->create();
    $published = courseLessons(publishedCourse(1))->first();
    $draft = Lesson::factory()->for(Stage::factory()->for(Course::factory()))->create();
    $service = app(TrainingSessionService::class);

    $reason = function (callable $call): string {
        try {
            $call();
        } catch (TrainingSessionException $e) {
            return $e->reason;
        }

        return 'no exception';
    };

    expect($reason(fn () => $service->openPersonal($learner, 'group_lesson', $published)))->toBe('invalid_mode')
        ->and($reason(fn () => $service->openPersonal($learner, 'exam')))->toBe('invalid_mode')
        ->and($reason(fn () => $service->openPersonal($learner, 'lesson', null)))->toBe('lesson_mismatch')
        ->and($reason(fn () => $service->openPersonal($learner, 'daily_review', $published)))->toBe('lesson_mismatch')
        ->and($reason(fn () => $service->openPersonal($learner, 'lesson', $draft)))->toBe('lesson_not_available')
        ->and(TrainingSession::query()->count())->toBe(0);
});

it('replaces an open session whose expires_at already passed instead of returning it', function (): void {
    $lesson = courseLessons(publishedCourse(1))->first();
    $learner = Learner::factory()->create();
    $service = app(TrainingSessionService::class);
    $opened = CarbonImmutable::parse('2026-10-18 09:00:00');

    $stale = $service->openPersonal($learner, 'lesson', $lesson, 'self', $opened);

    $fresh = $service->openPersonal($learner, 'lesson', $lesson, 'self', $opened->addHours(13));

    expect($fresh->id)->not->toBe($stale->id)
        ->and($fresh->status)->toBe('open')
        ->and($stale->fresh()?->status)->toBe('closed')
        ->and($stale->fresh()?->done_reason)->toBe('expired')
        ->and($stale->fresh()?->ended_at?->toIso8601String())->toBe($opened->addHours(12)->toIso8601String())
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->where('status', 'open')->count())->toBe(1);
});

// --- finish ----------------------------------------------------------------------

it('finishes a personal session with a summary, idempotently, and refuses group sessions', function (): void {
    $course = publishedCourse(1, 3);
    $lesson = courseLessons($course)->first();
    $learner = Learner::factory()->create();
    $service = app(TrainingSessionService::class);
    $flow = app(TrainingFlowService::class);
    $session = $service->openPersonal($learner, 'lesson', $lesson);

    // Two new cards graded: one right (Easy), one wrong; plus a card that was learned before this session.
    $old = dueRow($learner, Word::factory()->for($lesson)->create());
    $old->forceFill(['created_at' => now()->subDay()])->save(); // seen long before this session
    $flow->review($session, $learner, (int) $old->word_id, 4, 500);
    $w1 = $flow->nextStep($session, $learner)->word;
    $flow->review($session, $learner, (int) $w1?->id, 5, 700);
    $w2 = $flow->nextStep($session, $learner)->word;
    $flow->review($session, $learner, (int) $w2?->id, 2, 900);

    $closedAt = CarbonImmutable::now()->addMinutes(10);
    $summary = $service->finish($session, now: $closedAt);

    expect($summary->wasOpen)->toBeTrue()
        ->and($summary->reviewed)->toBe(3)
        ->and($summary->correct)->toBe(2)
        ->and($summary->newWords)->toBe(2)
        ->and($summary->session->status)->toBe('closed')
        ->and($summary->session->done_reason)->toBe('finished')
        ->and($summary->session->ended_at?->toIso8601String())->toBe($closedAt->toIso8601String());

    $again = $service->finish($session, now: $closedAt->addHour());
    expect($again->wasOpen)->toBeFalse()
        ->and($again->reviewed)->toBe(3)
        ->and($again->session->ended_at?->toIso8601String())->toBe($closedAt->toIso8601String());

    // A finished session accepts nothing more (410 at the HTTP layer, session_closed in the service).
    expect(fn () => app(ReviewSubmissionService::class)->submit($session, $learner, (int) $w2?->id, 4, 100))
        ->toThrow(fn (ReviewSubmissionException $e) => expect($e->status)->toBe(410)->and($e->errorCode)->toBe('session_closed'));
    $this->withHeaders(personalHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/start")
        ->assertStatus(410);

    $group = TrainingSession::factory()->create();
    expect(fn () => $service->finish($group))
        ->toThrow(fn (TrainingSessionException $e) => expect($e->reason)->toBe('not_personal'));
    expect($group->fresh()?->status)->toBe('open');
});

// --- closeExpired ----------------------------------------------------------------

it('closes expired personal sessions and stale group sessions by the scheduler rule', function (): void {
    $now = CarbonImmutable::parse('2026-10-18 12:00:00');
    $learner = Learner::factory()->create();

    $expired = TrainingSession::factory()->personal($learner, 'daily_review')->create(['expires_at' => $now->subMinute()]);
    $alive = TrainingSession::factory()->personal($learner)->create(['expires_at' => $now->addMinute()]);
    $alreadyClosed = TrainingSession::factory()->personal(Learner::factory()->create(), 'daily_review')->closed()
        ->create(['expires_at' => $now->subDay(), 'done_reason' => 'finished']);
    $oldGroup = TrainingSession::factory()->create(['started_at' => $now->subHours(25)]);
    $recentGroup = TrainingSession::factory()->create(['started_at' => $now->subHours(23)]);

    expect(app(TrainingSessionService::class)->closeExpired($now))->toBe(2);

    expect($expired->fresh()?->status)->toBe('closed')
        ->and($expired->fresh()?->done_reason)->toBe('expired')
        ->and($expired->fresh()?->ended_at?->toIso8601String())->toBe($now->toIso8601String())
        ->and($alive->fresh()?->status)->toBe('open')
        ->and($alreadyClosed->fresh()?->done_reason)->toBe('finished')
        ->and($oldGroup->fresh()?->status)->toBe('closed')
        ->and($oldGroup->fresh()?->done_reason)->toBe('expired')
        ->and($recentGroup->fresh()?->status)->toBe('open');

    // Second run: nothing left.
    expect(app(TrainingSessionService::class)->closeExpired($now))->toBe(0);
});

// --- policy ----------------------------------------------------------------------

it('lets only the owner finish a personal session and nobody finish a group one', function (): void {
    $owner = Learner::factory()->create();
    $stranger = Learner::factory()->create();
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $member = Learner::factory()->memberOf($group)->create();
    $personal = TrainingSession::factory()->personal($owner, 'daily_review')->create();
    $groupSession = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
    $policy = app(TrainingSessionPolicy::class);

    expect($policy->canFinish($owner, $personal))->toBeTrue()
        ->and($policy->canFinish($stranger, $personal))->toBeFalse()
        ->and($policy->canFinish($member, $groupSession))->toBeFalse()
        ->and($policy->canAccess($member, $groupSession))->toBeTrue();

    $owner->update(['blocked_at' => now()]);
    expect($policy->canFinish($owner->fresh(), $personal))->toBeFalse();
});

// --- daily_review scope ----------------------------------------------------------

it('serves due cards from different lessons and courses in a daily review, hard first', function (): void {
    $courseA = publishedCourse(2, 1);
    $courseB = publishedCourse(1, 1);
    [$a1, $a2] = courseLessons($courseA)->all();
    $b1 = courseLessons($courseB)->first();
    $learner = Learner::factory()->create(['active_course_id' => null]);

    $fromA1 = dueRow($learner, $a1->words()->first(), hard: false, daysAgo: 3);
    $fromA2 = dueRow($learner, $a2->words()->first(), hard: true, daysAgo: 1);
    $fromB1 = dueRow($learner, $b1->words()->first(), hard: false, daysAgo: 2);
    // Not due yet: must not appear.
    WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->for($b1)->create()->id,
        'state' => RepetitionState::Review->value, 'repetitions' => 1, 'next_review_at' => now()->addDays(2),
    ]);

    $session = app(TrainingSessionService::class)->openPersonal($learner, 'daily_review');
    $flow = app(TrainingFlowService::class);
    $served = [];
    for ($i = 0; $i < 3; $i++) {
        $step = $flow->nextStep($session, $learner);
        expect($step->kind)->toBe('due');
        $served[] = (int) $step->word?->id;
        $flow->review($session, $learner, (int) $step->word?->id, 4, 300);
    }

    // hard first, then the earliest due.
    expect($served)->toBe([(int) $fromA2->word_id, (int) $fromA1->word_id, (int) $fromB1->word_id]);

    $done = $flow->nextStep($session, $learner);
    expect($done->word)->toBeNull()
        ->and($done->doneReason)->toBe(DoneReason::NothingDue)
        ->and($done->progress)->toBe(['done' => 3, 'total' => (int) $learner->daily_goal]);
});

it('takes new words for a daily review from the active course in study order, skipping other courses', function (): void {
    $active = Course::factory()->published()->create();
    $stage2 = Stage::factory()->create(['course_id' => $active->id, 'number' => 2]);
    $stage1 = Stage::factory()->create(['course_id' => $active->id, 'number' => 1]);
    $s2l1 = Lesson::factory()->create(['stage_id' => $stage2->id, 'number' => 1]);
    $s1l2 = Lesson::factory()->create(['stage_id' => $stage1->id, 'number' => 2]);
    $s1l1 = Lesson::factory()->create(['stage_id' => $stage1->id, 'number' => 1]);
    $w21 = Word::factory()->for($s2l1)->create();
    $w12 = Word::factory()->for($s1l2)->create();
    $w11a = Word::factory()->for($s1l1)->create();
    $w11b = Word::factory()->for($s1l1)->create();
    $inactive = Word::factory()->for($s1l1)->create(['is_active' => false]);
    // Another published course: never a source of new words for this learner.
    publishedCourse(1, 2);

    $learner = Learner::factory()->create(['active_course_id' => $active->id]);
    // Already seen (graduated, not due): skipped as "new".
    WordRepetition::factory()->create([
        'learner_id' => $learner->id, 'word_id' => $w11a->id,
        'state' => RepetitionState::Review->value, 'repetitions' => 1, 'next_review_at' => now()->addDays(3),
    ]);

    $scope = TrainingScope::forSession(app(TrainingSessionService::class)->openPersonal($learner, 'daily_review'));
    expect($scope->courseId)->toBe($active->id);

    $order = [];
    foreach (range(1, 3) as $_) {
        $p = pick($learner, $scope);
        expect($p->kind)->toBe('new');
        $order[] = (int) $p->word?->id;
        // Graduate it right away so it is not due again.
        $p->repetition?->update(['state' => RepetitionState::Review->value, 'repetitions' => 1, 'next_review_at' => now()->addDays(4)]);
    }

    expect($order)->toBe([$w11b->id, $w12->id, $w21->id])
        ->and(pick($learner, $scope)->doneReason)->toBe(DoneReason::NothingDue)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $inactive->id)->exists())->toBeFalse();
});

it('serves only due cards in a daily review without an active course', function (): void {
    publishedCourse(1, 3);
    $learner = Learner::factory()->create(['active_course_id' => null]);
    $due = dueRow($learner, Word::query()->firstOrFail());

    $scope = TrainingScope::forSession(app(TrainingSessionService::class)->openPersonal($learner, 'daily_review'));

    $first = pick($learner, $scope);
    expect($first->kind)->toBe('due')->and($first->word?->id)->toBe($due->word_id);
    $first->repetition?->update(['next_review_at' => now()->addDays(6)]);

    expect(pick($learner, $scope)->doneReason)->toBe(DoneReason::NothingDue)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('finds the next lesson with new words of a course for the learner', function (): void {
    $course = publishedCourse(3, 1);
    [$l1, $l2, $l3] = courseLessons($course)->all();
    $learner = Learner::factory()->create();
    $catalog = app(CourseCatalogService::class);

    expect($catalog->nextLessonWithNewWords($course, $learner)?->id)->toBe($l1->id);

    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $l1->words()->firstOrFail()->id]);
    expect($catalog->nextLessonWithNewWords($course, $learner)?->id)->toBe($l2->id);

    // An inactive word does not make a lesson "have new words".
    $l2->words()->update(['is_active' => false]);
    expect($catalog->nextLessonWithNewWords($course, $learner)?->id)->toBe($l3->id);

    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $l3->words()->firstOrFail()->id]);
    expect($catalog->nextLessonWithNewWords($course, $learner))->toBeNull();
});

// --- lesson scope: done reasons, budget, inactive words -----------------------------

it('reports lesson_complete once every word of the lesson is graduated', function (): void {
    $lesson = courseLessons(publishedCourse(1, 1))->first();
    $learner = Learner::factory()->create();
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson);
    $flow = app(TrainingFlowService::class);

    $step = $flow->nextStep($session, $learner);
    expect($step->kind)->toBe('new')->and($step->doneReason)->toBeNull();

    $outcome = $flow->review($session, $learner, (int) $step->word?->id, 5, 400);
    expect($outcome->next->word)->toBeNull()
        ->and($outcome->next->doneReason)->toBe(DoneReason::LessonComplete)
        ->and($outcome->next->progress)->toBe(['done' => 1, 'total' => 1]);
});

it('stops new cards when the budget is exhausted but keeps serving due cards', function (): void {
    $lesson = courseLessons(publishedCourse(1, 2))->first();
    $learner = Learner::factory()->create();
    $scope = TrainingScope::lesson((int) $lesson->id);

    // Budget 0 with unseen words: no row created, reason = quota.
    $blocked = pick($learner, $scope, NewCardBudget::of(0));
    expect($blocked->hasCard())->toBeFalse()
        ->and($blocked->doneReason)->toBe(DoneReason::NewQuotaExhausted)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(0);

    // Budget 1: exactly one new card.
    $one = pick($learner, $scope, NewCardBudget::of(1));
    expect($one->kind)->toBe('new');
    $one->repetition?->update(['state' => RepetitionState::Review->value, 'repetitions' => 1, 'next_review_at' => now()->subMinute()]);

    // Due cards are never limited: the graduated-and-due card is served under budget 0 ...
    $due = pick($learner, $scope, NewCardBudget::of(0));
    expect($due->kind)->toBe('due')->and($due->word?->id)->toBe($one->word?->id);
    $due->repetition?->update(['next_review_at' => now()->addDays(3)]);

    // ... and with one unseen word left the quota is the reason again.
    expect(pick($learner, $scope, NewCardBudget::of(0))->doneReason)->toBe(DoneReason::NewQuotaExhausted);

    // Unlimited (default) serves it; afterwards budget 0 is NOT the reason — the lesson is simply complete.
    $last = pick($learner, $scope);
    expect($last->kind)->toBe('new');
    $last->repetition?->update(['state' => RepetitionState::Review->value, 'repetitions' => 1, 'next_review_at' => now()->addDays(3)]);
    expect(pick($learner, $scope, NewCardBudget::of(0))->doneReason)->toBe(DoneReason::LessonComplete);
});

it('evaluates a lazy budget under the learner lock inside the new-card transaction', function (): void {
    $lesson = courseLessons(publishedCourse(1, 1))->first();
    $learner = Learner::factory()->create();
    $calls = [];
    $outerTx = DB::transactionLevel(); // the test wrapper's own transaction

    $budget = NewCardBudget::lazy(function (int $learnerId, CarbonImmutable $now) use (&$calls): ?int {
        $calls[] = ['learner' => $learnerId, 'tx' => DB::transactionLevel()];

        return 5;
    });

    $pick = pick($learner, TrainingScope::lesson((int) $lesson->id), $budget);

    expect($pick->kind)->toBe('new')
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['learner'])->toBe($learner->id)
        ->and($calls[0]['tx'])->toBe($outerTx + 1);

    // No unseen words → the budget is not even consulted.
    $pick->repetition?->update(['state' => RepetitionState::Review->value, 'repetitions' => 1, 'next_review_at' => now()->addDay()]);
    pick($learner, TrainingScope::lesson((int) $lesson->id), $budget);
    expect($calls)->toHaveCount(1);
});

it('lets a bound NewCardBudgetResolver (task 1.4b) drive done_reason through the flow', function (): void {
    $lesson = courseLessons(publishedCourse(1, 2))->first();
    $learner = Learner::factory()->create();
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson);

    $this->app->bind(NewCardBudgetResolver::class, fn () => new class implements NewCardBudgetResolver
    {
        public function budgetFor(Learner $learner, CarbonImmutable $now): NewCardBudget
        {
            return NewCardBudget::of(0);
        }
    });

    $step = app(TrainingFlowService::class)->nextStep($session, $learner);
    expect($step->word)->toBeNull()
        ->and($step->doneReason)->toBe(DoneReason::NewQuotaExhausted)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(0);

    // The HTTP layer keeps working (card: null) — `done_reason` in the payload is part C.
    $this->withHeaders(personalHeaders($learner))
        ->getJson("/api/twa/training/sessions/{$session->id}/next")
        ->assertOk()
        ->assertJsonPath('card', null);
});

it('reports session_cap when the per-session grade limit is reached', function (): void {
    config()->set('twa.training.max_cards_per_session', 1);
    $lesson = courseLessons(publishedCourse(1, 3))->first();
    $learner = Learner::factory()->create();
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson);
    $flow = app(TrainingFlowService::class);

    $step = $flow->nextStep($session, $learner);
    $outcome = $flow->review($session, $learner, (int) $step->word?->id, 4, 100);

    expect($outcome->next->word)->toBeNull()->and($outcome->next->doneReason)->toBe(DoneReason::SessionCap);
});

it('never serves inactive words — not as new, not as due, not ahead — and counts only active ones', function (): void {
    $lesson = courseLessons(publishedCourse(1, 2))->first();
    [$active, $retired] = $lesson->words()->orderBy('id')->get()->all();
    $learner = Learner::factory()->create();
    $scope = TrainingScope::lesson((int) $lesson->id);

    // Retired word: due and in a learning step within the learn-ahead window.
    dueRow($learner, $retired, hard: true);
    $retired->update(['is_active' => false]);
    app(LearningCache::class)->forgetLesson($lesson);

    $first = pick($learner, $scope);
    expect($first->kind)->toBe('new')->and($first->word?->id)->toBe($active->id);

    $first->repetition?->update(['state' => RepetitionState::Review->value, 'repetitions' => 1, 'next_review_at' => now()->addDay()]);
    WordRepetition::query()->where('word_id', $retired->id)->update([
        'state' => RepetitionState::Learning->value, 'next_review_at' => now()->addMinutes(5),
    ]);
    expect(pick($learner, $scope)->doneReason)->toBe(DoneReason::LessonComplete);

    expect(app(LearningCache::class)->lessonWordCount((int) $lesson->id))->toBe(1)
        ->and(app(CardPicker::class)->progress((int) $learner->id, (int) $lesson->id))->toBe(['done' => 1, 'total' => 1]);

    // Reminders / words_due_today ignore the retired word too.
    WordRepetition::query()->where('word_id', $retired->id)->update([
        'state' => RepetitionState::Review->value, 'next_review_at' => now()->subDay(),
    ]);
    expect(app(LearnerStatsService::class)->forLearner($learner)->wordsDueToday)->toBe(0);
    $retired->update(['is_active' => true]);
    expect(app(LearnerStatsService::class)->forLearner($learner)->wordsDueToday)->toBe(1);
});

// --- review: scope check and daily stats hook --------------------------------------

it('accepts grades for any seen word in a daily review but rejects inactive words as out_of_scope', function (): void {
    publishedCourse(2, 1);
    $learner = Learner::factory()->create(['active_course_id' => null]);
    $daily = app(TrainingSessionService::class)->openPersonal($learner, 'daily_review');
    [$wordA, $wordB] = Word::query()->orderBy('id')->get()->all();
    dueRow($learner, $wordA);
    dueRow($learner, $wordB);
    $wordB->update(['is_active' => false]);

    $this->withHeaders(personalHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$daily->id}/review", ['word_id' => $wordA->id, 'quality' => 4, 'time_spent_ms' => 100])
        ->assertOk();

    $this->withHeaders(personalHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$daily->id}/review", ['word_id' => $wordB->id, 'quality' => 4, 'time_spent_ms' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'out_of_scope');
});

it('rejects a word from another lesson in a personal lesson session with out_of_scope', function (): void {
    [$lessonA, $lessonB] = courseLessons(publishedCourse(2, 1))->all();
    $learner = Learner::factory()->create();
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lessonA);
    dueRow($learner, $lessonB->words()->firstOrFail());

    $this->withHeaders(personalHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/review", [
            'word_id' => $lessonB->words()->firstOrFail()->id, 'quality' => 4, 'time_spent_ms' => 100,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'out_of_scope');
});

it('records the day aggregate in the review transaction with isNewCard for the first grade only', function (): void {
    $lesson = courseLessons(publishedCourse(1, 1))->first();
    $learner = Learner::factory()->create();
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson);

    $calls = [];
    $outerTx = DB::transactionLevel();
    $this->mock(DailyStatsService::class, function ($mock) use (&$calls): void {
        $mock->shouldReceive('recordReview')
            ->twice()
            ->andReturnUsing(function (Learner $l, int $q, int $ms, bool $isNew, CarbonImmutable $now) use (&$calls): void {
                $calls[] = [
                    'learner' => $l->id, 'quality' => $q, 'ms' => $ms, 'new' => $isNew,
                    'tx' => DB::transactionLevel(),
                    'review_logged' => TrainingReview::query()->count(),
                ];
            });
    });

    $flow = app(TrainingFlowService::class);
    $word = $flow->nextStep($session, $learner)->word;
    $flow->review($session, $learner, (int) $word?->id, 2, 1500);                        // first grade: new card
    $token = $flow->nextStep($session, $learner)->serveToken;                            // learn-ahead re-serve
    $flow->review($session, $learner, (int) $word?->id, 4, 800, $token);                // second grade

    expect($calls)->toHaveCount(2)
        ->and($calls[0])->toMatchArray(['learner' => $learner->id, 'quality' => 2, 'ms' => 1500, 'new' => true, 'tx' => $outerTx + 1, 'review_logged' => 1])
        ->and($calls[1])->toMatchArray(['quality' => 4, 'ms' => 800, 'new' => false, 'tx' => $outerTx + 1, 'review_logged' => 2]);
});

it('rolls the grade back when the day aggregate cannot be written', function (): void {
    $lesson = courseLessons(publishedCourse(1, 1))->first();
    $learner = Learner::factory()->create();
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson);
    $flow = app(TrainingFlowService::class);
    $word = $flow->nextStep($session, $learner)->word;

    $this->mock(DailyStatsService::class, function ($mock): void {
        $mock->shouldReceive('recordReview')->once()->andThrow(new RuntimeException('stats down'));
    });

    expect(fn () => app(TrainingFlowService::class)->review($session, $learner, (int) $word?->id, 5, 100))
        ->toThrow(RuntimeException::class);

    $row = WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $word?->id)->sole();
    expect($row->state)->toBe(RepetitionState::New)
        ->and($row->repetitions)->toBe(0)
        ->and(TrainingReview::query()->count())->toBe(0);
});

// --- HTTP shape of a daily review + group regression --------------------------------

it('starts a daily review over HTTP with lesson: null and the daily goal as total', function (): void {
    $course = publishedCourse(1, 2);
    $learner = Learner::factory()->create(['active_course_id' => $course->id, 'daily_goal' => 10]);
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'daily_review');

    $this->withHeaders(personalHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/start")
        ->assertOk()
        ->assertJsonPath('session_id', $session->id)
        ->assertJsonPath('lesson', null)
        ->assertJsonPath('total_words', 10)
        ->assertJsonPath('card.card_kind', 'new')
        ->assertJsonPath('progress', ['done' => 0, 'total' => 10]);

    $stranger = Learner::factory()->create();
    $this->withHeaders(personalHeaders($stranger))
        ->getJson("/api/twa/training/sessions/{$session->id}/next")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('keeps group sessions on the lesson scope: teacher open is idempotent and cards stay inside the lesson', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create();
    DB::table('teacher_groups')->insert(['user_id' => $teacher->id, 'telegram_group_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);
    [$lessonA, $lessonB] = courseLessons(publishedCourse(2, 1))->all();
    $learner = Learner::factory()->memberOf($group)->create();
    $service = app(TrainingSessionService::class);

    $session = $service->openForLesson($group, $lessonA, $teacher);
    expect($service->openForLesson($group, $lessonA, $teacher)->id)->toBe($session->id)
        ->and($session->mode)->toBe('group_lesson')
        ->and($session->expires_at)->toBeNull()
        ->and($session->learner_id)->toBeNull();

    // A due card from lesson B is not served in the group session of lesson A.
    dueRow($learner, $lessonB->words()->firstOrFail());
    $step = app(TrainingFlowService::class)->nextStep($session, $learner);
    expect($step->kind)->toBe('new')->and($step->word?->lesson_id)->toBe($lessonA->id);

    // Group start response still carries the lesson block.
    $this->withHeaders(personalHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/start")
        ->assertOk()
        ->assertJsonPath('lesson.lesson', $lessonA->number)
        ->assertJsonPath('total_words', 1);
});
