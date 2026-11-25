<?php

declare(strict_types=1);

/*
 * The word points in the training (docs/32 S5, §3.2, §3.7): the answer of `/review` hands its grade to the scoring core inside its
 * transaction - a lesson confirms its new words (the flash of the first meeting leaves the attempt open), a daily review pays the long
 * repetitions, a replay of the answer is a 409 that credits nothing, the legacy `quality` of a four-option card and the self-grade of a
 * flash close the attempt, a typo of a typed answer is a success (В8); the review of a lesson (`lesson_review`, docs/06 §15) gives
 * nothing; with the points off nothing of them is written. The exercise of a mature card on the form rung is the checked choice of the
 * translation instead of the flash while the points record (В3).
 */

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\WordScoreReason;
use App\Domain\Learning\Services\ExerciseSelector;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CardAnswers;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Wednesday 2026-11-25 10:00 in Tashkent.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.versions.2.starts_on' => '2026-11-01',
        'learning.lesson_review' => ['enabled' => true, 'max_words' => 20, 'max_serves_per_word' => 3],
        'billing.plans.free.limits.new_words_per_day' => 10,
        'practice.access' => 'all', 'practice.plan' => 'all', 'game.access' => 'off',
    ]);
});

/** Links each word to a lexeme of its own (the catalog of a platform course). @param list<int> $wordIds */
function wstLink(array $wordIds): void
{
    foreach ($wordIds as $wordId) {
        $lexeme = Lexeme::factory()->create();
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => $lexeme->id]);
    }
}

/** @return array{learner: Learner, headers: array<string, string>, lessons: array<string, mixed>, words: array<string, array{active: list<int>, inactive: list<int>}>} */
function wstWorld(): array
{
    ['course' => $course, 'lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => 5, 2 => 5]]);
    wstLink([...$words['1.1']['active'], ...$words['1.2']['active']]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 10, 'interface_locale' => 'ru']);

    return ['learner' => $learner, 'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']], 'lessons' => $lessons, 'words' => $words];
}

/** Plays an open session to its end with right answers; returns the cards served. @return list<array<string, mixed>> */
function wstPlay(object $test, array $headers, array $opened): array
{
    $served = [];
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $served[] = $card;
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json('card');
    }

    return $served;
}

/** @return array<string, mixed> every score row of the learner, for "nothing changed" */
function wstScoreState(Learner $learner): array
{
    return [
        'lexemes' => DB::table('learner_lexemes')->where('learner_id', $learner->id)->orderBy('lexeme_id')->get()->map(fn (object $r): array => (array) $r)->all(),
        'days' => DB::table('learner_score_days')->where('learner_id', $learner->id)->get()->map(fn (object $r): array => (array) $r)->all(),
        'credits' => DB::table('score_credits')->where('learner_id', $learner->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all(),
        'periods' => DB::table('learner_score_periods')->where('learner_id', $learner->id)->count(),
    ];
}

/** Due day-based cards of the learner on the given words: last pass `$interval` days (and an hour) ago, confirmed lexemes. @param list<int> $wordIds */
function wstDue(Learner $learner, array $wordIds, int $interval, int $repetitions = 1): void
{
    foreach ($wordIds as $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => RepetitionState::Review->value, 'repetitions' => $repetitions,
            'interval_days' => $interval, 'last_reviewed_at' => now()->subDays($interval)->subHour(), 'next_review_at' => now()->subHour(),
            'easiness_factor' => 2.5, 'is_hard' => false, 'last_quality' => 4, 'lapses' => 0, 'meta' => [],
        ]);
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => (int) Word::query()->whereKey($wordId)->value('lexeme_id'), 'confirmed_on' => '2026-11-01']);
    }
}

it('confirms the new words of a lesson once each and gives nothing for the review of the lesson', function (): void {
    ['learner' => $learner, 'headers' => $h, 'lessons' => $lessons, 'words' => $words] = wstWorld();

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    $served = wstPlay($this, $h, $opened);
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    // Every word: a flash meeting (attempt left open), then checked steps - the first of them confirms it.
    expect(collect($served)->filter(fn (array $c): bool => CardAnswers::typeOf($c) === ExerciseType::Flash)->count())->toBe(5);
    $credits = ScoreCredit::query()->where('learner_id', $learner->id)->orderBy('id')->get();
    expect($credits)->toHaveCount(5)
        ->and($credits->pluck('kind')->unique()->all())->toBe([ScoreKind::New])
        ->and($credits->pluck('source')->unique()->all())->toBe([ScoreSource::Training])
        ->and($credits->pluck('source_id')->unique()->all())->toBe([(int) $opened['session_id']])
        ->and($credits->pluck('word_id')->sort()->values()->all())->toBe($words['1.1']['active'])
        ->and($credits->sum('week_points'))->toBe(10)
        ->and(LearnerLexeme::query()->where('learner_id', $learner->id)->whereNotNull('confirmed_on')->count())->toBe(5)
        ->and(LearnerScoreDay::query()->where('learner_id', $learner->id)->sole()->week_points)->toBe(10);

    $before = wstScoreState($learner);
    $this->travel(2)->minutes();
    $review = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    expect(wstPlay($this, $h, $review))->not->toBe([])
        ->and(wstScoreState($learner))->toBe($before);
});

it('writes nothing of the word points with the points off', function (): void {
    config(['leagues.scoring.mode' => 'off']);
    ['learner' => $learner, 'headers' => $h, 'lessons' => $lessons, 'words' => $words] = wstWorld();
    wstDue($learner, array_slice($words['1.2']['active'], 0, 2), 7);
    LearnerLexeme::query()->delete();

    $lesson = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    wstPlay($this, $h, $lesson);
    $daily = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    wstPlay($this, $h, $daily);

    expect(DB::table('training_reviews')->where('learner_id', $learner->id)->count())->toBeGreaterThan(10)
        ->and(wstScoreState($learner))->toBe(['lexemes' => [], 'days' => [], 'credits' => [], 'periods' => 0]);
});

it('pays the long repetitions of a daily review once: a replay of the answer is a 409 that credits nothing', function (): void {
    ['learner' => $learner, 'headers' => $h, 'words' => $words] = wstWorld();
    $due = array_slice($words['1.2']['active'], 0, 3);
    wstDue($learner, $due, 7);

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $card = $opened['card'];
    $body = CardAnswers::body($card);
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", $body)->assertOk();
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", $body)->assertStatus(409)->assertJsonPath('error.code', 'card_not_due');

    $credit = ScoreCredit::query()->where('learner_id', $learner->id)->sole();
    expect($credit->kind)->toBe(ScoreKind::Long)
        ->and($credit->word_id)->toBe((int) $card['word_id'])
        ->and($credit->week_points)->toBe(5);

    // The rest of the round (the due cards and the new words of the course the sprint brings): one long repetition per due card, one
    // confirmation per new word, nothing twice.
    $rest = $this->withHeaders($h)->getJson("/api/twa/training/sessions/{$opened['session_id']}/next")->assertOk()->json();
    wstPlay($this, $h, ['session_id' => $opened['session_id'], 'card' => $rest['card']]);
    $credits = ScoreCredit::query()->where('learner_id', $learner->id)->orderBy('word_id')->get();
    expect($credits->where('kind', ScoreKind::Long)->pluck('word_id')->values()->all())->toBe($due)
        ->and($credits->pluck('lexeme_id')->duplicates()->all())->toBe([])
        ->and($credits->where('kind', ScoreKind::Review)->count())->toBe(0)
        ->and(LearnerScoreDay::query()->where('learner_id', $learner->id)->sole()->week_long)->toBe(3);
});

it('hands the outcome to the flow: the score of the answer rides in the review outcome', function (): void {
    ['learner' => $learner, 'words' => $words] = wstWorld();
    wstDue($learner, [$words['1.2']['active'][0]], 3);
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create();
    $card = WordRepetition::query()->where('learner_id', $learner->id)->sole();
    $type = app(ExerciseSelector::class)->select($card, Word::query()->findOrFail($card->word_id), Entitlement::fromPlan(Plan::Free, Entitlement::SOURCE_FREE, null, 1), LearnerExerciseSettings::fromLearner($learner), 'daily_review');
    expect($type)->toBe(ExerciseType::Meaning);

    $outcome = app(TrainingFlowService::class)->answer($session, $learner->fresh(), (int) $card->word_id, CardAnswers::correctAnswer(['word_id' => $card->word_id, 'prompt' => ['type' => 'meaning']]), 900);

    expect($outcome->score?->kind)->toBe(ScoreKind::Review)
        ->and($outcome->score?->toApi())->toMatchArray(['kind' => 'review', 'reason' => 'credited', 'base' => 3, 'week' => 3, 'month' => 3]);
});

it('closes the attempt with the legacy quality of a four-option card and with the self-grade of a flash', function (string $how): void {
    ['learner' => $learner, 'words' => $words] = wstWorld();
    $wordId = $words['1.2']['active'][0];
    if ($how === 'legacy reverse') {
        wstDue($learner, [$wordId], 3, repetitions: 3); // the form rung: reverse first
        config(['learning.objective_grading.accept_legacy_quality' => true]);
    } else {
        wstDue($learner, [$wordId], 3, repetitions: 1);
        config(['learning.ladder.enabled' => false]); // the ladder of old: flash below two passes
    }
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create();

    $accepted = app(ReviewSubmissionService::class)->submitQuality($session, $learner->fresh(), $wordId, 5, 900);

    expect($accepted->exercise)->toBe($how === 'legacy reverse' ? ExerciseType::Reverse : ExerciseType::Flash)
        ->and($accepted->score?->reason)->toBe(WordScoreReason::Closed)
        ->and(ScoreCredit::query()->count())->toBe(0)
        ->and(LearnerLexeme::query()->where('learner_id', $learner->id)->sole()->last_attempt_on?->toDateString())->toBe('2026-11-25');
})->with(['legacy reverse', 'flash self-grade']);

it('counts a typo of a typed answer as a success', function (): void {
    config(['learning.ladder.typed_recall_in_free' => true]);
    ['learner' => $learner, 'words' => $words] = wstWorld();
    $wordId = $words['1.2']['active'][0];
    DB::table('words')->where('id', $wordId)->update(['word' => 'remember', 'example' => null]);
    wstDue($learner, [$wordId], 8, repetitions: 4); // the typed rung (interval >= 6): spelling, the word has no example
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create();

    $accepted = app(ReviewSubmissionService::class)->submitAnswer($session, $learner->fresh(), $wordId, 'remembr', 900);

    expect($accepted->exercise)->toBe(ExerciseType::Spelling)
        ->and($accepted->verdict?->quality)->toBe(4)
        ->and($accepted->score?->kind)->toBe(ScoreKind::Long)
        ->and($accepted->score?->weekAdded)->toBe(5);
});

it('serves the checked choice of the translation instead of the flash on the form rung while the points record', function (string $mode, array $expected): void {
    config(['leagues.scoring.mode' => $mode, 'learning.ladder.early' => ['enabled' => true, 'recognition' => 'meaning,reverse,audio', 'form' => 'reverse,audio,flash', 'audio_requires_file' => true]]);
    $selector = new ExerciseSelector(new GapFillBuilder, LadderPolicy::fromConfig());
    $word = new Word(['word' => 'walk', 'example' => 'They walked home.']);
    $word->id = 42;
    $free = Entitlement::fromPlan(Plan::Free, Entitlement::SOURCE_FREE, null, 1);

    // Six grades in a row of a mature card on the form rung (repetitions >= 3, interval below the typed rung), no audio file.
    $served = [];
    $meta = [];
    for ($i = 0; $i < 6; $i++) {
        $card = new WordRepetition(['repetitions' => 3 + $i, 'interval_days' => 3, 'state' => RepetitionState::Review->value, 'meta' => $meta, 'lapses' => 0]);
        $type = $selector->select($card, $word, $free, new LearnerExerciseSettings, 'daily_review');
        $served[] = $type->value;
        $meta = [LadderPolicy::LAST_EXERCISE_META => $type->value];
    }

    expect($served)->toBe($expected);
})->with([
    'off: the form list as configured' => ['off', ['reverse', 'flash', 'reverse', 'flash', 'reverse', 'flash']],
    'shadow' => ['shadow', ['reverse', 'meaning', 'reverse', 'meaning', 'reverse', 'meaning']],
    'live' => ['live', ['reverse', 'meaning', 'reverse', 'meaning', 'reverse', 'meaning']],
]);

it('maps the flash of a configured form list to the meaning only once', function (): void {
    config(['leagues.scoring.mode' => 'live']);

    expect(LadderPolicy::scoringForm([ExerciseType::Reverse, ExerciseType::Flash, ExerciseType::Audio]))->toBe([ExerciseType::Reverse, ExerciseType::Meaning, ExerciseType::Audio])
        ->and(LadderPolicy::scoringForm([ExerciseType::Meaning, ExerciseType::Flash]))->toBe([ExerciseType::Meaning])
        ->and(LadderPolicy::scoringForm([ExerciseType::Flash]))->toBe([ExerciseType::Meaning]);

    config(['leagues.scoring.mode' => 'off']);
    expect(LadderPolicy::scoringForm([ExerciseType::Reverse, ExerciseType::Flash]))->toBe([ExerciseType::Reverse, ExerciseType::Flash]);
});
