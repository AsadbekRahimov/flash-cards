<?php

declare(strict_types=1);

/*
 * No points of the rating for the words of a learner's own deck (owner's decision of 27.11.2026, docs/32 §2.1, §12.12): the learner
 * writes the translation himself. A deck word has no lexeme (`words.lexeme_id` NULL - the deck never links to the catalog), so its
 * answer writes nothing of the word points - no credit, no day, no `learner_lexemes` - and the same spelling in a course of the platform
 * keeps its points (the attempt of the day is not spent, the "new" is not burnt). The SRS, the daily stats and the goal of the day are
 * the training's as before. Not one statement more than with the points off (the budget: WordScoreQueryBudgetTest).
 */

use App\Domain\Content\DTO\DeckRow;
use App\Domain\Content\Services\LearnerDeckService;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
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
        'leagues.enabled' => false,
        'billing.plans.free.limits.new_words_per_day' => 20,
        'practice.access' => 'all', 'practice.plan' => 'all', 'game.access' => 'off',
    ]);
});

/**
 * A platform course whose words carry lexemes, and a learner with a deck of the SAME spellings (the catalog knows every one of them).
 *
 * @return array{learner: Learner, headers: array<string, string>, deck: Course, deckWords: list<int>, courseWords: list<int>, courseLesson: int}
 */
function dwsWorld(): array
{
    ['course' => $course, 'lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => 4]]);
    $courseWords = $words['1.1']['active'];
    foreach ($courseWords as $wordId) {
        $text = (string) DB::table('words')->where('id', $wordId)->value('word');
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => Lexeme::factory()->create(['key' => mb_strtolower($text)])->id]);
    }
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 10, 'interface_locale' => 'ru']);

    $decks = app(LearnerDeckService::class);
    $deck = $decks->create($learner, 'Мои слова');
    $rows = array_map(fn (int $id): DeckRow => new DeckRow((string) DB::table('words')->where('id', $id)->value('word'), 'мой перевод '.$id), $courseWords);
    $decks->addWords($learner, $deck, $rows);
    $deckWords = Word::query()->whereIn('lesson_id', DB::table('lessons')->join('stages', 'stages.id', '=', 'lessons.stage_id')->where('stages.course_id', $deck->id)->pluck('lessons.id'))
        ->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

    return [
        'learner' => $learner,
        'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']],
        'deck' => $deck,
        'deckWords' => $deckWords,
        'courseWords' => $courseWords,
        'courseLesson' => $lessons['1.1']->id,
    ];
}

/** Plays an open session to its end with right answers; returns the `score` block of every answer. @return list<array<string, mixed>|null> */
function dwsPlay(object $test, array $headers, array $opened): array
{
    $scores = [];
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $answer = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk();
        $scores[] = $answer->json('score');
        $card = $answer->json('card');
    }

    return $scores;
}

/** @return array{credits: int, days: int, lexemes: int, periods: int} */
function dwsScoreRows(Learner $learner): array
{
    return [
        'credits' => DB::table('score_credits')->where('learner_id', $learner->id)->count(),
        'days' => DB::table('learner_score_days')->where('learner_id', $learner->id)->count(),
        'lexemes' => DB::table('learner_lexemes')->where('learner_id', $learner->id)->count(),
        'periods' => DB::table('learner_score_periods')->where('learner_id', $learner->id)->count(),
    ];
}

it('keeps the words of a deck out of the catalog even when the platform has the spelling', function (): void {
    ['deckWords' => $deckWords, 'courseWords' => $courseWords] = dwsWorld();

    expect($deckWords)->toHaveCount(count($courseWords))
        ->and(Word::query()->whereIn('id', $deckWords)->whereNotNull('lexeme_id')->count())->toBe(0)
        ->and(Word::query()->whereIn('id', $courseWords)->whereNull('lexeme_id')->count())->toBe(0);
});

it('gives no points for a lesson of the deck and leaves the same spellings of the platform their points', function (): void {
    ['learner' => $learner, 'headers' => $h, 'deck' => $deck, 'courseWords' => $courseWords, 'courseLesson' => $courseLesson] = dwsWorld();
    $deckLesson = (int) DB::table('lessons')->join('stages', 'stages.id', '=', 'lessons.stage_id')->where('stages.course_id', $deck->id)->value('lessons.id');

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $deckLesson])->assertOk()->json();
    $scores = dwsPlay($this, $h, $opened);

    // Every answer: nothing of the rating - the honest block of a word outside the catalog, never "+N".
    $blocks = array_values(array_filter($scores));
    expect($blocks)->not->toBe([])
        ->and(collect($blocks)->pluck('reason')->unique()->values()->all())->toBe(['no_catalog'])
        ->and(collect($blocks)->sum('week'))->toBe(0)
        ->and(collect($blocks)->sum('month'))->toBe(0)
        ->and(collect($blocks)->pluck('today')->filter()->all())->toBe([])
        ->and(dwsScoreRows($learner))->toBe(['credits' => 0, 'days' => 0, 'lexemes' => 0, 'periods' => 0]);

    // The training is the training: the SRS moved, the day of the learner counted the answers.
    expect(WordRepetition::query()->where('learner_id', $learner->id)->where('state', '<>', RepetitionState::New->value)->count())->toBeGreaterThan(0)
        ->and((int) DB::table('learner_daily_stats')->where('learner_id', $learner->id)->value('reviews_count'))->toBe(count($scores));

    // The same spellings in the course of the platform, the same rating day: every one is still new - confirmed and paid.
    $course = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $courseLesson])->assertOk()->json();
    dwsPlay($this, $h, $course);
    $credits = ScoreCredit::query()->where('learner_id', $learner->id)->get();
    expect($credits)->toHaveCount(count($courseWords))
        ->and($credits->pluck('kind')->unique()->all())->toBe([ScoreKind::New])
        ->and($credits->pluck('word_id')->sort()->values()->all())->toBe($courseWords);
});

it('gives no points for a due repetition of a deck word', function (): void {
    ['learner' => $learner, 'headers' => $h, 'deckWords' => $deckWords] = dwsWorld();
    foreach ($deckWords as $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => RepetitionState::Review->value, 'repetitions' => 2,
            'interval_days' => 8, 'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour(),
            'easiness_factor' => 2.5, 'is_hard' => false, 'last_quality' => 4, 'lapses' => 0, 'meta' => [],
        ]);
    }

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $card = $opened['card'];
    expect(in_array((int) $card['word_id'], $deckWords, true))->toBeTrue();
    $answer = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk();

    expect($answer->json('score'))->toMatchArray(['kind' => null, 'reason' => 'no_catalog', 'base' => 0, 'week' => 0, 'month' => 0, 'today' => null])
        ->and(dwsScoreRows($learner))->toBe(['credits' => 0, 'days' => 0, 'lexemes' => 0, 'periods' => 0]);
});

it('unlinks the deck words a migration finds linked and leaves the courses as they are', function (): void {
    ['deckWords' => $deckWords, 'courseWords' => $courseWords] = dwsWorld();
    // The links of S1 (a deck matched the catalog): the deck word gets the lexeme of the course word of the same spelling.
    foreach ($deckWords as $i => $wordId) {
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => DB::table('words')->where('id', $courseWords[$i])->value('lexeme_id')]);
    }
    $migration = require database_path('migrations/2026_12_01_100001_unlink_deck_words_from_lexemes.php');

    $migration->up();
    expect(Word::query()->whereIn('id', $deckWords)->whereNotNull('lexeme_id')->count())->toBe(0)
        ->and(Word::query()->whereIn('id', $courseWords)->whereNull('lexeme_id')->count())->toBe(0);
    $migration->up(); // a second run changes nothing

    $migration->down();
    expect(Word::query()->whereIn('id', $deckWords)->whereNull('lexeme_id')->count())->toBe(0);
    $migration->up();
    expect(Word::query()->whereIn('id', $deckWords)->whereNotNull('lexeme_id')->count())->toBe(0);
});
