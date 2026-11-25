<?php

declare(strict_types=1);

/*
 * The `score` block of `POST /training/sessions/{id}/review` (docs/32 §8.1, the rest of S5; docs/04 A.3): the resource hands the word
 * points of the answer over as `WordScoreOutcome::toApi()` - null with the points off or in `shadow` (the contract of the old clients
 * does not change), the block in `live` once a count of the day is on v2.
 */

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Learning\DTO\ReviewOutcome;
use App\Domain\Learning\DTO\ScoreDayCount;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\WordScoreReason;
use App\Domain\Twa\Services\JwtService;
use App\Http\Resources\Twa\ReviewResultResource;
use App\Models\Learner;
use App\Models\Lexeme;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\CardAnswers;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Wednesday 2026-11-25 10:00 in Tashkent.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.versions.2.starts_on' => '2026-11-01',
        'practice.access' => 'all', 'practice.plan' => 'all', 'game.access' => 'off',
    ]);
});

/** @return array<string, mixed> */
function rsbResource(?WordScoreOutcome $score): array
{
    $repetition = WordRepetition::factory()->make(['next_review_at' => now()->addDay(), 'interval_days' => 1, 'easiness_factor' => 2.5]);
    $outcome = new ReviewOutcome($repetition, new TrainingStep(null, null, ['done' => 1, 'total' => 1], doneReason: DoneReason::LessonComplete), score: $score);

    return (new ReviewResultResource($outcome))->toArray(Request::create('/'));
}

it('gives the score block of a visible outcome as toApi() and null otherwise', function (): void {
    $week = new ScoreDayCount(ScoreCategory::Free, 2, 12, 100, 3, 20, 2, 20);
    $visible = new WordScoreOutcome(WordScoreReason::Credited, ScoreKind::Long, 5, 5, 5, $week, $week, visible: true);

    expect(rsbResource($visible)['score'])->toBe([
        'kind' => 'long', 'reason' => 'credited', 'base' => 5, 'week' => 5, 'month' => 5,
        'today' => ['points' => 12, 'cap' => 100, 'category' => 'free', 'new' => [3, 20], 'review' => [2, 20]],
        'today_month' => null,
    ])
        // off / shadow / live before the start: the outcome is not visible
        ->and(rsbResource(new WordScoreOutcome(WordScoreReason::Credited, ScoreKind::New, 2, 0, 0, $week, $week, visible: false)))->toHaveKey('score', null)
        ->and(rsbResource(WordScoreOutcome::skipped(WordScoreReason::Off)))->toHaveKey('score', null)
        // the review of a lesson has no outcome at all
        ->and(rsbResource(null))->toHaveKey('score', null);
});

/** @return array{learner: Learner, headers: array<string, string>, opened: array<string, mixed>} */
function rsbLesson(object $test): array
{
    ['course' => $course, 'lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => 5]]); // a four-option card needs three other words
    foreach ($words['1.1']['active'] as $wordId) {
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
    }
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 10, 'interface_locale' => 'ru']);
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();

    return ['learner' => $learner, 'headers' => $headers, 'opened' => $opened];
}

/**
 * Plays the lesson with right answers; the `score` of every response.
 *
 * @param  array<string, mixed>  $opened
 * @param  array<string, string>  $headers
 * @return list<array{type: string, score: mixed}>
 */
function rsbPlay(object $test, array $headers, array $opened): array
{
    $out = [];
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 50; $guard++) {
        $response = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk();
        expect($response->json())->toHaveKey('score');
        $out[] = ['type' => CardAnswers::typeOf($card)->value, 'score' => $response->json('score')];
        $card = $response->json('card');
    }

    return $out;
}

it('answers score: null with the word points off and in shadow', function (string $mode): void {
    config(['leagues.scoring.mode' => $mode]);
    ['headers' => $h, 'opened' => $opened] = rsbLesson($this);

    $answers = rsbPlay($this, $h, $opened);

    expect($answers)->not->toBeEmpty()
        ->and(array_column($answers, 'score'))->each->toBeNull();
})->with(['off', 'shadow']);

it('answers the score block in live: the first meeting is introductory, the first checked answer confirms the word', function (): void {
    config(['leagues.scoring.mode' => 'live']);
    ['headers' => $h, 'opened' => $opened] = rsbLesson($this);

    $answers = rsbPlay($this, $h, $opened);

    $flash = $answers[0];
    expect($flash['type'])->toBe('flash')
        ->and($flash['score'])->toBe(['kind' => null, 'reason' => 'introductory', 'base' => 0, 'week' => 0, 'month' => 0, 'today' => null, 'today_month' => null]);

    $credited = array_values(array_filter($answers, fn (array $a): bool => ($a['score']['reason'] ?? null) === 'credited'));
    expect($credited)->toHaveCount(5);
    $first = $credited[0]['score'];
    expect($first)->toMatchArray(['kind' => 'new', 'base' => 2, 'week' => 2, 'month' => 2, 'today_month' => null])
        ->and($first['today'])->toBe(['points' => 2, 'cap' => 100, 'category' => 'free', 'new' => [1, 20], 'review' => [0, 20]])
        ->and($credited[4]['score']['today']['points'])->toBe(10);
});
