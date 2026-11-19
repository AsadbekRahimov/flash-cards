<?php

declare(strict_types=1);

use App\Domain\Learning\Services\CorrectedMistakeFinder;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/*
 * "Earlier you got «X» wrong - today you answered it right" on the finish of a round of hard words (docs/06 §14, audit 2026-11-18 §3):
 * the pair is trusted only when both sides are objective, the mistake is of an EARLIER session and the FIRST grade of the word in the
 * round is a pass. Runs on sqlite and on PostgreSQL alike (one statement with window functions and a join on the review ids).
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 05:00:00', 'UTC'));
});

function cmfSession(Learner $learner, string $mode = TrainingSession::MODE_HARD_REVIEW, ?CarbonImmutable $at = null): TrainingSession
{
    $at ??= CarbonImmutable::now();

    return TrainingSession::query()->create([
        'learner_id' => $learner->id, 'mode' => $mode, 'lesson_id' => null, 'course_id' => null, 'telegram_group_id' => null,
        'started_by_user_id' => null, 'source' => 'self', 'status' => 'open', 'started_at' => $at, 'expires_at' => $at->addHours(12),
        'meta' => $mode === TrainingSession::MODE_HARD_REVIEW ? ['hard_words' => []] : [],
    ]);
}

/** One grade (the ids grow in the order of the calls, like the real inserts). */
function cmfGrade(TrainingSession $session, Word $word, int $quality, string $exercise, ?string $answer = null, ?CarbonImmutable $at = null): void
{
    DB::table('training_reviews')->insert([
        'training_session_id' => $session->id, 'learner_id' => $session->learner_id, 'word_id' => $word->id, 'quality' => $quality,
        'exercise' => $exercise, 'answer_text' => $answer, 'scheduler' => 'sm2', 'interval_before_days' => 3, 'time_spent_ms' => 900,
        'created_at' => $at ?? CarbonImmutable::now(),
    ]);
}

function cmfFind(TrainingSession $session, string $locale = 'ru'): ?App\Domain\Learning\DTO\CorrectedMistake
{
    return app(CorrectedMistakeFinder::class)->find($session, $locale);
}

it('finds the word failed objectively in an earlier session and answered right at its first grade in the round', function (): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create(['word' => 'borrow', 'translation' => 'занимать']);
    $earlier = cmfSession($learner, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(3));
    cmfGrade($earlier, $word, 1, 'spelling', 'borow', CarbonImmutable::parse('2026-11-13 07:30:00', 'UTC'));
    $round = cmfSession($learner);
    cmfGrade($round, $word, 4, 'meaning', 'занимать');

    $found = null;
    $log = QueryLog::during(function () use (&$found, $round): void {
        $found = cmfFind($round);
    });

    expect($found)->not->toBeNull()
        ->and($found?->toArray())->toBe([
            'word_id' => $word->id, 'word' => 'borrow', 'translation' => 'занимать',
            'mistaken_at' => '2026-11-13T07:30:00+00:00', 'answer' => 'borow',
        ])
        // ONE statement reads the reviews; the translation of a word nobody read yet is one more (warm in the real round).
        ->and(QueryLog::matching($log, '/training_reviews/'))->toHaveCount(1)
        ->and(count($log))->toBeLessThanOrEqual(2);
});

it('does not count a mistake made earlier in the same session', function (): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create();
    $other = Word::factory()->create();
    $round = cmfSession($learner);
    cmfGrade($round, $other, 4, 'reverse');
    cmfGrade($round, $word, 1, 'reverse');
    cmfGrade($round, $word, 4, 'reverse');

    expect(cmfFind($round))->toBeNull();
});

it('does not trust an earlier mistake that was a self-grade or a first exposure', function (string $exercise): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create();
    $earlier = cmfSession($learner, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(2));
    cmfGrade($earlier, $word, 1, $exercise);
    $round = cmfSession($learner);
    cmfGrade($round, $word, 5, 'meaning');

    expect(cmfFind($round))->toBeNull();
})->with(['flash', 'pretest']);

it('does not trust a first grade of the round that was a self-grade', function (): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create();
    $earlier = cmfSession($learner, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(2));
    cmfGrade($earlier, $word, 1, 'meaning');
    $round = cmfSession($learner);
    cmfGrade($round, $word, 4, 'flash');

    expect(cmfFind($round))->toBeNull();
});

it('does not tell a correction when the first grade in the round failed and a later one passed', function (): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create();
    $earlier = cmfSession($learner, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(2));
    cmfGrade($earlier, $word, 1, 'reverse', 'lend');
    $round = cmfSession($learner);
    cmfGrade($round, $word, 1, 'reverse', 'lend');
    cmfGrade($round, $word, 4, 'reverse');

    expect(cmfFind($round))->toBeNull();
});

it('prefers the most recent earlier mistake among the corrected words', function (): void {
    $learner = Learner::factory()->create();
    $old = Word::factory()->create(['word' => 'old']);
    $recent = Word::factory()->create(['word' => 'recent']);
    $s1 = cmfSession($learner, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(6));
    cmfGrade($s1, $old, 2, 'meaning', null, CarbonImmutable::now()->subDays(6));
    $s2 = cmfSession($learner, TrainingSession::MODE_LESSON, CarbonImmutable::now()->subDays(2));
    cmfGrade($s2, $recent, 1, 'audio', null, CarbonImmutable::now()->subDays(2));
    cmfGrade($s2, $old, 4, 'meaning', null, CarbonImmutable::now()->subDays(2)); // a later pass does not erase the mistake
    $round = cmfSession($learner);
    cmfGrade($round, $old, 4, 'reverse');
    cmfGrade($round, $recent, 4, 'reverse');

    $found = cmfFind($round);

    expect($found?->word)->toBe('recent')
        ->and($found?->mistakenAt->toIso8601String())->toBe(CarbonImmutable::now()->subDays(2)->toIso8601String())
        // A picked option of a four-option card is not what the learner typed.
        ->and($found?->answer)->toBeNull();
});

it('ignores the reviews of another learner', function (): void {
    $learner = Learner::factory()->create();
    $stranger = Learner::factory()->create();
    $word = Word::factory()->create();
    cmfGrade(cmfSession($stranger, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDay()), $word, 1, 'meaning');
    $round = cmfSession($learner);
    cmfGrade($round, $word, 4, 'meaning');

    expect(cmfFind($round))->toBeNull();
});

it('gives the translation in the requested locale, with the fallback when it has none', function (): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create(['word' => 'borrow', 'translation' => 'занимать']);
    WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => 'qarz olmoq']);
    $plain = Word::factory()->create(['word' => 'lend', 'translation' => 'одалживать']);
    $earlier = cmfSession($learner, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(2));
    cmfGrade($earlier, $word, 1, 'meaning');
    $round = cmfSession($learner);
    cmfGrade($round, $word, 4, 'meaning');

    expect(cmfFind($round, 'uz')?->translation)->toBe('qarz olmoq')
        ->and(cmfFind($round, 'ru')?->translation)->toBe('занимать');

    $learner2 = Learner::factory()->create();
    cmfGrade(cmfSession($learner2, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(2)), $plain, 1, 'reverse');
    $round2 = cmfSession($learner2);
    cmfGrade($round2, $plain, 4, 'reverse');

    expect(cmfFind($round2, 'uz')?->translation)->toBe('одалживать'); // no uz row: the ru fallback
});

it('shows the typed answer only when it is not empty and not the right word', function (?string $typed, string $exercise, ?string $shown): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create(['word' => 'Borrow']);
    $earlier = cmfSession($learner, TrainingSession::MODE_DAILY_REVIEW, CarbonImmutable::now()->subDays(2));
    cmfGrade($earlier, $word, 1, $exercise, $typed);
    $round = cmfSession($learner);
    cmfGrade($round, $word, 4, 'spelling', 'borrow');

    expect(cmfFind($round)?->answer)->toBe($shown);
})->with([
    'a typo' => ['borow', 'spelling', 'borow'],
    'trimmed' => ['  barrow ', 'gap_fill', 'barrow'],
    'empty' => ['   ', 'spelling', null],
    'none' => [null, 'spelling', null],
    'the word itself (a gap-fill expecting a form)' => [' borrow', 'gap_fill', null],
    'a picked option' => ['lend', 'reverse', null],
]);

it('answers null for a session without grades', function (): void {
    expect(cmfFind(cmfSession(Learner::factory()->create())))->toBeNull();
});
