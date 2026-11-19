<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Engagement\Support\LeaguePoints;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CardAnswers;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/*
 * A round of hard words over HTTP (`hard_review`, docs/06 §14, docs/04 A.3): opened with POST /training/sessions, played with the ordinary
 * /next and /review, closed with /finish. Its grades are repetitions like any other: points, streak and the day's statistics come
 * with them; the round brings no new word and spends none of the Free quota.
 */

beforeEach(function (): void {
    // 2026-11-16 10:00 in Tashkent; the learner's day began 2026-11-15 19:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 05:00:00', 'UTC'));
    config([
        'learning.hard_round' => ['enabled' => true, 'words' => 10, 'min_words' => 6, 'per_day' => 1, 'lapses_from' => 2, 'due_within_days' => 2],
        'practice.access' => 'all', 'practice.plan' => 'all', 'game.access' => 'off',
    ]);
});

function hardRoundFlowHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A learner with a course of thirty words and `$hard` hard words (released, two lapses, due tomorrow, graded three days ago).
 *
 * @return array{learner: Learner, words: list<int>, headers: array<string, string>}
 */
function hardRoundFlowWorld(int $hard = 10, bool $pro = false, bool $audio = false): array
{
    ['course' => $course, 'words' => $ids] = Fx::course([1 => [1 => 30]]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 10]);
    $words = $ids['1.1']['active'];

    foreach (array_slice($words, 0, $hard) as $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => RepetitionState::Review->value,
            'repetitions' => 4, 'interval_days' => 10, 'easiness_factor' => 2.5, 'lapses' => 2, 'is_hard' => false,
            'next_review_at' => now()->addDay(), 'last_reviewed_at' => now()->subDays(3), 'last_quality' => 4,
            'introduced_mode' => 'daily_review', 'served_ahead_token' => null, 'meta' => [], 'created_at' => now()->subDays(5),
        ]);
    }
    if ($audio) {
        Storage::fake('public');
        foreach (Word::query()->whereIn('id', $words)->get() as $word) {
            $word->update(['audio_path' => app(WordAudioService::class)->pathFor($word->word)]);
        }
    }
    if ($pro) {
        PracticeKit::pro($learner);
    }

    return ['learner' => $learner, 'words' => $words, 'headers' => hardRoundFlowHeaders($learner)];
}

/** @return array<string, mixed> */
function hardRoundFlowOpen(object $test, array $headers, string $source = 'self'): array
{
    return $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'hard_review', 'source' => $source])->assertOk()->json();
}

/**
 * Answers the cards of an opened round until none is left (or `$limit` answers).
 *
 * @param  Closure(array<string, mixed>, int): int|null  $quality  the quality of the n-th answer for a card; 4 (right) by default
 * @return array{types: list<string>, done: list<int>, answers: int, last: array<string, mixed>, words: list<int>}
 */
function hardRoundFlowPlay(object $test, array $headers, array $opened, ?Closure $quality = null, int $limit = 80): array
{
    $url = "/api/twa/training/sessions/{$opened['session_id']}";
    $response = $opened;
    $types = [];
    $done = [$opened['progress']['done']];
    $words = [];
    $n = 0;

    while ($response['card'] !== null && $n < $limit) {
        $card = $response['card'];
        $types[] = $card['prompt']['type'];
        $words[] = (int) $card['word_id'];
        $q = $quality === null ? 4 : $quality($card, $n);
        $response = $test->withHeaders($headers)->postJson("{$url}/review", CardAnswers::body($card, $q))->assertOk()->json();
        $done[] = $response['progress']['done'];
        $n++;
    }

    return ['types' => $types, 'done' => $done, 'answers' => $n, 'last' => $response, 'words' => $words];
}

it('plays a whole round: ten words, no flash card, a failure repeated in the round, then the end and the summary', function (bool $pro, bool $audio): void {
    ['learner' => $learner, 'words' => $words, 'headers' => $h] = hardRoundFlowWorld(10, $pro, $audio);
    $rowsBefore = WordRepetition::query()->where('learner_id', $learner->id)->count();
    $quotaBefore = $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json('new_words_remaining');

    $opened = hardRoundFlowOpen($this, $h);

    expect($opened['mode'])->toBe('hard_review')->and($opened['lesson'])->toBeNull()->and($opened['total_words'])->toBe(10)
        ->and($opened['progress'])->toBe(['done' => 0, 'total' => 10])
        ->and($opened['quota']['new_words_remaining'])->toBeNull()
        ->and($opened['card'])->not->toBeNull()->and($opened['done_reason'])->toBeNull()
        ->and(array_slice($words, 0, 10))->toContain((int) $opened['card']['word_id']);

    $first = null;
    $play = hardRoundFlowPlay($this, $h, $opened, function (array $card, int $n) use (&$first): int {
        if ($n === 0) {
            $first = (int) $card['word_id'];

            return 2; // the first card fails once
        }

        return 4;
    });

    // Ten words and the failed one once more; every card an objective one; the count of won words never falls.
    expect($play['answers'])->toBe(11)
        ->and($play['words'])->toHaveCount(11)->and(array_count_values($play['words'])[$first])->toBe(2)
        ->and($play['types'])->each->not->toBe('flash')
        ->and($play['done'])->toBe(collect($play['done'])->sort()->values()->all())
        ->and(end($play['done']))->toBe(10)
        ->and($play['last']['card'])->toBeNull()->and($play['last']['done_reason'])->toBe('hard_round_complete')->and($play['last']['can_continue'])->toBeFalse()
        ->and($play['last']['quota']['new_words_remaining'])->toBeNull();

    $finish = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk()->json();
    $stats = LearnerDailyStats::query()->where('learner_id', $learner->id)->sole();

    expect($finish['hard_round'])->toBe(['total' => 10, 'won' => 10])
        ->and($finish['hard_round_offer'])->toBeNull()
        ->and($finish['corrected'])->toBeNull() // no mistake in an earlier session: the failed word failed in this round only
        ->and($finish['reviewed'])->toBe(11)->and($finish['correct'])->toBe(10)->and($finish['new_words'])->toBe(0)
        ->and($finish['practice_entry'])->toBeNull()
        // The ordinary points: a grade, a right answer, the goal of ten met in this session; no new word.
        ->and($finish['league_points'])->toBe(LeaguePoints::ofCounts(11, 10, 0, true))->and($finish['league_points'])->toBe(11 + 10 + 10)
        ->and($finish['goal'])->toBe(['target' => 10, 'done' => 11, 'met' => true])
        ->and($stats->reviews_count)->toBe(11)->and($stats->correct_count)->toBe(10)->and($stats->new_words_count)->toBe(0)
        // The round took no new word and spent none of the quota.
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe($rowsBefore)
        ->and(TrainingSession::query()->findOrFail($opened['session_id'])->done_reason)->toBe('finished');

    $today = $this->withHeaders($h)->getJson('/api/twa/today')->assertOk();
    $today->assertJsonPath('hard_round.available', false)->assertJsonPath('hard_round.reason', 'played_today')
        ->assertJsonPath('hard_round.resets_at', '2026-11-16T19:00:00.000Z')->assertJsonPath('new_words_remaining', $quotaBefore);

    // The words answered in the round are not due in the daily round: a grade of one again would be the second pump of the day.
    $daily = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$daily['session_id']}/review", ['word_id' => $first, 'quality' => 4, 'time_spent_ms' => 800])
        ->assertStatus(409)->assertJsonPath('error.code', 'card_not_due');
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", ['word_id' => $first, 'quality' => 4, 'time_spent_ms' => 800])
        ->assertStatus(410)->assertJsonPath('error.code', 'session_closed');
})->with([
    'free, no audio' => [false, false],
    'free, with audio files' => [false, true],
    'pro, no audio' => [true, false],
    'pro, with audio files' => [true, true],
]);

it('ends with nine of ten when the learner stops while the failed word has not come back right', function (): void {
    ['learner' => $learner, 'headers' => $h] = hardRoundFlowWorld();
    $opened = hardRoundFlowOpen($this, $h);
    $url = "/api/twa/training/sessions/{$opened['session_id']}";
    $failed = (int) $opened['card']['word_id'];

    $response = $this->withHeaders($h)->postJson("{$url}/review", CardAnswers::body($opened['card'], 2))->assertOk()->json();
    $done = [$response['progress']['done']];
    // The nine others are right; the failed card waits for its minute step and comes last.
    for ($i = 0; $i < 9; $i++) {
        expect((int) $response['card']['word_id'])->not->toBe($failed);
        $response = $this->withHeaders($h)->postJson("{$url}/review", CardAnswers::body($response['card'], 4))->assertOk()->json();
        $done[] = $response['progress']['done'];
    }
    expect($done)->toBe([0, 1, 2, 3, 4, 5, 6, 7, 8, 9])
        ->and((int) $response['card']['word_id'])->toBe($failed)->and($response['card']['card_kind'])->toBe('learning')->and($response['progress'])->toBe(['done' => 9, 'total' => 10]);

    // The failed card fails once more and the learner leaves: its LAST grade is a miss.
    $response = $this->withHeaders($h)->postJson("{$url}/review", CardAnswers::body($response['card'], 2))->assertOk()->json();
    expect($response['progress']['done'])->toBe(9);

    $finish = $this->withHeaders($h)->postJson("{$url}/finish")->assertOk()->json();
    $event = ProductEvent::query()->where('name', ProductEventName::TrainingSessionFinished->value)->sole();

    expect($finish['hard_round'])->toBe(['total' => 10, 'won' => 9])->and($finish['reviewed'])->toBe(11)->and($finish['correct'])->toBe(9)
        ->and($event->props)->toMatchArray(['mode' => 'hard_review', 'hard_won' => 9, 'hard_total' => 10, 'new_words' => 0])
        ->and(ProductEvent::query()->where('name', ProductEventName::TrainingSessionOpened->value)->sole()->props)->toMatchArray(['mode' => 'hard_review', 'source' => 'self', 'reused' => false])
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $failed)->sole()->state)->toBe(RepetitionState::Relearning);
});

it('plays the round with the early ladder off: still no flash card', function (): void {
    config(['learning.ladder.early' => ['enabled' => false]]);
    ['headers' => $h] = hardRoundFlowWorld();

    $play = hardRoundFlowPlay($this, $h, hardRoundFlowOpen($this, $h));

    expect($play['answers'])->toBe(10)->and($play['types'])->each->not->toBe('flash')
        ->and($play['last']['done_reason'])->toBe('hard_round_complete');
});

it('gives a ten-word round to a learner with twelve hard words and leaves the other two for another day', function (): void {
    ['learner' => $learner, 'words' => $words, 'headers' => $h] = hardRoundFlowWorld(12);

    $opened = hardRoundFlowOpen($this, $h);
    $set = TrainingSession::query()->findOrFail($opened['session_id'])->meta['hard_words'] ?? [];

    expect($set)->toHaveCount(10)->and($opened['progress']['total'])->toBe(10)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->where('next_review_at', '>', now())->count())->toBe(2)
        ->and(array_diff(array_slice($words, 0, 12), $set))->toHaveCount(2);
});

// --- the blocks ---------------------------------------------------------------------------------------------

it('shows the block on Today (five words: not enough, six: available): not enough, available, in progress with its session, played today', function (): void {
    ['learner' => $learner, 'headers' => $h] = hardRoundFlowWorld(5);

    $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('hard_round', ['available' => false, 'reason' => 'not_enough', 'words' => 0, 'open_session_id' => null, 'resets_at' => null]);

    ['headers' => $h2] = (function () use ($learner): array {
        $word = Word::factory()->create();
        WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'state' => 'review', 'repetitions' => 3, 'interval_days' => 6, 'lapses' => 3, 'is_hard' => true, 'next_review_at' => now()->addDay(), 'last_reviewed_at' => now()->subDays(2), 'served_ahead_token' => null, 'meta' => []]);

        return ['headers' => hardRoundFlowHeaders($learner)];
    })();
    $this->withHeaders($h2)->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('hard_round', ['available' => true, 'reason' => null, 'words' => 6, 'open_session_id' => null, 'resets_at' => null]);

    $opened = hardRoundFlowOpen($this, $h2);
    $today = $this->withHeaders($h2)->getJson('/api/twa/today')->assertOk();
    $today->assertJsonPath('hard_round', ['available' => true, 'reason' => null, 'words' => 6, 'open_session_id' => $opened['session_id'], 'resets_at' => null])
        ->assertJsonPath('open_session_id', null); // the daily round's own key is not the hard one

    $this->withHeaders($h2)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();
    $this->withHeaders($h2)->getJson('/api/twa/today')->assertOk()->assertJsonPath('hard_round.reason', 'played_today');
});

it('invites to the round on the end of a daily round and a lesson, and not on the end of the round itself', function (): void {
    ['learner' => $learner, 'words' => $words, 'headers' => $h] = hardRoundFlowWorld();
    $daily = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();

    $offer = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$daily['session_id']}/finish")->assertOk();
    $offer->assertJsonPath('hard_round', null)
        ->assertJsonPath('hard_round_offer', ['available' => true, 'reason' => null, 'words' => 10, 'open_session_id' => null, 'resets_at' => null]);

    // `source: finish` is the one the client sends from that button.
    $opened = hardRoundFlowOpen($this, $h, 'finish');
    expect(TrainingSession::query()->findOrFail($opened['session_id'])->source)->toBe('finish');

    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk()
        ->assertJsonPath('hard_round_offer', null)->assertJsonPath('hard_round.total', 10);

    // A lesson's end, with the round played already today: the offer says so.
    $lesson = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => Word::query()->findOrFail($words[0])->lesson_id])->assertOk()->json();
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$lesson['session_id']}/finish")->assertOk()
        ->assertJsonPath('hard_round_offer.available', false)->assertJsonPath('hard_round_offer.reason', 'played_today');
    expect($learner->id)->toBeInt();
});

// --- the refusals -------------------------------------------------------------------------------------------

it('answers 409 hard_round_unavailable with the reason, 422 for a lesson and for a source it does not know', function (): void {
    ['headers' => $h] = hardRoundFlowWorld(5);

    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'hard_review'])
        ->assertStatus(409)->assertJsonPath('error.code', 'hard_round_unavailable')
        ->assertJsonPath('error.details', ['reason' => 'not_enough', 'resets_at' => null]);
    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'hard_review', 'lesson_id' => 999999])
        ->assertStatus(422)->assertJsonPath('error.code', 'lesson_mismatch');
    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'hard_review', 'source' => 'nowhere'])->assertStatus(422);
    expect(TrainingSession::query()->count())->toBe(0);
});

it('answers 409 played_today with the end of the window on a second opening after the round was finished', function (): void {
    ['headers' => $h] = hardRoundFlowWorld();
    $opened = hardRoundFlowOpen($this, $h);
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'hard_review'])
        ->assertStatus(409)->assertJsonPath('error.details', ['reason' => 'played_today', 'resets_at' => '2026-11-16T19:00:00.000Z']);
});

it('is idempotent for the live round of the day: two opens, one session, the second sees the progress', function (): void {
    ['headers' => $h] = hardRoundFlowWorld();
    $first = hardRoundFlowOpen($this, $h);
    $answered = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$first['session_id']}/review", CardAnswers::body($first['card'], 4))->assertOk()->json();

    $second = hardRoundFlowOpen($this, $h, 'finish');

    expect($second['session_id'])->toBe($first['session_id'])->and($second['progress'])->toBe(['done' => 1, 'total' => 10])
        ->and($second['card']['word_id'])->toBe($answered['card']['word_id']);
});

it('switches the whole feature off with the flag: no block on Today, no offer, no opening', function (): void {
    config(['learning.hard_round.enabled' => false]);
    ['learner' => $learner, 'words' => $words, 'headers' => $h] = hardRoundFlowWorld();

    $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->assertJsonPath('hard_round', null);
    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'hard_review'])
        ->assertStatus(409)->assertJsonPath('error.code', 'hard_round_unavailable')->assertJsonPath('error.details.reason', 'disabled');
    $daily = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$daily['session_id']}/finish")->assertOk()->assertJsonPath('hard_round_offer', null);

    expect(TrainingSession::query()->where('learner_id', $learner->id)->where('mode', 'hard_review')->count())->toBe(0)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->whereIn('word_id', array_slice($words, 0, 10))->where('next_review_at', '<=', now())->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::HardRoundRefused->value)->sole()->props)->toMatchArray(['reason' => 'disabled']);
});

// --- the scope in the grade -----------------------------------------------------------------------------------

it('does not serve nor accept a word of the set that was withdrawn, nor a word outside the set', function (): void {
    ['words' => $words, 'headers' => $h] = hardRoundFlowWorld(10);
    $opened = hardRoundFlowOpen($this, $h);
    $url = "/api/twa/training/sessions/{$opened['session_id']}";
    $out = (int) $words[20];
    WordRepetition::factory()->create(['learner_id' => Learner::query()->firstOrFail()->id, 'word_id' => $out, 'state' => 'review', 'repetitions' => 4, 'interval_days' => 10, 'lapses' => 0, 'next_review_at' => now()->subDay(), 'last_reviewed_at' => now()->subDays(3), 'served_ahead_token' => null, 'meta' => []]);
    $withdrawn = (int) $opened['card']['word_id'];
    Word::query()->whereKey($withdrawn)->update(['is_active' => false]);

    $this->withHeaders($h)->postJson("{$url}/review", ['word_id' => $withdrawn, 'quality' => 4, 'time_spent_ms' => 500])->assertStatus(422)->assertJsonPath('error.code', 'out_of_scope');
    $this->withHeaders($h)->postJson("{$url}/review", ['word_id' => $out, 'quality' => 4, 'time_spent_ms' => 500])->assertStatus(422)->assertJsonPath('error.code', 'out_of_scope');

    $play = hardRoundFlowPlay($this, $h, ['session_id' => $opened['session_id']] + $this->withHeaders($h)->getJson("{$url}/next")->assertOk()->json());
    expect($play['words'])->not->toContain($withdrawn)->and($play['words'])->not->toContain($out)->and($play['words'])->toHaveCount(9);
});

it('counts a word of the set that the daily round graded meanwhile as not won, and ends the round without it', function (): void {
    ['learner' => $learner, 'headers' => $h] = hardRoundFlowWorld();
    $opened = hardRoundFlowOpen($this, $h);
    $daily = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    // The pulled words are due now, so the daily round may take one of them first (docs/06 §14, risk A2).
    $taken = (int) $daily['card']['word_id'];
    $inSet = in_array($taken, TrainingSession::query()->findOrFail($opened['session_id'])->meta['hard_words'] ?? [], true);

    expect($inSet)->toBeTrue(); // the pulled words are the only cards due: the daily round takes one of them
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$daily['session_id']}/review", CardAnswers::body($daily['card'], 4))->assertOk();

    $play = hardRoundFlowPlay($this, $h, ['session_id' => $opened['session_id']] + $this->withHeaders($h)->getJson("/api/twa/training/sessions/{$opened['session_id']}/next")->assertOk()->json());
    $finish = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk()->json();

    expect($play['words'])->not->toContain($taken)->and($play['last']['done_reason'])->toBe('hard_round_complete')
        ->and($finish['hard_round'])->toBe(['total' => 10, 'won' => 9])
        ->and($learner->id)->toBeInt();
});

it('shows a card as flash, as the card builder does, when the word has too few options (decks of one word, outside the platform)', function (): void {
    // Eight words, each alone in its course and lesson: nothing to offer as the other options, so the card falls back to flash
    // (ExercisePromptBuilder::effectiveType) - rare, known and taken (docs/06 §14, risk A5).
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 10]);
    for ($i = 0; $i < 8; $i++) {
        $word = Fx::course([1 => [1 => 1]], ['visibility' => 'private'])['words']['1.1']['active'][0];
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $word, 'state' => 'review', 'repetitions' => 4, 'interval_days' => 10, 'lapses' => 2, 'is_hard' => false,
            'next_review_at' => now()->addDay(), 'last_reviewed_at' => now()->subDays(3), 'served_ahead_token' => null, 'meta' => [], 'created_at' => now()->subDays(5),
        ]);
    }
    $h = hardRoundFlowHeaders($learner);

    $opened = hardRoundFlowOpen($this, $h);

    expect($opened['card']['prompt']['type'])->toBe('flash')->and($opened['progress']['total'])->toBe(8);
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($opened['card'], 4))->assertOk()
        ->assertJsonPath('progress.done', 1);
});

// --- the corrected mistake ---------------------------------------------------------------------------------------

/** A wrong answer to the word in a closed daily round three days ago (an earlier session: its review ids are below the round's). */
function hardRoundFlowEarlierMistake(Learner $learner, int $wordId, string $exercise = 'spelling', ?string $answer = 'wrnog'): void
{
    $at = now()->subDays(3);
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->closed()->create(['started_at' => $at, 'ended_at' => $at]);
    DB::table('training_reviews')->insert([
        'training_session_id' => $session->id, 'learner_id' => $learner->id, 'word_id' => $wordId, 'quality' => 1, 'exercise' => $exercise,
        'answer_text' => $answer, 'scheduler' => 'sm2', 'interval_before_days' => 4, 'time_spent_ms' => 2000, 'created_at' => $at,
    ]);
}

it('tells on the finish one word failed in an earlier session and answered right in the round, the typed answer with it', function (): void {
    ['learner' => $learner, 'words' => $words, 'headers' => $h] = hardRoundFlowWorld();
    $word = Word::query()->findOrFail($words[3]);
    hardRoundFlowEarlierMistake($learner, (int) $word->id);

    $opened = hardRoundFlowOpen($this, $h);
    hardRoundFlowPlay($this, $h, $opened);
    $url = "/api/twa/training/sessions/{$opened['session_id']}/finish";
    $finish = $this->withHeaders($h)->postJson($url)->assertOk()->json();

    expect($finish['corrected'])->toBe([
        'word_id' => $word->id,
        'word' => $word->word,
        'translation' => app(WordTranslationResolver::class)->for($word, 'ru'),
        'mistaken_at' => now()->subDays(3)->utc()->toIso8601String(),
        'answer' => 'wrnog',
    ])
        ->and($finish['hard_round'])->toBe(['total' => 10, 'won' => 10])
        // Idempotent: a repeat of /finish tells the same word.
        ->and($this->withHeaders($h)->postJson($url)->assertOk()->json('corrected.word_id'))->toBe($word->id);
});

it('tells nothing when the earlier mistake was a self-grade, or the word failed first in the round', function (string $case): void {
    ['learner' => $learner, 'words' => $words, 'headers' => $h] = hardRoundFlowWorld();
    $wordId = (int) $words[3];
    hardRoundFlowEarlierMistake($learner, $wordId, $case === 'flash' ? 'flash' : 'meaning', null);

    $opened = hardRoundFlowOpen($this, $h);
    $failed = false;
    hardRoundFlowPlay($this, $h, $opened, function (array $card) use ($case, $wordId, &$failed): int {
        if ($case === 'failed first' && (int) $card['word_id'] === $wordId && ! $failed) {
            $failed = true;

            return 2;
        }

        return 4;
    });
    $finish = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk()->json();

    expect($finish['corrected'])->toBeNull()->and($finish['hard_round']['won'])->toBe(10);
})->with(['flash', 'failed first']);

it('does not look for a corrected mistake on the finish of a daily round', function (): void {
    ['learner' => $learner, 'words' => $words, 'headers' => $h] = hardRoundFlowWorld();
    hardRoundFlowEarlierMistake($learner, (int) $words[3]);
    $daily = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$daily['session_id']}/review", CardAnswers::body($daily['card'], 4))->assertOk();

    $finish = null;
    $log = QueryLog::during(function () use (&$finish, $daily, $h): void {
        $finish = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$daily['session_id']}/finish")->assertOk()->json();
    });

    expect($finish)->toHaveKey('corrected')->and($finish['corrected'])->toBeNull()
        ->and(QueryLog::matching($log, '/from ["`]training_reviews["`]/'))->toHaveCount(1);
});

// --- the statements ---------------------------------------------------------------------------------------------

it('keeps the round inside the budgets of the training cycle: /next, /review, /finish +1, /today nine', function (): void {
    ['headers' => $h] = hardRoundFlowWorld();
    $opened = hardRoundFlowOpen($this, $h);
    $base = "/api/twa/training/sessions/{$opened['session_id']}";
    $card = $opened['card'];

    // warm: the plan, the word counts; the first grade of the day also places the learner in the league and checks the first achievement
    $card = $this->withHeaders($h)->postJson("{$base}/review", CardAnswers::body($card, 4, 100))->assertOk()->json('card');

    $next = QueryLog::during(function () use ($base, $h): void {
        $this->withHeaders($h)->getJson("{$base}/next")->assertOk();
    });
    // learner (auth), session with access context, the counters (grades and words won in ONE statement), the due pick, nothing about the quota.
    expect(count($next))->toBeLessThanOrEqual(5)
        ->and(QueryLog::matching($next, '/training_reviews/'))->toHaveCount(1)
        ->and(QueryLog::matching($next, '/from ["`]words["`] where/'))->toBe([])
        ->and(QueryLog::matching($next, '/new_words|created_at["`] >= \?/'))->toBe([]);

    $body = CardAnswers::body($card, 4, 100);
    $review = QueryLog::during(function () use ($base, $h, $body): void {
        $this->withHeaders($h)->postJson("{$base}/review", $body)->assertOk();
    });
    // The same statements as a daily review answer without the new-word step: no learner lock, no quota count.
    expect(count($review))->toBeLessThanOrEqual(14)
        ->and(QueryLog::matching($review, '/for no key update$/'))->toBe([])
        ->and(QueryLog::matching($review, '/subscriptions/'))->toBe([]);

    $finish = QueryLog::during(function () use ($base, $h): void {
        $this->withHeaders($h)->postJson("{$base}/finish")->assertOk()->assertJsonPath('hard_round_offer', null);
    });
    // 9 as for any session (the round itself carries no invitation) +1: the corrected mistake (CorrectedMistakeFinder, one statement).
    expect(count($finish))->toBeLessThanOrEqual(10)
        ->and(QueryLog::matching($finish, '/from ["`]training_reviews["`]/'))->toHaveCount(2);
});
