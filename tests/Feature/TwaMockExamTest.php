<?php

declare(strict_types=1);

/*
 * Personal IELTS / SAT mocks (task 2D.4, docs/13 §8.4, docs/04 A.4a): opening (Pro, exam
 * courses only, one open per learner), playing through the exam routes under the owner
 * policy, the single timer, lazy and scheduled finalization, percent + percentile.
 */

use App\Console\Commands\CloseExpiredExams;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learning\Exceptions\MockExamException;
use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Domain\Learning\Services\MockExamService;
use App\Domain\Twa\Services\JwtService;
use App\Jobs\PostLeaderboardJob;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Policies\ExamSessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-10-20 10:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array<string, string> */
function mkHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function mkLearner(bool $pro = true): Learner
{
    $learner = Learner::factory()->onboarded()->create();
    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id]);
        app(EntitlementResolver::class)->forget($learner);
    }

    return $learner;
}

/** A published platform course tagged `$tag` with `$words` active words (unique translations) in one lesson. */
function mkCourse(int $words = 12, ?string $tag = 'ielts', int $inactive = 0): Course
{
    $course = Course::factory()->published()->create(['exam_tag' => $tag]);
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->for($stage)->create();

    static $n = 0;
    foreach (range(1, $words) as $i) {
        $n++;
        Word::factory()->for($lesson)->create(['word' => "w{$n}", 'translation' => "перевод {$n}", 'is_active' => true]);
    }
    foreach (range(1, $inactive) as $i) {
        $n++;
        Word::factory()->for($lesson)->create(['word' => "off{$n}", 'translation' => "выключено {$n}", 'is_active' => false]);
    }

    return $course;
}

function mkOpen(object $test, Learner $learner, Course $course): TestResponse
{
    return $test->withHeaders(mkHeaders($learner))->postJson("/api/twa/courses/{$course->slug}/mock-exam");
}

/** Answers every question of the learner's mock; `$correctEvery` → every n-th question gets the right option. */
function mkPlay(object $test, Learner $learner, ExamSession $session, int $correctEvery = 1): int
{
    $personalizer = app(ExamQuestionPersonalizer::class);
    $total = $personalizer->count($session);
    $correct = 0;

    foreach (range(0, $total - 1) as $i) {
        $q = $test->withHeaders(mkHeaders($learner))
            ->getJson("/api/twa/exam/sessions/{$session->id}/question?index={$i}")
            ->assertOk()
            ->json();
        $view = $personalizer->questionAt($session, $learner, $i);
        $right = $correctEvery > 0 && $i % $correctEvery === 0;
        $pick = $right ? $view['correct_index'] : ($view['correct_index'] + 1) % count($view['options']);
        $correct += $right ? 1 : 0;

        $test->withHeaders(mkHeaders($learner))
            ->postJson("/api/twa/exam/sessions/{$session->id}/answer", [
                'question_index' => $i,
                'word_id' => $q['word_id'],
                'selected_option_index' => $pick,
                'time_spent_ms' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('is_correct', $right);
    }

    return $correct;
}

/** A closed mock of `$course` with `$correct` of `$total` right, closed `$daysAgo` days ago (bypasses the service). */
function mkClosedMock(Course $course, int $correct, int $total, int $daysAgo = 1): ExamSession
{
    $session = ExamSession::factory()->personalMock(null, $course)->create([
        'status' => 'closed',
        'started_at' => CarbonImmutable::now()->subDays($daysAgo)->subMinutes(20),
        'ends_at' => CarbonImmutable::now()->subDays($daysAgo),
        'closed_at' => CarbonImmutable::now()->subDays($daysAgo),
    ]);
    ExamResult::query()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $session->learner_id,
        'total_score' => $correct * 10,
        'correct_count' => $correct,
        'total_count' => $total,
        'rank' => 1,
    ]);

    return $session;
}

// --- opening ------------------------------------------------------------------

it('refuses a Free learner with the paywall reason', function (): void {
    $course = mkCourse();

    mkOpen($this, mkLearner(pro: false), $course)
        ->assertStatus(403)
        ->assertJsonPath('error.code', MockExamException::PRO_REQUIRED);

    expect(ExamSession::query()->count())->toBe(0);
});

it('refuses a course without an IELTS / SAT tag', function (?string $tag): void {
    mkOpen($this, mkLearner(), mkCourse(tag: $tag))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'course_not_exam');
})->with([null, 'general', 'school']);

it('answers 404 for an unknown or unpublished course', function (): void {
    $learner = mkLearner();
    $draft = Course::factory()->create(['exam_tag' => 'ielts']);

    $this->withHeaders(mkHeaders($learner))->postJson('/api/twa/courses/nope/mock-exam')->assertNotFound();
    mkOpen($this, $learner, $draft)->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
});

it('refuses a course with too few active words', function (): void {
    mkOpen($this, mkLearner(), mkCourse(words: 3, inactive: 5))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_enough_words');
});

it('opens a personal mock for a Pro learner: owner, course, 20-minute timer, seeded words, event', function (): void {
    config(['learning.mock_exam.questions' => 8]);
    $learner = mkLearner();
    $course = mkCourse(words: 12, tag: 'sat', inactive: 3);

    $response = mkOpen($this, $learner, $course)
        ->assertCreated()
        ->assertExactJsonStructure(['session_id', 'mode', 'course_id', 'started_at', 'ends_at', 'total_questions', 'seconds', 'resumed'])
        ->assertJsonPath('mode', 'personal_mock')
        ->assertJsonPath('total_questions', 8)
        ->assertJsonPath('seconds', 1200)
        ->assertJsonPath('resumed', false)
        ->assertJsonPath('course_id', $course->id);

    expect($response->getContent())->not->toContain('order_secret')->not->toContain('correct_translation');

    $session = ExamSession::query()->findOrFail($response->json('session_id'));
    expect($session->mode)->toBe('personal_mock')
        ->and($session->learner_id)->toBe($learner->id)
        ->and($session->course_id)->toBe($course->id)
        ->and($session->telegram_group_id)->toBeNull()
        ->and($session->lesson_id)->toBeNull()
        ->and($session->started_by_user_id)->toBeNull()
        ->and($session->status)->toBe('open')
        ->and($session->ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addSeconds(1200)->toIso8601String())
        ->and($session->config['seconds_per_question'])->toBe(1200)
        ->and($session->config['total_questions'])->toBe(8)
        ->and($session->config['question_order'])->toBe(ExamQuestionPersonalizer::ORDER_PER_LEARNER)
        ->and(strlen((string) $session->config['order_secret']))->toBe(64)
        ->and($session->config['questions'])->toHaveCount(8);

    // The words are the first 8 of the seeded order over the ACTIVE words of the course.
    $activeIds = Word::query()->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    $expected = array_slice(MockExamService::seededOrder((int) $session->id, $activeIds), 0, 8);
    expect(array_column($session->config['questions'], 'word_id'))->toBe($expected);

    foreach ($session->config['questions'] as $q) {
        expect($q['options'])->toContain($q['correct_translation'])
            ->and(count($q['options']))->toBe(4)
            ->and($q)->not->toHaveKey('correct_index');
        foreach ($q['options'] as $option) {
            expect($option)->not->toStartWith('выключено');
        }
    }

    $events = ProductEvent::query()->where('name', 'exam.mock_started')->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->learner_id)->toBe($learner->id)
        ->and($events[0]->props['course_id'])->toBe($course->id)
        ->and($events[0]->props['exam_tag'])->toBe('sat')
        ->and($events[0]->props['questions'])->toBe(8);
});

it('caps the questions at the active words of the course', function (): void {
    $response = mkOpen($this, mkLearner(), mkCourse(words: 6))->assertCreated();

    expect($response->json('total_questions'))->toBe(6);
});

it('is deterministic: the seeded order depends on the session id only', function (): void {
    $ids = range(100, 130);
    $a = MockExamService::seededOrder(7, $ids);
    $b = MockExamService::seededOrder(7, array_reverse($ids));
    $c = MockExamService::seededOrder(8, $ids);

    expect($a)->toBe($b)
        ->and(sort($a) ? $a : [])->toBe($ids)
        ->and($c)->not->toBe($b);
});

it('returns the open mock on a repeated open, also for another course', function (): void {
    $learner = mkLearner();
    $course = mkCourse();
    $other = mkCourse(tag: 'sat');

    $first = mkOpen($this, $learner, $course)->assertCreated()->json('session_id');

    mkOpen($this, $learner, $course)->assertOk()->assertJsonPath('session_id', $first)->assertJsonPath('resumed', true);
    mkOpen($this, $learner, $other)->assertOk()->assertJsonPath('session_id', $first);

    expect(ExamSession::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'exam.mock_started')->count())->toBe(1);
});

it('finalizes an expired open mock and opens a fresh one', function (): void {
    $learner = mkLearner();
    $course = mkCourse();

    $stale = ExamSession::query()->findOrFail(mkOpen($this, $learner, $course)->json('session_id'));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(21));

    $fresh = mkOpen($this, $learner, $course)->assertCreated()->json('session_id');

    expect($fresh)->not->toBe($stale->id)
        ->and($stale->fresh()?->status)->toBe('closed')
        ->and(ExamSession::query()->where('status', 'open')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'exam.mock_finished')->value('props'))
        ->toMatchArray(['exam_session_id' => $stale->id, 'reason' => 'expired', 'answered' => 0]);
});

it('does not let a Free learner into an exam course through the service either', function (): void {
    expect(fn () => app(MockExamService::class)->open(mkLearner(pro: false), mkCourse()))
        ->toThrow(MockExamException::class);
});

// --- access (owner policy) ------------------------------------------------------

it('lets only the owner join, answer and read a personal mock', function (): void {
    $owner = mkLearner();
    $course = mkCourse();
    $session = ExamSession::query()->findOrFail(mkOpen($this, $owner, $course)->json('session_id'));

    $stranger = mkLearner();
    $member = Learner::factory()->memberOf(TelegramGroup::factory()->create(['status' => 'active']))->create();

    foreach ([$stranger, $member] as $other) {
        $this->withHeaders(mkHeaders($other))->postJson("/api/twa/exam/sessions/{$session->id}/join")
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->withHeaders(mkHeaders($other))->getJson("/api/twa/exam/sessions/{$session->id}/question?index=0")
            ->assertForbidden();
        $this->withHeaders(mkHeaders($other))->getJson("/api/twa/exam/sessions/{$session->id}/result")
            ->assertForbidden();
    }

    $this->withHeaders(mkHeaders($owner))->postJson("/api/twa/exam/sessions/{$session->id}/join")
        ->assertOk()
        ->assertJsonPath('total_questions', 12)
        ->assertJsonPath('seconds_per_question', 1200);

    $policy = app(ExamSessionPolicy::class);
    expect($policy->denial($owner, $session))->toBeNull()
        ->and($policy->denial($stranger, $session))->toBe('forbidden')
        ->and($policy->canParticipate($owner, $session))->toBeTrue()
        ->and($policy->canViewResult($owner, $session))->toBeFalse();

    $banned = $owner;
    $banned->update(['blocked_at' => now()]);
    expect($policy->denial($banned->fresh() ?? $banned, $session))->toBe('forbidden');
});

it('keeps the group-exam rules for group sessions (policy unchanged)', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $exam = ExamSession::factory()->create(['telegram_group_id' => $group->id]);
    $member = Learner::factory()->memberOf($group)->create();
    $outsider = Learner::factory()->create();

    $policy = app(ExamSessionPolicy::class);
    expect($exam->mode)->toBe('group_exam')
        ->and($policy->denial($member, $exam))->toBeNull()
        ->and($policy->denial($outsider, $exam))->toBe('not_a_member');
});

// --- playing, timer, finalization ----------------------------------------------

it('finalizes on /result once every question is answered and reports percent + percentile', function (): void {
    config(['learning.mock_exam.questions' => 10]);
    $learner = mkLearner();
    $course = mkCourse(words: 10);
    $session = ExamSession::query()->findOrFail(mkOpen($this, $learner, $course)->json('session_id'));

    // Result while open and unanswered: still open.
    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")
        ->assertStatus(409)->assertJsonPath('error.code', 'exam_still_open');

    $correct = mkPlay($this, $learner, $session, correctEvery: 2); // 5 of 10

    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")
        ->assertOk()
        ->assertExactJsonStructure(['mode', 'correct', 'total', 'answered', 'percent', 'percentile', 'attempts', 'course_id'])
        ->assertJsonPath('mode', 'personal_mock')
        ->assertJsonPath('correct', $correct)
        ->assertJsonPath('total', 10)
        ->assertJsonPath('answered', 10)
        ->assertJsonPath('percent', 50)
        ->assertJsonPath('percentile', 100)
        ->assertJsonPath('attempts', 1);

    $closed = $session->fresh();
    expect($closed?->status)->toBe('closed')
        ->and($closed?->closed_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());

    $result = ExamResult::query()->where('exam_session_id', $session->id)->get();
    expect($result)->toHaveCount(1)
        ->and($result[0]->correct_count)->toBe(5)
        ->and($result[0]->total_count)->toBe(10)
        ->and($result[0]->learner_id)->toBe($learner->id);

    // Idempotent: a second read changes nothing and no second event is written.
    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")
        ->assertOk()->assertJsonPath('percent', 50);

    $finished = ProductEvent::query()->where('name', 'exam.mock_finished')->get();
    expect($finished)->toHaveCount(1)
        ->and($finished[0]->props)->toMatchArray(['exam_session_id' => $session->id, 'percent' => 50, 'percentile' => 100, 'answered' => 10, 'reason' => 'finished']);

    // Answers after the close are refused.
    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/question?index=0")
        ->assertStatus(410);
});

it('stops accepting answers when the time is over and finalizes with the unanswered questions as wrong', function (): void {
    config(['learning.mock_exam.questions' => 8]);
    $learner = mkLearner();
    $session = ExamSession::query()->findOrFail(mkOpen($this, $learner, mkCourse(words: 8))->json('session_id'));

    $personalizer = app(ExamQuestionPersonalizer::class);
    foreach ([0, 1] as $i) {
        $q = $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/question?index={$i}")->json();
        $this->withHeaders(mkHeaders($learner))->postJson("/api/twa/exam/sessions/{$session->id}/answer", [
            'question_index' => $i,
            'word_id' => $q['word_id'],
            'selected_option_index' => $personalizer->questionAt($session, $learner, $i)['correct_index'],
            'time_spent_ms' => 100,
        ])->assertOk()->assertJsonPath('is_correct', true);
    }

    // Serve question 2 within the time, answer it after the deadline: refused, not graded.
    $q2 = $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/question?index=2")->assertOk()->json();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1201));

    $this->withHeaders(mkHeaders($learner))->postJson("/api/twa/exam/sessions/{$session->id}/answer", [
        'question_index' => 2,
        'word_id' => $q2['word_id'],
        'selected_option_index' => 0,
        'time_spent_ms' => 100,
    ])->assertStatus(410)->assertJsonPath('error.code', 'session_closed');

    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/question?index=3")
        ->assertStatus(410);

    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")
        ->assertOk()
        ->assertJsonPath('correct', 2)
        ->assertJsonPath('answered', 2)
        ->assertJsonPath('total', 8)
        ->assertJsonPath('percent', 25);

    expect($session->fresh()?->status)->toBe('closed')
        ->and(ExamAnswer::query()->where('exam_session_id', $session->id)->count())->toBe(2)
        ->and(ProductEvent::query()->where('name', 'exam.mock_finished')->value('props'))
        ->toMatchArray(['reason' => 'expired', 'answered' => 2, 'percent' => 25]);
});

it('is finalized by exams:close-expired without a leaderboard job, next to the group exams', function (): void {
    Bus::fake([PostLeaderboardJob::class]);
    $learner = mkLearner();
    $session = ExamSession::query()->findOrFail(mkOpen($this, $learner, mkCourse(words: 6))->json('session_id'));
    mkPlay($this, $learner, $session, correctEvery: 3); // 2 of 6 right, but keep the mock open: answer only part

    $running = ExamSession::query()->findOrFail(mkOpen($this, mkLearner(), mkCourse(words: 6))->json('session_id'));
    $group = ExamSession::factory()->withQuestions(4)->create(['ends_at' => CarbonImmutable::now()->subMinutes(2)]);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(25));
    $running->update(['ends_at' => CarbonImmutable::now()->addMinutes(5)]);

    $this->artisan(CloseExpiredExams::class)->assertSuccessful();

    expect($session->fresh()?->status)->toBe('closed')
        ->and($running->fresh()?->status)->toBe('open')
        ->and($group->fresh()?->status)->toBe('closed')
        ->and(ExamResult::query()->where('exam_session_id', $session->id)->value('correct_count'))->toBe(2)
        ->and(ProductEvent::query()->where('name', 'exam.mock_finished')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'exam.closed')->count())->toBe(1);

    Bus::assertDispatchedTimes(PostLeaderboardJob::class, 1);
    Bus::assertDispatched(PostLeaderboardJob::class, fn (PostLeaderboardJob $job): bool => $job->examSessionId === $group->id);
});

it('closes a mock nobody answered without an exam_results row', function (): void {
    $learner = mkLearner();
    $session = ExamSession::query()->findOrFail(mkOpen($this, $learner, mkCourse())->json('session_id'));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(21));
    $closed = app(MockExamService::class)->finalizeIfDue($session->fresh() ?? $session);

    expect($closed->status)->toBe('closed')
        ->and(ExamResult::query()->where('exam_session_id', $session->id)->exists())->toBeFalse();

    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")
        ->assertOk()
        ->assertJsonPath('percent', 0)
        ->assertJsonPath('answered', 0)
        ->assertJsonPath('percentile', 100)
        ->assertJsonPath('attempts', 1);
});

it('leaves a running, partly answered mock open on /result and on the scheduler', function (): void {
    $learner = mkLearner();
    $session = ExamSession::query()->findOrFail(mkOpen($this, $learner, mkCourse(words: 6))->json('session_id'));
    $q = $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/question?index=0")->json();
    $this->withHeaders(mkHeaders($learner))->postJson("/api/twa/exam/sessions/{$session->id}/answer", [
        'question_index' => 0, 'word_id' => $q['word_id'], 'selected_option_index' => 0, 'time_spent_ms' => 100,
    ])->assertOk();

    $this->withHeaders(mkHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertStatus(409);
    $this->artisan(CloseExpiredExams::class)->assertSuccessful();

    expect($session->fresh()?->status)->toBe('open');
});

// --- percentile ---------------------------------------------------------------------

it('computes the percentile over the closed mocks of the course within 90 days, deterministically', function (): void {
    $course = mkCourse(words: 10);
    $otherCourse = mkCourse(words: 10, tag: 'sat');

    foreach ([2, 4, 4, 6, 8] as $correct) {
        mkClosedMock($course, $correct, 10);
    }
    mkClosedMock($course, 10, 10, daysAgo: 100); // outside the window
    mkClosedMock($otherCourse, 10, 10);           // another course

    $service = app(MockExamService::class);

    $mine = mkClosedMock($course, 4, 10);
    $result = $service->result($mine);
    // Distribution with mine: 20, 40, 40, 40, 60, 80 -> at or below 40: 4 of 6.
    expect($result->percent)->toBe(40)
        ->and($result->attempts)->toBe(6)
        ->and($result->percentile)->toBe(67);

    $top = mkClosedMock($course, 10, 10);
    Cache::flush();
    $topResult = $service->result($top);
    expect($topResult->percent)->toBe(100)
        ->and($topResult->attempts)->toBe(7)
        ->and($topResult->percentile)->toBe(100);

    $low = mkClosedMock($course, 0, 10);
    Cache::flush();
    $lowResult = $service->result($low);
    expect($lowResult->percent)->toBe(0)
        ->and($lowResult->attempts)->toBe(8)
        ->and($lowResult->percentile)->toBe(13);

    $alone = mkClosedMock($otherCourse, 3, 10);
    Cache::flush();
    expect($service->result($alone)->percentile)->toBe(50)
        ->and($service->result($alone)->attempts)->toBe(2);
});

it('caches the distribution for an hour and drops it when a mock of the course is finalized', function (): void {
    $course = mkCourse(words: 10);
    $service = app(MockExamService::class);

    $mine = mkClosedMock($course, 5, 10);
    expect($service->result($mine)->attempts)->toBe(1);

    // A row added behind the cache's back is not seen...
    mkClosedMock($course, 9, 10);
    expect($service->result($mine)->attempts)->toBe(1);

    // ...until a finalize of the course drops the cache.
    $open = ExamSession::factory()->personalMock(null, $course)->create();
    $service->finalize($open, 'expired');
    expect($service->result($mine)->attempts)->toBe(2);

    // The distribution lives under one key per course with the configured TTL (an hour); once it is
    // gone, the next read sees the newer rows. (Only the array store honours Carbon's test clock for
    // expiry, so the key is dropped by hand here instead of travelling in time.)
    mkClosedMock($course, 1, 10);
    expect($service->result($mine)->attempts)->toBe(2)
        ->and(Cache::has(MockExamService::distributionCacheKey((int) $course->id)))->toBeTrue();
    Cache::forget(MockExamService::distributionCacheKey((int) $course->id));
    expect($service->result($mine)->attempts)->toBe(3);
});

it('answers null percentile while the mock is still open', function (): void {
    $session = ExamSession::factory()->personalMock()->create();

    $result = app(MockExamService::class)->result($session);
    expect($result->percentile)->toBeNull()
        ->and($result->attempts)->toBe(0)
        ->and($result->percent)->toBe(0);
});

it('finalize is idempotent and ignores group exams', function (): void {
    $service = app(MockExamService::class);
    $mock = ExamSession::factory()->personalMock()->withQuestions(4)->create();
    $group = ExamSession::factory()->create();

    $first = $service->finalize($mock, 'finished');
    $second = $service->finalize($mock, 'finished');
    expect($first->status)->toBe('closed')
        ->and($second->closed_at?->toIso8601String())->toBe($first->closed_at?->toIso8601String())
        ->and(ProductEvent::query()->where('name', 'exam.mock_finished')->count())->toBe(1)
        ->and($service->finalize($group, 'expired')->status)->toBe('open')
        ->and($service->finalizeIfDue($group)->status)->toBe('open');
});
