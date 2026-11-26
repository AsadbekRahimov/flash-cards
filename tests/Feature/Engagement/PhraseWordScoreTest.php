<?php

declare(strict_types=1);

/*
 * F5 / docs/35 §3.3 f: a phrase earns the word points exactly as a word (docs/32 §3.2) - the import made it a lexeme, nothing in the
 * core knows phrases: a lesson confirms a new phrase once (+2), a due phrase of a daily review pays a long repetition once (a replay is
 * a 409 that credits nothing), a wrong key word closes the attempt of the day; "A piece of cake" and "piece of cake" are one lexeme.
 */

use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\WordScoreReason;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\ScoreCredit;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CardAnswers;
use Tests\Support\PhraseKit;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.versions.2.starts_on' => '2026-11-01',
        'billing.plans.free.limits.new_words_per_day' => 20,
        'learning.ladder.typed_recall_in_free' => true,
        'practice.access' => 'all', 'practice.plan' => 'all', 'game.access' => 'off',
    ]);
});

/** @return array{0: Learner, 1: array<string, string>} */
function pwsLearner(Course $course): array
{
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 20, 'interface_locale' => 'ru']);

    return [$learner, ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']]];
}

/** A due phrase on the typed rung, its lexeme confirmed long ago. */
function pwsDue(Learner $learner, Word $phrase): void
{
    WordRepetition::factory()->create([
        'learner_id' => $learner->id, 'word_id' => $phrase->id, 'state' => RepetitionState::Review->value, 'repetitions' => 4,
        'interval_days' => 8, 'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour(),
        'easiness_factor' => 2.5, 'is_hard' => false, 'last_quality' => 4, 'lapses' => 0, 'meta' => [],
    ]);
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $phrase->lexeme_id, 'confirmed_on' => '2026-11-01']);
}

it('confirms each new phrase of a lesson once, as a new word', function (): void {
    [$course, $lesson] = PhraseKit::import();
    [$learner, $h] = pwsLearner($course);

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id])->assertOk()->json();
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $card = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json('card');
    }

    $credits = ScoreCredit::query()->where('learner_id', $learner->id)->get();
    expect($credits)->toHaveCount(12)
        ->and($credits->pluck('kind')->unique()->all())->toBe([ScoreKind::New])
        ->and($credits->pluck('lexeme_id')->unique())->toHaveCount(12)
        ->and($credits->sum('week_points'))->toBe(24);
});

it('pays the long repetition of a due phrase once: a replay of the answer is a 409 that credits nothing', function (): void {
    [$course, , $phrases] = PhraseKit::import();
    [$learner, $h] = pwsLearner($course);
    pwsDue($learner, $phrases[0]);

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $card = $opened['card'];
    expect($card['word_id'])->toBe($phrases[0]->id)
        ->and($card['prompt']['type'])->toBe('gap_fill');
    $body = CardAnswers::body($card);
    $answered = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", $body)->assertOk()->json();
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", $body)->assertStatus(409)->assertJsonPath('error.code', 'card_not_due');

    expect($answered['score'])->toMatchArray(['kind' => 'long', 'reason' => 'credited', 'week' => 5])
        ->and(ScoreCredit::query()->where('learner_id', $learner->id)->sole()->kind)->toBe(ScoreKind::Long);
});

it('closes the attempt of the day of a phrase with a wrong key word', function (): void {
    [$course, , $phrases] = PhraseKit::import();
    [$learner] = pwsLearner($course);
    pwsDue($learner, $phrases[0]);
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create();

    $accepted = app(ReviewSubmissionService::class)->submitAnswer($session, $learner->fresh(), $phrases[0]->id, 'stay', 900);

    expect($accepted->exercise)->toBe(ExerciseType::GapFill)
        ->and($accepted->score?->reason)->toBe(WordScoreReason::Closed)
        ->and(ScoreCredit::query()->count())->toBe(0)
        ->and(LearnerLexeme::query()->where('learner_id', $learner->id)->sole()->last_attempt_on?->toDateString())->toBe('2026-11-25');
});

it('makes "A piece of cake" of another phrase course the same lexeme as "a piece of cake": one attempt a day for both', function (): void {
    PhraseKit::import();
    Course::factory()->published()->create(['slug' => 'phrases-other', 'exam_tag' => 'phrases']);
    $report = app(VocabularyImporter::class)->importAsSystem(json_encode([
        'course' => 'phrases-other', 'stage' => ['number' => 1], 'lesson' => ['number' => 1],
        'words' => [['word' => 'Piece of cake!', 'part_of_speech' => 'phrase', 'translation' => 'проще простого', 'phrase' => ['kind' => 'idiom', 'register' => 'informal', 'gap' => 'cake']]],
    ], JSON_THROW_ON_ERROR));

    expect($report->ok())->toBeTrue()
        ->and(Word::query()->where('word', 'Piece of cake!')->value('lexeme_id'))->toBe(Word::query()->where('word', 'a piece of cake')->value('lexeme_id'))
        ->and(Word::query()->where('word', 'Piece of cake!')->value('lexeme_id'))->not->toBeNull();
});
