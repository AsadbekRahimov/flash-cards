<?php

declare(strict_types=1);

/*
 * The quiz of the week (docs/13 §8.5, docs/04 A.20): opening, the counted attempt of the ISO week in the learner's zone, play through
 * the ordinary exam routes, lazy and scheduled finalization, and the promise that it is a measurement - no schedule, no daily stats.
 */

use App\Console\Commands\CloseExpiredExams;
use App\Domain\Content\Support\TranslationParts;
use App\Domain\Learning\Exceptions\LevelQuizException;
use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Domain\Learning\Services\LevelQuizService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LevelQuizResult;
use App\Models\ProductEvent;
use App\Policies\ExamSessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The shipped values, whatever a local .env says.
    config([
        'readiness.enabled' => true,
        'readiness.quiz.enabled' => true,
        'readiness.quiz.questions' => 12,
        'readiness.quiz.seconds' => 600,
        'readiness.quiz.met_share' => 0.67,
        'readiness.quiz.history_weeks' => 12,
        'learners.default_timezone' => 'Asia/Tashkent',
    ]);
    CarbonImmutable::setTestNow('2026-11-17 10:00:00'); // Tuesday; Monday of the week is 2026-11-16 (Tashkent)
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array<string, string> */
function levelQuizHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A course of `$total` active words in one lesson, the first `$met` of them met by a Free learner.
 *
 * @return array{course: Course, learner: Learner, met: list<int>, unmet: list<int>}
 */
function levelQuizWorld(int $total = 50, int $met = 20, string $timezone = 'Asia/Tashkent'): array
{
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => $total]]);
    $ids = $words['1.1']['active'];
    $learner = Learner::factory()->onboarded()->create(['timezone' => $timezone]);
    $metIds = array_slice($ids, 0, $met);
    Fx::learn($learner, $metIds);

    return ['course' => $course, 'learner' => $learner, 'met' => $metIds, 'unmet' => array_slice($ids, $met)];
}

function levelQuizOpen(object $test, Learner $learner, object $course): TestResponse
{
    return $test->withHeaders(levelQuizHeaders($learner))->postJson("/api/twa/courses/{$course->slug}/level-quiz");
}

/** Answers `$count` questions of the learner's quiz (all when null); every `$correctEvery`-th answer is right. @return int the right ones */
function levelQuizPlay(object $test, Learner $learner, ExamSession $session, int $correctEvery = 1, ?int $count = null): int
{
    $personalizer = app(ExamQuestionPersonalizer::class);
    $total = $count ?? $personalizer->count($session);
    $correct = 0;

    foreach (range(0, $total - 1) as $i) {
        $q = $test->withHeaders(levelQuizHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/question?index={$i}")->assertOk()->json();
        $view = $personalizer->questionAt($session, $learner, $i);
        $right = $correctEvery > 0 && $i % $correctEvery === 0;
        $correct += $right ? 1 : 0;

        $test->withHeaders(levelQuizHeaders($learner))->postJson("/api/twa/exam/sessions/{$session->id}/answer", [
            'question_index' => $i,
            'word_id' => $q['word_id'],
            'selected_option_index' => $right ? $view['correct_index'] : ($view['correct_index'] + 1) % count($view['options']),
            'time_spent_ms' => 1000,
        ])->assertOk()->assertJsonPath('is_correct', $right);
    }

    return $correct;
}

function levelQuizSession(TestResponse $response): ExamSession
{
    return ExamSession::query()->findOrFail($response->json('session_id'));
}

/** @return list<int> */
function levelQuizWordIds(ExamSession $session): array
{
    return array_map(fn (array $q): int => (int) $q['word_id'], $session->config['questions']);
}

// --- opening ------------------------------------------------------------------

it('opens a quiz for a Free learner: owner, course, week, one timer, the key stays on the server, the event', function (): void {
    ['course' => $course, 'learner' => $learner, 'met' => $met] = levelQuizWorld();

    $response = levelQuizOpen($this, $learner, $course)->assertCreated()
        ->assertJsonPath('mode', 'level_quiz')
        ->assertJsonPath('course_id', $course->id)
        ->assertJsonPath('week_start', '2026-11-16')
        ->assertJsonPath('scored', true)
        ->assertJsonPath('total_questions', 12)
        ->assertJsonPath('seconds', 600)
        ->assertJsonPath('resumed', false)
        ->assertJsonMissingPath('config')
        ->assertJsonMissing(['order_secret'])
        ->assertJsonMissing(['correct_translation'])
        ->assertJsonMissing(['questions']);

    $session = levelQuizSession($response);
    $ids = levelQuizWordIds($session);

    expect($session->mode)->toBe('level_quiz')
        ->and($session->learner_id)->toBe($learner->id)
        ->and($session->telegram_group_id)->toBeNull()
        ->and($session->ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addSeconds(600)->toIso8601String())
        ->and($session->config['seconds_per_question'])->toBe(600)
        ->and($session->config['week_start'])->toBe('2026-11-16')
        ->and($session->config['scored'])->toBeTrue()
        // 20 met, 30 not met words: about two thirds of the questions come from the met ones (8 + 4)
        ->and(count(array_intersect($ids, $met)))->toBe(8)
        ->and(array_unique($ids))->toHaveCount(12)
        ->and($session->config['met_count'])->toBe(8)
        ->and($session->config['unmet_count'])->toBe(4);

    $event = ProductEvent::query()->where('name', 'level_quiz.started')->sole();
    expect($event->learner_id)->toBe($learner->id)
        ->and($event->props)->toMatchArray(['exam_session_id' => $session->id, 'course_id' => $course->id, 'week_start' => '2026-11-16', 'scored' => true, 'questions' => 12, 'met' => 8, 'unmet' => 4]);
});

it('draws only unmet words for a newcomer and only met ones for a learner who finished the course', function (int $met, int $expectedMet): void {
    ['course' => $course, 'learner' => $learner, 'met' => $metIds] = levelQuizWorld(20, $met);

    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());

    expect(count(array_intersect(levelQuizWordIds($session), $metIds)))->toBe($expectedMet)
        ->and($session->config['total_questions'])->toBe(12);
})->with([
    'newcomer' => [0, 0],
    'course finished' => [20, 12],
]);

it('asks as many questions as the course has words below the quiz size, and refuses a course of fewer than four', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld(5, 2);
    levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('total_questions', 5);

    ['course' => $tiny, 'learner' => $other] = levelQuizWorld(3, 0);
    levelQuizOpen($this, $other, $tiny)->assertStatus(422)->assertJsonPath('error.code', 'not_enough_words');
    expect(ExamSession::query()->where('learner_id', $other->id)->count())->toBe(0);
});

it('answers 404 for an unknown or unpublished course', function (): void {
    ['learner' => $learner] = levelQuizWorld(6, 0);
    $this->withHeaders(levelQuizHeaders($learner))->postJson('/api/twa/courses/no-such-course/level-quiz')
        ->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
});

it('gives back the running quiz on a repeated open', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $first = levelQuizOpen($this, $learner, $course)->assertCreated();
    $again = levelQuizOpen($this, $learner, $course)->assertOk()->assertJsonPath('resumed', true);

    expect($again->json('session_id'))->toBe($first->json('session_id'))
        ->and(ExamSession::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'level_quiz.started')->count())->toBe(1);
});

it('is switched off by either setting: 404 level_quiz_disabled', function (string $key): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    config([$key => false]);

    levelQuizOpen($this, $learner, $course)->assertNotFound()->assertJsonPath('error.code', 'level_quiz_disabled');
    expect(ExamSession::query()->count())->toBe(0)
        ->and(fn () => app(LevelQuizService::class)->open($learner, $course))->toThrow(LevelQuizException::class);
})->with(['readiness.enabled', 'readiness.quiz.enabled']);

it('lets only the owner join, answer and read the result of a quiz', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    $stranger = Learner::factory()->onboarded()->create();
    $policy = app(ExamSessionPolicy::class);

    expect($policy->denial($learner, $session))->toBeNull()
        ->and($policy->denial($stranger, $session))->toBe('forbidden');

    $h = levelQuizHeaders($stranger);
    $this->withHeaders($h)->postJson("/api/twa/exam/sessions/{$session->id}/join")->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$session->id}/question?index=0")->assertForbidden();
    $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertForbidden();
    $this->withHeaders(levelQuizHeaders($learner))->postJson("/api/twa/exam/sessions/{$session->id}/join")->assertOk()
        ->assertJsonPath('seconds_per_question', 600);
});

// --- play and result ----------------------------------------------------------

it('plays a quiz to the end: the server checks the answers, /result closes it and records ONE counted result and ONE event', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    $h = levelQuizHeaders($learner);

    // Before the answer the question carries no key.
    $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$session->id}/question?index=0")->assertOk()
        ->assertJsonMissing(['correct_index'])->assertJsonMissing(['is_correct']);
    $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertStatus(409)->assertJsonPath('error.code', 'exam_still_open');

    $correct = levelQuizPlay($this, $learner, $session, 2); // 6 of 12

    $result = $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertOk()
        ->assertJsonPath('mode', 'level_quiz')
        ->assertJsonPath('correct', $correct)
        ->assertJsonPath('total', 12)
        ->assertJsonPath('answered', 12)
        ->assertJsonPath('percent', 50)
        ->assertJsonPath('scored', true)
        ->assertJsonPath('week_start', '2026-11-16')
        ->assertJsonPath('course_id', $course->id)
        ->assertJsonPath('course_slug', $course->slug);
    expect(array_keys($result->json()))->toBe(['mode', 'correct', 'total', 'answered', 'percent', 'scored', 'week_start', 'course_id', 'course_slug']);

    // A second read is the same and writes nothing more.
    $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertOk()->assertJsonPath('correct', $correct);

    $row = LevelQuizResult::query()->where('learner_id', $learner->id)->sole();
    expect($session->refresh()->status)->toBe('closed')
        ->and($row->scored)->toBeTrue()
        ->and([$row->correct, $row->total, $row->week_start->toDateString(), $row->course_id, $row->exam_session_id])->toBe([6, 12, '2026-11-16', $course->id, $session->id])
        ->and(ProductEvent::query()->where('name', 'level_quiz.finished')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'level_quiz.finished')->sole()->props)
        ->toMatchArray(['scored' => true, 'correct' => 6, 'total' => 12, 'percent' => 50, 'answered' => 12, 'reason' => 'finished', 'week_start' => '2026-11-16']);
});

it('takes the percent as a floor and counts the unanswered questions as wrong', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    levelQuizPlay($this, $learner, $session, 1, 5); // 5 right of 5 answered

    CarbonImmutable::setTestNow('2026-11-17 10:11:00'); // the time is over
    $this->withHeaders(levelQuizHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertOk()
        ->assertJsonPath('correct', 5)->assertJsonPath('answered', 5)->assertJsonPath('total', 12)
        ->assertJsonPath('percent', 41); // 100 * 5 / 12 = 41.67 -> 41, never 42

    expect(LevelQuizService::percent(995, 1000))->toBe(99)
        ->and(LevelQuizService::percent(1, 3))->toBe(33)
        ->and(LevelQuizService::percent(0, 0))->toBe(0)
        ->and(LevelQuizService::percent(13, 12))->toBe(100);
});

it('refuses a second answer to a question, a wrong word and an answer after the time', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    $h = levelQuizHeaders($learner);
    $q = $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$session->id}/question?index=0")->assertOk()->json();
    $body = ['question_index' => 0, 'word_id' => $q['word_id'], 'selected_option_index' => 0, 'time_spent_ms' => 500];

    $this->withHeaders($h)->postJson("/api/twa/exam/sessions/{$session->id}/answer", [...$body, 'word_id' => $q['word_id'] + 1000])
        ->assertStatus(422)->assertJsonPath('error.code', 'word_mismatch');
    $this->withHeaders($h)->postJson("/api/twa/exam/sessions/{$session->id}/answer", $body)->assertOk();
    $this->withHeaders($h)->postJson("/api/twa/exam/sessions/{$session->id}/answer", $body)
        ->assertStatus(409)->assertJsonPath('error.code', 'already_answered');
    expect(ExamAnswer::query()->where('exam_session_id', $session->id)->count())->toBe(1);

    CarbonImmutable::setTestNow('2026-11-17 10:10:01');
    $this->withHeaders($h)->postJson("/api/twa/exam/sessions/{$session->id}/answer", [...$body, 'question_index' => 1])->assertStatus(410);
    expect(ExamAnswer::query()->where('exam_session_id', $session->id)->count())->toBe(1);
});

// --- the counted attempt of the week -------------------------------------------

it('counts the first finished quiz of the week and makes the next one practice, which changes nothing the learner sees', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $h = levelQuizHeaders($learner);

    $first = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('scored', true));
    levelQuizPlay($this, $learner, $first, 1);
    $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$first->id}/result")->assertOk()->assertJsonPath('percent', 100)->assertJsonPath('scored', true);

    $second = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('scored', false));
    levelQuizPlay($this, $learner, $second, 0); // all wrong
    $this->withHeaders($h)->getJson("/api/twa/exam/sessions/{$second->id}/result")->assertOk()
        ->assertJsonPath('correct', 0)->assertJsonPath('scored', false);

    $quizzes = app(LevelQuizService::class);
    $now = CarbonImmutable::now();
    $week = $quizzes->weekLine($learner, $course, $now);
    expect($week->thisWeek)->toMatchArray(['correct' => 12, 'total' => 12, 'percent' => 100])
        ->and($quizzes->history($learner, $course, $now))->toHaveCount(1)
        ->and($quizzes->history($learner, $course, $now)[0])->toBe(['week_start' => '2026-11-16', 'correct' => 12, 'total' => 12, 'percent' => 100])
        ->and(LevelQuizResult::query()->where('learner_id', $learner->id)->orderBy('id')->pluck('scored')->all())->toBe([true, false])
        ->and(ProductEvent::query()->where('name', 'level_quiz.finished')->orderBy('id')->get()->map(fn ($e) => $e->props['scored'])->all())->toBe([true, false]);
});

it('does not count a quiz nobody answered: the week stays open for a counted one', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $abandoned = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());

    CarbonImmutable::setTestNow('2026-11-17 10:20:00');
    $next = levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('scored', true);

    expect($abandoned->refresh()->status)->toBe('closed')
        ->and(LevelQuizResult::query()->count())->toBe(0)
        ->and($next->json('session_id'))->not->toBe($abandoned->id);
});

it('finalizes an expired quiz that has answers when the learner opens the next one, which is then practice', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $old = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    $right = levelQuizPlay($this, $learner, $old, 1, 3);

    CarbonImmutable::setTestNow('2026-11-17 10:30:00');
    $new = levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('scored', false);

    $row = LevelQuizResult::query()->where('exam_session_id', $old->id)->sole();
    expect($old->refresh()->status)->toBe('closed')
        ->and([$row->scored, $row->correct, $row->total])->toBe([true, $right, 12])
        ->and($new->json('session_id'))->not->toBe($old->id)
        ->and(ProductEvent::query()->where('name', 'level_quiz.finished')->sole()->props['reason'])->toBe('expired');
});

it('cuts the week on Monday in the learner\'s own zone', function (string $zone, string $now, string $expected): void {
    $learner = Learner::factory()->create(['timezone' => $zone]);

    expect(app(LevelQuizService::class)->weekStart($learner, CarbonImmutable::parse($now, 'UTC')))->toBe($expected);
})->with([
    'Tashkent, Sunday 23:59' => ['Asia/Tashkent', '2026-11-22 18:59:00', '2026-11-16'],
    'Tashkent, Monday 00:00' => ['Asia/Tashkent', '2026-11-22 19:00:00', '2026-11-23'],
    'New York at the same instant' => ['America/New_York', '2026-11-22 19:00:00', '2026-11-16'],
    'Monday morning' => ['Asia/Tashkent', '2026-11-16 00:01:00', '2026-11-16'],
]);

it('counts a quiz opened on Sunday night for the week it was opened in, and a new one on Monday for the new week', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    CarbonImmutable::setTestNow('2026-11-22 18:58:00'); // Sunday 23:58 in Tashkent
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('week_start', '2026-11-16'));
    levelQuizPlay($this, $learner, $session, 1);

    CarbonImmutable::setTestNow('2026-11-22 19:05:00'); // Monday 00:05
    $this->withHeaders(levelQuizHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertOk()
        ->assertJsonPath('week_start', '2026-11-16')->assertJsonPath('scored', true);
    levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('week_start', '2026-11-23')->assertJsonPath('scored', true);

    expect(LevelQuizResult::query()->where('scored', true)->pluck('week_start')->map(fn ($d) => $d->toDateString())->all())->toBe(['2026-11-16']);
});

it('writes a second counted row of the week as practice instead of failing (the constraint is the last line)', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated()->assertJsonPath('scored', true));
    levelQuizPlay($this, $learner, $session, 1, 4);

    // Another counted result of this week appears between the open and the finalize.
    $other = ExamSession::factory()->levelQuiz($learner, $course)->closed()->create();
    LevelQuizResult::query()->create([
        'learner_id' => $learner->id, 'course_id' => $course->id, 'exam_session_id' => $other->id, 'week_start' => '2026-11-16',
        'scored' => true, 'correct' => 3, 'total' => 12, 'finished_at' => now(),
    ]);

    app(LevelQuizService::class)->finalize($session, 'finished');

    expect(LevelQuizResult::query()->where('exam_session_id', $session->id)->sole()->scored)->toBeFalse()
        ->and(LevelQuizResult::query()->where('scored', true)->count())->toBe(1)
        ->and($session->refresh()->status)->toBe('closed');
});

it('is finalized by exams:close-expired once, and a second run does nothing', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    levelQuizPlay($this, $learner, $session, 1, 2);

    CarbonImmutable::setTestNow('2026-11-17 10:30:00');
    expect(Artisan::call('exams:close-expired'))->toBe(CloseExpiredExams::SUCCESS)
        ->and($session->refresh()->status)->toBe('closed')
        ->and(LevelQuizResult::query()->where('exam_session_id', $session->id)->sole()->correct)->toBe(2);

    Artisan::call('exams:close-expired');
    expect(LevelQuizResult::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'level_quiz.finished')->count())->toBe(1);
});

it('leaves a running quiz open for the scheduler and for finalizeIfDue', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    levelQuizPlay($this, $learner, $session, 1, 2);

    Artisan::call('exams:close-expired');

    expect($session->refresh()->status)->toBe('open')
        ->and(app(LevelQuizService::class)->finalizeIfDue($session)->status)->toBe('open')
        ->and(LevelQuizResult::query()->count())->toBe(0);
});

// --- a measurement, not a lesson -------------------------------------------------

it('touches neither the schedule nor the daily stats nor the training and the points', function (): void {
    ['course' => $course, 'learner' => $learner] = levelQuizWorld();
    DB::table('learner_daily_stats')->where('learner_id', $learner->id)->delete();

    $snapshot = fn (): array => [
        DB::table('word_repetitions')->where('learner_id', $learner->id)->orderBy('word_id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('learner_daily_stats')->where('learner_id', $learner->id)->count(),
        DB::table('training_sessions')->where('learner_id', $learner->id)->count(),
        DB::table('training_reviews')->count(),
        DB::table('game_runs')->count(),
        DB::table('game_coin_ledger')->count(),
    ];
    $before = $snapshot();

    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());
    levelQuizPlay($this, $learner, $session, 2);
    $this->withHeaders(levelQuizHeaders($learner))->getJson("/api/twa/exam/sessions/{$session->id}/result")->assertOk();

    expect(LevelQuizResult::query()->count())->toBe(1)
        ->and($snapshot())->toBe($before)
        ->and($before[1])->toBe(0);

});

it('never offers an option that shares a sense with the right answer of the question', function (): void {
    ['course' => $course, 'learner' => $learner, 'met' => $ids] = levelQuizWorld(12, 12);
    // `he` and `she` both mean "он" in one of their senses: neither may be the distractor of the other.
    DB::table('words')->where('id', $ids[0])->update(['word' => 'he', 'translation' => 'он']);
    DB::table('words')->where('id', $ids[1])->update(['word' => 'she', 'translation' => 'она, он']);

    $session = levelQuizSession(levelQuizOpen($this, $learner, $course)->assertCreated());

    expect($session->config['questions'])->toHaveCount(12);
    foreach ($session->config['questions'] as $question) {
        $keys = TranslationParts::keys($question['correct_translation']);
        foreach ($question['options'] as $option) {
            if ($option !== $question['correct_translation']) {
                expect(TranslationParts::share($keys, $option))->toBeFalse("'{$option}' shares a sense with '{$question['correct_translation']}'");
            }
        }
    }
});
