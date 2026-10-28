<?php

declare(strict_types=1);

/*
 * The steps of a LESSON session (`POST /start`, `GET /next`, `POST /review`): what they answer, and what they cost.
 *
 * `progress.total` is the number of ACTIVE words of the lesson. Every step used to count them with a query of its
 * own, and re-read lesson -> stage -> course just to learn the visibility of the course. The count is the cached
 * `LearningCache::lessonWordCount()` now (dropped by every path that changes words, see WordChangePathsTest), the
 * access check is one query (LessonSessionAvailabilityTest has its verdicts).
 */

use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TrainingSession;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/**
 * A learner with an open session on a lesson of `$active` active and `$inactive` retired words.
 *
 * @return array{learner: Learner, lesson: Lesson, session: TrainingSession, headers: array<string, string>, words: array{active: list<int>, inactive: list<int>}}
 */
function lessonStepsScenario(int $active = 6, int $inactive = 2): array
{
    ['lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => [$active, $inactive]]]);
    $learner = Learner::factory()->onboarded()->create();

    return [
        'learner' => $learner,
        'lesson' => $lessons['1.1'],
        'session' => app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lessons['1.1']),
        'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']],
        'words' => $words['1.1'],
    ];
}

it('reports done of total ACTIVE words on every step of a lesson session', function (): void {
    $s = lessonStepsScenario(6, 2);
    $base = "/api/twa/training/sessions/{$s['session']->id}";

    $this->withHeaders($s['headers'])->postJson("{$base}/start")->assertOk()
        ->assertJsonPath('progress', ['done' => 0, 'total' => 6]);

    $card = $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()
        ->assertJsonPath('progress', ['done' => 0, 'total' => 6])
        ->json('card');

    // "Easy" graduates the card: one of six is done, and the next card is already on its way.
    $this->withHeaders($s['headers'])->postJson("{$base}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 800])->assertOk()
        ->assertJsonPath('progress', ['done' => 1, 'total' => 6]);
    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()
        ->assertJsonPath('progress', ['done' => 1, 'total' => 6]);
});

it('follows a word that is switched off in the admin panel on the very next step', function (): void {
    $s = lessonStepsScenario(5, 0);
    $base = "/api/twa/training/sessions/{$s['session']->id}";

    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()->assertJsonPath('progress.total', 5);

    // What EditWord does after the toggle: counters follow (the lesson cache is dropped).
    $retired = Word::query()->findOrFail($s['words']['active'][4]);
    $retired->update(['is_active' => false]);
    app(LessonContentRefresher::class)->wordChanged($retired);

    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()->assertJsonPath('progress.total', 4);

    $retired->update(['is_active' => true]);
    app(LessonContentRefresher::class)->wordChanged($retired);

    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()->assertJsonPath('progress.total', 5);
});

/**
 * The statements of /start, /next and /review (the SECOND grade of the day: the first one also places the
 * learner in the league and checks the first achievement) on a lesson of $words words.
 *
 * @return array{start: list<string>, next: list<string>, review: list<string>}
 */
function lessonStepStatements(object $test, int $words): array
{
    $s = lessonStepsScenario($words, 0);
    $base = "/api/twa/training/sessions/{$s['session']->id}";
    $call = fn (string $method, string $uri, array $body = []) => $test->withHeaders($s['headers'])->json($method, $base.$uri, $body)->assertOk();

    $call('POST', '/start'); // warm the entitlement, the lesson count and the translation caches
    $start = QueryLog::during(fn () => $call('POST', '/start'));

    $call('GET', '/next'); // the first card is served (a row is created); asking again finds it due
    $card = null;
    $next = QueryLog::during(function () use ($call, &$card): void {
        $card = $call('GET', '/next')->json('card');
    });

    $call('POST', '/review', ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 100]);
    $second = $call('GET', '/next')->json('card');
    $review = QueryLog::during(fn () => $call('POST', '/review', ['word_id' => $second['word_id'], 'quality' => 5, 'time_spent_ms' => 100]));

    return ['start' => $start, 'next' => $next, 'review' => $review];
}

it('costs the same statements for a lesson of 6 and of 40 words, and counts no words per step', function (): void {
    $small = lessonStepStatements($this, 6);
    $large = lessonStepStatements($this, 40);

    // Before: start 14, next 12, review 25. The words of the lesson are counted once an hour, not on each step;
    // the access check is one statement instead of three, and /start reads the lesson with its stage and course in
    // one statement instead of three (12 -> 10).
    foreach (['start' => 10, 'next' => 9, 'review' => 22] as $step => $budget) {
        expect(count($small[$step]))->toBeLessThanOrEqual($budget)
            ->and(count($large[$step]))->toBe(count($small[$step]))
            ->and(QueryLog::matching($large[$step], '/select count\(\*\) as aggregate from ["`]words["`]/'))->toBe([]);
    }
});

it('reads lesson, stage and course once per /next and /review - in the access check - and not in the card flow', function (): void {
    $steps = lessonStepStatements($this, 6);
    $touching = fn (array $queries): array => QueryLog::matching($queries, '/["`](lessons|stages|courses)["`]/');

    expect($touching($steps['next']))->toHaveCount(1)
        ->and($touching($steps['review']))->toHaveCount(1)
        // /start shows the lesson and its deck title: the access check, plus ONE read of the lesson with its stage
        // and the course facts (it was three more: lesson, stage, course, one after another - four in all).
        ->and($touching($steps['start']))->toHaveCount(2);

    // The second read is the joined one: lessons, stages and courses in a single statement.
    expect(QueryLog::matching($steps['start'], '/from ["`]lessons["`] inner join ["`]stages["`] .* inner join ["`]courses["`]/s'))->toHaveCount(2);
});
