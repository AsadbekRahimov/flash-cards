<?php

declare(strict_types=1);

/*
 * The placement test of the onboarding of the Mini App (docs/40 §2.1a, docs/04 A.27): POST /onboarding/placement and
 * POST /onboarding/placement/answers - ten questions, two per level, the key only on the server, the level from the answers, one scoring
 * per set, the limits, and the guard that nothing of the learning state is touched.
 */

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ScoreCredit;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['learners.goals' => ['general'], 'learners.daily_goal_options' => [10, 20, 40]]);
});

/**
 * The general courses of the levels given (A2 has none: it leads to the A1 course, the "A1–A2" row of the level screen), 12 words each.
 *
 * @param  list<string>  $levels
 * @return array<string, Course>
 */
function plCatalog(array $levels = ['A1', 'B1', 'B2', 'C1', 'C2']): array
{
    $courses = [];
    foreach ($levels as $level) {
        $courses[$level] = Fx::course([1 => [1 => 6, 2 => 6]], [
            'slug' => 'english-'.strtolower($level), 'exam_tag' => 'general', 'level' => $level,
            'title' => ['ru' => 'Английский '.$level, 'uz' => 'Ingliz tili '.$level],
        ])['course'];
    }

    return $courses;
}

/** @return array<string, string> */
function plHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function plStart(Learner $learner): TestResponse
{
    return test()->withHeaders(plHeaders($learner))->postJson('/api/twa/onboarding/placement');
}

/** @param  array<string, mixed>  $body */
function plAnswer(Learner $learner, array $body): TestResponse
{
    return test()->withHeaders(plHeaders($learner))->postJson('/api/twa/onboarding/placement/answers', $body);
}

/** @return list<array{id: int, word_id: int, level: string, correct: int, n: int}> the key of the learner's draft */
function plItems(Learner $learner): array
{
    /** @var list<array{id: int, word_id: int, level: string, correct: int, n: int}> */
    return $learner->fresh()?->meta['twa_onboarding']['placement']['items'] ?? [];
}

/**
 * Answers built from the key: right on the levels of `$right`, wrong elsewhere (`$only` - right for the first question of a level only).
 *
 * @param  list<string>  $right
 * @param  list<string>  $only
 * @return list<int>
 */
function plAnswers(Learner $learner, array $right, array $only = []): array
{
    $answers = [];
    $seen = [];
    foreach (plItems($learner) as $item) {
        $first = ! isset($seen[$item['level']]);
        $seen[$item['level']] = true;
        $isRight = in_array($item['level'], $right, true) || (in_array($item['level'], $only, true) && $first);
        $answers[] = $isRight ? $item['correct'] : ($item['correct'] + 1) % $item['n'];
    }

    return $answers;
}

function plCourseOfWord(int $wordId): ?int
{
    return Word::query()->whereKey($wordId)->first()?->lesson?->stage?->course_id;
}

it('starts a test of ten questions, two per level, and keeps the key on the server only', function (): void {
    $courses = plCatalog();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $this->freezeSecond();

    $response = plStart($learner)->assertCreated()->assertJsonStructure(['questions' => [['id', 'word', 'options']], 'expires_at']);

    $questions = $response->json('questions');
    expect($questions)->toHaveCount(10)
        ->and(array_column($questions, 'id'))->toBe(range(1, 10))
        ->and($response->json('expires_at'))->toBe(now()->addMinutes(20)->utc()->toIso8601String())
        ->and(array_keys($questions[0]))->toBe(['id', 'word', 'options'])
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('correct')->not->toContain('word_id');

    $items = plItems($learner);
    expect(array_column($items, 'level'))->toBe(['A1', 'A1', 'A2', 'A2', 'B1', 'B1', 'B2', 'B2', 'C1', 'C1'])
        ->and(array_column($items, 'id'))->toBe(range(1, 10));

    foreach ($items as $i => $item) {
        $word = Word::query()->findOrFail($item['word_id']);
        $question = $questions[$i];
        $options = $question['options'];
        // Four distinct options, the right one at the stored index; the word and its options are of the course of the level.
        expect($question['word'])->toBe($word->word)
            ->and($options)->toHaveCount(4)
            ->and(array_unique($options))->toHaveCount(4)
            ->and($options[$item['correct']])->toBe($word->translation)
            ->and($item['n'])->toBe(4);
        $courseId = $courses[$item['level'] === 'A2' ? 'A1' : $item['level']]->id;
        expect(plCourseOfWord($item['word_id']))->toBe($courseId);
        $translations = Word::query()->whereIn('lesson_id', fn ($q) => $q->select('id')->from('lessons')->whereIn('stage_id', fn ($s) => $s->select('id')->from('stages')->where('course_id', $courseId)))->pluck('translation')->all();
        foreach ($options as $option) {
            expect($translations)->toContain($option);
        }
    }
    // The asked words of a level are distinct.
    expect(array_unique(array_column($items, 'word_id')))->toHaveCount(10);
});

it('scores the answers into the level and the course of the level screen', function (array $right, array $only, string $level, string $slug): void {
    plCatalog();
    $learner = Learner::factory()->create();
    plStart($learner)->assertCreated();

    $response = plAnswer($learner, ['answers' => plAnswers($learner, $right, $only)])->assertOk();

    expect($response->json('level'))->toBe($level)
        ->and($response->json('course_slug'))->toBe($slug)
        ->and(array_keys($response->json('correct_by_level')))->toBe(['A1', 'A2', 'B1', 'B2', 'C1']);
})->with([
    'everything right → C1' => [['A1', 'A2', 'B1', 'B2', 'C1'], [], 'C1', 'english-c1'],
    'nothing right → A1' => [[], [], 'A1', 'english-a1'],
    'right up to B1, none on B2, right on C1 → B1 (the walk stops at the first empty level)' => [['A1', 'A2', 'B1', 'C1'], [], 'B1', 'english-b1'],
    'a hole on B1, right on B2 → A2 (the course of A2 is the A1 one)' => [['A1', 'A2', 'B2'], [], 'A2', 'english-a1'],
    'one of two on every level → C1' => [[], ['A1', 'A2', 'B1', 'B2', 'C1'], 'C1', 'english-c1'],
    'only A1 right → A1' => [['A1'], [], 'A1', 'english-a1'],
    'A1 empty, everything above right → A1' => [['A2', 'B1', 'B2', 'C1'], [], 'A1', 'english-a1'],
]);

it('counts the right answers per level and treats a skipped question (null) as wrong', function (): void {
    plCatalog();
    $learner = Learner::factory()->create();
    plStart($learner)->assertCreated();

    $answers = plAnswers($learner, ['A1', 'A2', 'B1']);
    $answers[0] = null; // the first question of A1 skipped

    plAnswer($learner, ['answers' => $answers])->assertOk()
        ->assertJsonPath('correct_by_level', ['A1' => 1, 'A2' => 2, 'B1' => 2, 'B2' => 0, 'C1' => 0])
        ->assertJsonPath('level', 'B1');
});

it('scores a set once: a repeated request, even with other answers, gets the same result without re-scoring', function (): void {
    plCatalog();
    $learner = Learner::factory()->create();
    plStart($learner)->assertCreated();

    $first = plAnswer($learner, ['answers' => plAnswers($learner, ['A1', 'A2'])])->assertOk()->json();
    $meta = $learner->fresh()?->meta['twa_onboarding']['placement'];
    expect($first['level'])->toBe('A2')
        ->and($meta)->toHaveKeys(['issued_at', 'answered_at', 'result'])
        ->and($meta)->not->toHaveKey('items');

    $second = plAnswer($learner, ['answers' => array_fill(0, 10, 0)])->assertOk()->json();
    $third = plAnswer($learner, ['answers' => [1]])->assertOk()->json();

    expect($second)->toBe($first)->and($third)->toBe($first)
        ->and($learner->fresh()?->meta['twa_onboarding']['placement'])->toBe($meta);
});

it('answers 410 placement_expired after 20 minutes and when no test was started', function (): void {
    plCatalog();
    $learner = Learner::factory()->create();

    plAnswer($learner, ['answers' => array_fill(0, 10, 0)])->assertStatus(410)
        ->assertExactJson(['error' => ['code' => 'placement_expired', 'message' => 'The placement test has expired. Start a new one.']]);

    plStart($learner)->assertCreated();
    $this->travel(19)->minutes();
    $answers = plAnswers($learner, ['A1']);
    $this->travel(2)->minutes();
    plAnswer($learner, ['answers' => $answers])->assertStatus(410)->assertJsonPath('error.code', 'placement_expired');

    // A new start gives a new set and works again.
    $before = array_column(plItems($learner), 'word_id');
    plStart($learner)->assertCreated();
    plAnswer($learner, ['answers' => plAnswers($learner, ['A1'])])->assertOk()->assertJsonPath('level', 'A1');
    expect(plItems($learner))->toBe([])->and($before)->toHaveCount(10);
});

it('answers 409 onboarding_completed on both routes for a learner who is done, and writes nothing', function (): void {
    plCatalog();
    $learner = Learner::factory()->create(['onboarding_state' => 'done', 'meta' => ['x' => 1]]);

    plStart($learner)->assertStatus(409)->assertJsonPath('error.code', 'onboarding_completed');
    plAnswer($learner, ['answers' => [0]])->assertStatus(409)->assertJsonPath('error.code', 'onboarding_completed');

    expect($learner->fresh()?->meta)->toBe(['x' => 1]);
});

it('refuses malformed answers with 422 and keeps the draft answerable', function (array $body): void {
    plCatalog();
    $learner = Learner::factory()->create();
    plStart($learner)->assertCreated();

    $errors = array_keys((array) plAnswer($learner, $body)->assertUnprocessable()->json('errors'));

    expect($errors)->not->toBe([])->each->toStartWith('answers')
        ->and(plItems($learner))->toHaveCount(10);
})->with([
    'missing' => [[]],
    'not a list' => [['answers' => ['a' => 1]]],
    'empty' => [['answers' => []]],
    'too few' => [['answers' => [0, 1]]],
    'too many' => [['answers' => array_fill(0, 11, 0)]],
    'out of range' => [['answers' => [0, 1, 2, 3, 4, 0, 1, 2, 3, 0]]],
    'negative' => [['answers' => [-1, 1, 2, 3, 0, 0, 1, 2, 3, 0]]],
    'a string' => [['answers' => ['0', 1, 2, 3, 0, 0, 1, 2, 3, 0]]],
    'a float' => [['answers' => [1.5, 1, 2, 3, 0, 0, 1, 2, 3, 0]]],
]);

it('replaces the draft and the result on a new start: a new set of words, answerable again', function (): void {
    plCatalog();
    $learner = Learner::factory()->create();
    plStart($learner)->assertCreated();
    plAnswer($learner, ['answers' => plAnswers($learner, ['A1', 'A2', 'B1', 'B2', 'C1'])])->assertOk()->assertJsonPath('level', 'C1');

    plStart($learner)->assertCreated();
    expect($learner->fresh()?->meta['twa_onboarding']['placement'])->not->toHaveKey('result')
        ->and(plItems($learner))->toHaveCount(10);
    plAnswer($learner, ['answers' => plAnswers($learner, [])])->assertOk()->assertJsonPath('level', 'A1');
});

it('keeps the other keys of the onboarding progress and of meta, and the steps keep the draft', function (): void {
    plCatalog();
    $learner = Learner::factory()->create(['meta' => ['locale_chosen' => true]]);
    $this->withHeaders(plHeaders($learner))->putJson('/api/twa/onboarding/steps/goals', ['goals' => ['career']])->assertOk();

    plStart($learner)->assertCreated();
    $this->withHeaders(plHeaders($learner))->putJson('/api/twa/onboarding/steps/age', ['age_range' => '18_24'])->assertOk();

    $fresh = $learner->fresh();
    expect($fresh?->meta['locale_chosen'])->toBeTrue()
        ->and($fresh?->goals)->toBe(['career'])
        ->and($fresh?->age_range)->toBe('18_24')
        ->and($fresh?->meta['twa_onboarding']['answered'])->toBe(['goals', 'age'])
        ->and($fresh?->meta['twa_onboarding']['step'])->toBe('goals')
        ->and(plItems($learner))->toHaveCount(10);
});

it('touches nothing of the learning state: no repetitions, sessions, exams, points, days; the level and the state stay', function (): void {
    plCatalog();
    $learner = Learner::factory()->create(['level' => null, 'onboarding_state' => 'new']);

    plStart($learner)->assertCreated();
    plAnswer($learner, ['answers' => plAnswers($learner, ['A1', 'A2', 'B1', 'B2', 'C1'])])->assertOk()->assertJsonPath('level', 'C1');

    $fresh = $learner->fresh();
    expect(WordRepetition::query()->count())->toBe(0)
        ->and(TrainingSession::query()->count())->toBe(0)
        ->and(ExamSession::query()->count())->toBe(0)
        ->and(ScoreCredit::query()->count())->toBe(0)
        ->and(LearnerDailyStats::query()->count())->toBe(0)
        ->and($fresh?->level)->toBeNull()
        ->and($fresh?->active_course_id)->toBeNull()
        ->and($fresh?->onboarding_state)->toBe('new')
        ->and($fresh?->streak_current)->toBe(0);
});

it('asks only the levels with a course of their own: without a B2 course the test has eight questions and B2 is transparent', function (): void {
    plCatalog(['A1', 'B1', 'C1']);
    $learner = Learner::factory()->create();

    $response = plStart($learner)->assertCreated();
    expect($response->json('questions'))->toHaveCount(8)
        ->and(array_column(plItems($learner), 'level'))->toBe(['A1', 'A1', 'A2', 'A2', 'B1', 'B1', 'C1', 'C1']);

    plAnswer($learner, ['answers' => plAnswers($learner, ['A1', 'A2', 'B1', 'C1'])])->assertOk()
        ->assertJsonPath('level', 'C1')
        ->assertJsonPath('correct_by_level', ['A1' => 2, 'A2' => 2, 'B1' => 2, 'C1' => 2]);
});

it('answers 422 placement_unavailable with a catalog of one level, and GET /onboarding says whether the test is available', function (): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(plHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('options.placement_available', false);
    plStart($learner)->assertUnprocessable()->assertJsonPath('error.code', 'placement_unavailable');

    plCatalog(['A1']);
    app(CourseCatalogService::class)->forgetPublished();
    $this->withHeaders(plHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('options.placement_available', true);
    // A1 and A2 from the one course: four questions, the minimum.
    plStart($learner)->assertCreated()->assertJsonCount(4, 'questions');

    plCatalog(['B1']);
    app(CourseCatalogService::class)->forgetPublished();
    $this->withHeaders(plHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('options.placement_available', true);
    expect($learner->fresh()?->meta['twa_onboarding']['placement']['items'])->toHaveCount(4);
});

it('limits the starts and the answers to twa-session-create per learner', function (): void {
    plCatalog();
    $learner = Learner::factory()->create();

    foreach (range(1, 10) as $_) {
        plStart($learner)->assertCreated();
    }
    plStart($learner)->assertTooManyRequests();
    plAnswer($learner, ['answers' => array_fill(0, 10, 0)])->assertTooManyRequests();
});

it('costs 8 statements to start (the learner, four courses of words, the translations, the lock, the write) and 3 to answer, 2 again', function (): void {
    plCatalog();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $headers = plHeaders($learner);
    $this->withHeaders($headers)->getJson('/api/twa/onboarding')->assertOk(); // warms the catalog and the plan

    $start = QueryLog::during(fn () => $this->withHeaders($headers)->postJson('/api/twa/onboarding/placement')->assertCreated());
    $answers = plAnswers($learner, ['A1']);
    $answer = QueryLog::during(fn () => $this->withHeaders($headers)->postJson('/api/twa/onboarding/placement/answers', ['answers' => $answers])->assertOk());
    $again = QueryLog::during(fn () => $this->withHeaders($headers)->postJson('/api/twa/onboarding/placement/answers', ['answers' => $answers])->assertOk());

    $statements = fn (array $log): array => QueryLog::matching($log, '/^(select|insert|update|delete)/i');
    expect(count($statements($start)))->toBe(8, implode("\n", $start))
        ->and(count(QueryLog::matching($start, '/from "words"/')))->toBe(4)
        ->and(count(QueryLog::matching($start, '/word_translations/')))->toBe(1)
        ->and(count(QueryLog::matching($start, '/^update "learners"/')))->toBe(1)
        ->and(count($statements($answer)))->toBe(3, implode("\n", $answer))
        ->and(count($statements($again)))->toBe(2, implode("\n", $again))
        ->and(QueryLog::matching([...$start, ...$answer], '/word_repetitions|training_sessions|exam_sessions|score_credits|learner_daily_stats/'))->toBe([]);
});
