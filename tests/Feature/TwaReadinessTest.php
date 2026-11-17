<?php

declare(strict_types=1);

/*
 * Readiness to the level on "Today" (`active_course.readiness`) and on its own screen (`GET /courses/{slug}/readiness`),
 * docs/13 §8.5, docs/04 A.20: the form, the switches, the quiz line and the statement budgets.
 */

use App\Domain\Learning\Services\LevelQuizService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LevelQuizResult;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'readiness.enabled' => true,
        'readiness.quiz.enabled' => true,
        'readiness.quiz.questions' => 12,
        'readiness.quiz.seconds' => 600,
        'readiness.quiz.history_weeks' => 12,
        'learning.stages.known_from_interval_days' => 7,
        'learning.stages.mastered_from_interval_days' => 21,
        'game.access' => 'off',
    ]);
    CarbonImmutable::setTestNow('2026-11-17 10:00:00'); // Tuesday, the week of 2026-11-16
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array<string, string> */
function readinessHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A B1 course of two modules (10 + 10 active words) and a learner with 6 rows: 3 learned (1 of them mastered) in module 1,
 * 1 learned in module 2, 2 in progress.
 *
 * @return array{course: Course, learner: Learner}
 */
function readinessWorld(): array
{
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 10], 2 => [1 => 10]], ['level' => 'B1', 'title' => ['ru' => 'Английский B1', 'uz' => 'Ingliz tili B1']]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    $row = fn (int $wordId, string $state, int $interval) => DB::table('word_repetitions')->insert([
        'learner_id' => $learner->id, 'word_id' => $wordId, 'repetitions' => 3, 'interval_days' => $interval, 'state' => $state,
        'next_review_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    [$a, $b, $c, $d, $e] = $words['1.1']['active'];
    $row($a, 'review', 30);
    $row($b, 'review', 9);
    $row($c, 'review', 7);
    $row($d, 'review', 3);
    $row($e, 'relearning', 0);
    $row($words['2.1']['active'][0], 'review', 8);

    return ['course' => $course, 'learner' => $learner];
}

function readinessQuizRow(Learner $learner, Course $course, string $week, int $correct, bool $scored = true): void
{
    $session = ExamSession::factory()->levelQuiz($learner, $course, $week)->closed()->create();
    LevelQuizResult::query()->create([
        'learner_id' => $learner->id, 'course_id' => $course->id, 'exam_session_id' => $session->id, 'week_start' => $week,
        'scored' => $scored, 'correct' => $correct, 'total' => 12, 'finished_at' => CarbonImmutable::now(),
    ]);
}

// --- /today -----------------------------------------------------------------------

it('puts the readiness and the quiz line of the week in the active course of /today', function (): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();

    $today = $this->withHeaders(readinessHeaders($learner))->getJson('/api/twa/today')->assertOk();

    expect($today->json('active_course.readiness'))->toBe([
        'level_label' => 'B1',
        'percent' => 20, // 4 of 20 learned
        'words' => ['total' => 20, 'learned' => 4, 'mastered' => 1, 'in_progress' => 2, 'not_started' => 14],
        'quiz' => ['enabled' => true, 'week_start' => '2026-11-16', 'this_week' => null],
    ]);

    readinessQuizRow($learner, $course, '2026-11-16', 8);
    readinessQuizRow($learner, $course, '2026-11-09', 5);
    readinessQuizRow($learner, $course, '2026-11-16', 1, false);

    expect($this->withHeaders(readinessHeaders($learner))->getJson('/api/twa/today')->json('active_course.readiness.quiz'))
        ->toBe(['enabled' => true, 'week_start' => '2026-11-16', 'this_week' => ['correct' => 8, 'total' => 12, 'percent' => 66]]);
});

it('has no readiness without an active course and null when the feature is off', function (): void {
    ['learner' => $learner] = readinessWorld();
    $bare = Learner::factory()->onboarded()->create();
    $this->withHeaders(readinessHeaders($bare))->getJson('/api/twa/today')->assertOk()->assertJsonPath('active_course', null);

    config(['readiness.enabled' => false]);
    $queries = QueryLog::during(fn () => $this->withHeaders(readinessHeaders($learner))->getJson('/api/twa/today')->assertOk()->assertJsonPath('active_course.readiness', null));

    expect(QueryLog::matching($queries, '/level_quiz_results/'))->toBe([]);
});

it('says only that the quiz is off, without a statement, when READINESS_QUIZ_ENABLED is false', function (): void {
    ['learner' => $learner] = readinessWorld();
    config(['readiness.quiz.enabled' => false]);

    $queries = QueryLog::during(fn () => $this->withHeaders(readinessHeaders($learner))->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('active_course.readiness.percent', 20)
        ->assertJsonPath('active_course.readiness.quiz', ['enabled' => false]));

    expect(QueryLog::matching($queries, '/level_quiz_results/'))->toBe([]);
});

it('keeps the statements of /today to the one indexed read of the quiz results, however many results there are', function (): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();
    foreach (range(0, 5) as $i) {
        readinessQuizRow($learner, $course, CarbonImmutable::parse('2026-11-16')->subWeeks($i)->toDateString(), 6);
    }
    $this->withHeaders(readinessHeaders($learner))->getJson('/api/twa/today')->assertOk(); // warm

    $queries = QueryLog::during(fn () => $this->withHeaders(readinessHeaders($learner))->getJson('/api/twa/today')->assertOk());

    expect(QueryLog::matching($queries, '/from ["`]level_quiz_results["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]word_repetitions["`] inner join/'))->toHaveCount(1);
});

// --- the screen -------------------------------------------------------------------

it('answers the screen with the readiness, the modules and the quiz of the week', function (): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();
    readinessQuizRow($learner, $course, '2026-10-26', 5);
    readinessQuizRow($learner, $course, '2026-11-09', 9);
    readinessQuizRow($learner, $course, '2026-11-16', 8);
    readinessQuizRow($learner, $course, '2026-11-16', 12, false); // practice: not in the history
    readinessQuizRow($learner, $course, '2025-01-06', 12);        // older than the window
    $otherCourse = Course::factory()->published()->create();
    readinessQuizRow($learner, $otherCourse, '2026-11-16', 12);   // another course

    $response = $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$course->slug}/readiness?source=today")->assertOk();
    $json = $response->json();

    expect(array_keys($json))->toBe(['course', 'percent', 'words', 'thresholds', 'modules', 'quiz'])
        ->and($json['course'])->toBe(['id' => $course->id, 'slug' => $course->slug, 'title' => 'Английский B1', 'level' => 'B1', 'level_label' => 'B1'])
        ->and($json['percent'])->toBe(20)
        ->and($json['words'])->toBe(['total' => 20, 'learned' => 4, 'mastered' => 1, 'known' => 3, 'in_progress' => 2, 'not_started' => 14])
        ->and($json['thresholds'])->toBe(['known_days' => 7, 'mastered_days' => 21])
        ->and($json['modules'])->toHaveCount(2)
        ->and($json['modules'][0])->toMatchArray(['number' => 1, 'learned' => 3, 'total' => 10])
        ->and($json['modules'][1])->toMatchArray(['number' => 2, 'learned' => 1, 'total' => 10])
        ->and(array_keys($json['modules'][0]))->toBe(['number', 'title', 'learned', 'total'])
        ->and(array_keys($json['quiz']))->toBe(['enabled', 'questions', 'seconds', 'week_start', 'this_week', 'history', 'best_percent'])
        ->and($json['quiz']['enabled'])->toBeTrue()
        ->and($json['quiz']['questions'])->toBe(12)
        ->and($json['quiz']['seconds'])->toBe(600)
        ->and($json['quiz']['week_start'])->toBe('2026-11-16')
        ->and($json['quiz']['this_week'])->toBe(['correct' => 8, 'total' => 12, 'percent' => 66, 'finished_at' => '2026-11-17T10:00:00+00:00'])
        ->and($json['quiz']['history'])->toBe([
            ['week_start' => '2026-10-26', 'correct' => 5, 'total' => 12, 'percent' => 41],
            ['week_start' => '2026-11-09', 'correct' => 9, 'total' => 12, 'percent' => 75],
            ['week_start' => '2026-11-16', 'correct' => 8, 'total' => 12, 'percent' => 66],
        ])
        ->and($json['quiz']['best_percent'])->toBe(75);
});

it('answers the screen of a learner who has done nothing yet with empty figures and no best result', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 6]], ['level' => 'A1']);
    $learner = Learner::factory()->onboarded()->create();

    $json = $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$course->slug}/readiness")->assertOk()->json();

    expect($json['percent'])->toBe(0)
        ->and($json['words'])->toBe(['total' => 6, 'learned' => 0, 'mastered' => 0, 'known' => 0, 'in_progress' => 0, 'not_started' => 6])
        ->and($json['quiz']['this_week'])->toBeNull()
        ->and($json['quiz']['history'])->toBe([])
        ->and($json['quiz']['best_percent'])->toBeNull();
});

it('names the level by meta.level_label when the course has one', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 6]], ['level' => 'A1', 'meta' => ['level_label' => 'A1–A2']]);
    $learner = Learner::factory()->onboarded()->create();

    $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$course->slug}/readiness")->assertOk()
        ->assertJsonPath('course.level', 'A1')->assertJsonPath('course.level_label', 'A1–A2');
});

it('answers 404 course_not_found for an unknown, an unpublished and a private course of somebody else', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $draft = Course::factory()->create(['status' => 'draft']);

    foreach (['nope', $draft->slug] as $slug) {
        $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$slug}/readiness")
            ->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    }
});

it('removes the screen when readiness is off: 404 readiness_disabled and no event', function (): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();
    config(['readiness.enabled' => false]);

    $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$course->slug}/readiness")
        ->assertNotFound()->assertJsonPath('error.code', 'readiness_disabled');
    expect(ProductEvent::query()->where('name', 'readiness.viewed')->count())->toBe(0);
});

it('shows the screen without the quiz history when only the quiz is off', function (): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();
    config(['readiness.quiz.enabled' => false]);

    $queries = QueryLog::during(function () use ($course, $learner): void {
        $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$course->slug}/readiness")->assertOk()
            ->assertJsonPath('percent', 20)->assertJsonPath('quiz', ['enabled' => false]);
    });

    expect(QueryLog::matching($queries, '/level_quiz_results/'))->toBe([]);
});

it('records readiness.viewed with the source, once per opening', function (string $query, string $expected): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();

    $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$course->slug}/readiness{$query}")->assertOk();

    $event = ProductEvent::query()->where('name', 'readiness.viewed')->sole();
    expect($event->learner_id)->toBe($learner->id)
        ->and(ksorted($event->props))->toBe(ksorted(['course_id' => $course->id, 'percent' => 20, 'learned' => 4, 'total' => 20, 'source' => $expected])); // jsonb reorders keys on PostgreSQL
})->with([
    'profile' => ['?source=profile', 'profile'],
    'today' => ['?source=today', 'today'],
    'course' => ['?source=course', 'course'],
    'garbage' => ['?source=%3Cscript%3E', 'unknown'],
    'none' => ['', 'unknown'],
]);

it('reads the screen within five statements once the tree is warm', function (): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();
    readinessQuizRow($learner, $course, '2026-11-16', 8);
    $url = "/api/twa/courses/{$course->slug}/readiness?source=course";
    $this->withHeaders(readinessHeaders($learner))->getJson($url)->assertOk(); // warm the tree and the plan

    $queries = QueryLog::during(fn () => $this->withHeaders(readinessHeaders($learner))->getJson($url)->assertOk());

    expect(count($queries))->toBeLessThanOrEqual(5)
        ->and(QueryLog::matching($queries, '/from ["`]level_quiz_results["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]word_repetitions["`] inner join/'))->toHaveCount(1);
});

it('does not reveal the key of any answer or the config of a quiz on these payloads', function (): void {
    ['course' => $course, 'learner' => $learner] = readinessWorld();
    app(LevelQuizService::class)->open($learner, $course);

    $body = $this->withHeaders(readinessHeaders($learner))->getJson("/api/twa/courses/{$course->slug}/readiness")->assertOk()->getContent();

    expect($body)->not->toContain('order_secret')->not->toContain('correct_translation')->not->toContain('options');
});
