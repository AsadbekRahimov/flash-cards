<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Content\Services\WordHookResolver;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\WordStage;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CardAnswers;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/*
 * The early ladder and the sprint (owner's decision of 2026-11-17, docs/06 §13): a newcomer's first daily round meets each
 * word on a flash card and is then asked about it objectively, in alternating formats; the round ends by itself with
 * `sprint_complete` and `can_continue`; the progress is the round's; the summary tells how the words grew. The old
 * behaviour stays behind `learning.ladder.early.enabled` and `learning.sprint.enabled`.
 *
 * A `config([...])` must come BEFORE the first request of a test (the router keeps the controller it built first).
 */

function spHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A published course: lesson 1 carries the ten words of the delivered A1 course's first lesson (real translations, so the
 * distractors are what a newcomer gets), the other lessons factory words with distinct translations.
 *
 * @return array{course: Course, lessons: list<Lesson>}
 */
function spCourse(int $lessons = 2): array
{
    $course = Course::factory()->published()->create(['visibility' => 'public']);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $list = [];
    for ($n = 1; $n <= $lessons; $n++) {
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $n]);
        if ($n === 1) {
            /** @var array{words: list<array{word: string, translation: string, example: string}>} $data */
            $data = json_decode((string) file_get_contents(base_path('database/data/courses/english-a1-a2/m01-l1.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['words'] as $w) {
                Word::factory()->for($lesson)->create(['word' => $w['word'], 'translation' => $w['translation'], 'example' => $w['example']]);
            }
        } else {
            for ($i = 0; $i < 10; $i++) {
                Word::factory()->for($lesson)->create(['word' => "word{$n}{$i}", 'translation' => "перевод {$n}-{$i}", 'example' => "I like word{$n}{$i} a lot."]);
            }
        }
        $list[] = $lesson;
    }

    return ['course' => $course, 'lessons' => $list];
}

function spLearner(Course $course, bool $pro = false, array $attributes = []): Learner
{
    $learner = Learner::factory()->create(array_replace(['active_course_id' => $course->id, 'timezone' => 'Asia/Tashkent'], $attributes));
    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo']);
    }

    return $learner;
}

/**
 * Opens the daily round and answers every card until the server has none left; `$grade` says how to answer a card
 * (quality >= 3: right). Returns every serve (the card, the progress) and the last answer.
 *
 * @return array{session_id: int, serves: list<array{card: array<string, mixed>, progress: array{done: int, total: int}}>, last: array<string, mixed>}
 */
function spRound(object $test, Learner $learner, ?Closure $grade = null, int $max = 80): array
{
    // Rounds are told apart by the session's start (second precision): a round never opens within the same second as the
    // previous round's last pick in real life — here the clock is moved on.
    $test->travel(1)->seconds();
    $headers = spHeaders($learner);
    $response = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $sessionId = (int) $response['session_id'];
    $serves = [];

    for ($i = 0; $i < $max && $response['card'] !== null; $i++) {
        $card = $response['card'];
        $serves[] = ['card' => $card, 'progress' => $response['progress']];
        $quality = $grade === null ? 4 : $grade($card, $i);
        $response = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$sessionId}/review", CardAnswers::body($card, $quality))->assertOk()->json();
    }

    return ['session_id' => $sessionId, 'serves' => $serves, 'last' => $response];
}

/** Closes the round the way the Mini App does before "one more round". */
function spFinish(object $test, Learner $learner, int $sessionId): array
{
    return $test->withHeaders(spHeaders($learner))->postJson("/api/twa/training/sessions/{$sessionId}/finish")->assertOk()->json();
}

/** A due day-based card of the learner. */
function spDue(Learner $learner, Word $word, int $repetitions, int $intervalDays, array $extra = []): WordRepetition
{
    return WordRepetition::factory()->create(array_replace([
        'learner_id' => $learner->id, 'word_id' => $word->id, 'state' => RepetitionState::Review->value,
        'repetitions' => $repetitions, 'interval_days' => $intervalDays, 'next_review_at' => now()->subHour(),
        'introduced_mode' => 'daily_review', 'is_hard' => false, 'lapses' => 0, 'meta' => [], 'served_ahead_token' => null,
        'created_at' => now()->subDays(max(1, $intervalDays)),
    ], $extra));
}

// --- the first round of a newcomer ------------------------------------------------------------

it('walks a newcomer through the first round: six words met on flash cards, each asked back objectively, then sprint_complete', function (): void {
    ['course' => $course] = spCourse();
    $learner = spLearner($course, attributes: ['daily_goal' => 20]);

    $round = spRound($this, $learner);
    $serves = $round['serves'];
    expect($serves)->toHaveCount(12);

    // The first six serves: the meeting — a flash card with the translation and the example open, nothing else.
    foreach (array_slice($serves, 0, 6) as $i => $serve) {
        expect($serve['card']['card_kind'])->toBe('new', "serve {$i}")
            ->and($serve['card']['prompt']['type'])->toBe('flash')
            ->and($serve['card']['translation'])->not->toBe('')
            ->and($serve['card']['serve_token'])->toBeNull();
    }
    $introduced = array_map(static fn (array $s): int => (int) $s['card']['word_id'], array_slice($serves, 0, 6));
    expect(array_unique($introduced))->toHaveCount(6);

    // Then the checks, served ahead of their 10-minute step: the same six words, the meaning asked with four distinct
    // translations of the A1 lesson, the right one among them, the translation itself hidden.
    foreach (array_slice($serves, 6) as $i => $serve) {
        $card = $serve['card'];
        expect($card['card_kind'])->toBe('learning', "check {$i}")
            ->and($card['prompt']['type'])->toBe('meaning')
            ->and($card['serve_token'])->toBeString()
            ->and($card['translation'])->toBe('')
            ->and($card['prompt']['options'])->toHaveCount(4)
            ->and(array_unique(array_map('mb_strtolower', $card['prompt']['options'])))->toHaveCount(4)
            ->and($card['prompt']['options'])->toContain(CardAnswers::correctAnswer($card));
    }
    expect(array_map(static fn (array $s): int => (int) $s['card']['word_id'], array_slice($serves, 6)))->toBe($introduced);

    // The round's progress: 12 all along (6 words × 2 steps), never run past, closed at 12 / 12.
    foreach ($serves as $i => $serve) {
        expect($serve['progress'])->toBe(['done' => $i, 'total' => 12], "progress at serve {$i}");
    }
    expect($round['last']['card'])->toBeNull()
        ->and($round['last']['progress'])->toBe(['done' => 12, 'total' => 12])
        ->and($round['last']['done_reason'])->toBe('sprint_complete')
        ->and($round['last']['can_continue'])->toBeTrue()
        ->and($round['last']['quota'])->toBe(['new_words_remaining' => 14]);

    // The schedule moved as the grades said: six graduated cards, one day each; one grade per card, no double count.
    expect(WordRepetition::query()->where('learner_id', $learner->id)->where('state', RepetitionState::Review->value)->where('interval_days', 1)->count())->toBe(6)
        ->and(TrainingReview::query()->where('learner_id', $learner->id)->count())->toBe(12)
        ->and(TrainingReview::query()->where('learner_id', $learner->id)->where('exercise', 'meaning')->count())->toBe(6);
    $day = LearnerDailyStats::query()->where('learner_id', $learner->id)->sole();
    expect($day->reviews_count)->toBe(12)->and($day->correct_count)->toBe(12)->and($day->new_words_count)->toBe(6);

    // The summary: six new words, all of them out of the minute steps; the points of the leagues from the same numbers.
    $summary = $this->withHeaders(spHeaders($learner))->postJson("/api/twa/training/sessions/{$round['session_id']}/finish")->assertOk()->json();
    expect($summary)->toMatchArray(['reviewed' => 12, 'correct' => 12, 'new_words' => 6, 'stages' => ['graduated' => 6, 'promoted' => 0, 'mastered' => 0], 'league_points' => 12 + 12 + 6 * 2]);

    // "One more round": a new session with the next six words.
    $second = spRound($this, $learner);
    expect($second['session_id'])->not->toBe($round['session_id'])
        ->and($second['serves'])->toHaveCount(12)
        ->and(array_intersect(array_map(static fn (array $s): int => (int) $s['card']['word_id'], $second['serves']), $introduced))->toBe([])
        ->and($second['last']['done_reason'])->toBe('sprint_complete');
});

it('alternates the formats of a word that fails its checks and moves the learning steps by the objective verdict; "already knew" skips them', function (): void {
    config(['learning.sprint.new_words' => 2]);
    ['course' => $course] = spCourse(1);
    $learner = spLearner($course);

    $types = [];
    $round = spRound($this, $learner, function (array $card, int $i) use (&$types): int {
        $types[$card['word_id']][] = $card['prompt']['type'];

        return match ($i) {
            0 => 2,      // the first word: "did not remember" at the meeting
            1 => 5,      // the second word: "already knew it"
            2 => 2,      // the first check (meaning) wrong
            default => 4,
        };
    });

    [$first, $second] = array_values(array_unique(array_map(static fn (array $s): int => (int) $s['card']['word_id'], $round['serves'])));

    // flash (fail) → meaning (fail) → reverse (pass, step 1) → meaning again (pass, graduate): never the same twice in a row.
    expect($types[$first])->toBe(['flash', 'meaning', 'reverse', 'meaning'])
        ->and($types[$second])->toBe(['flash']);

    $rows = TrainingReview::query()->where('learner_id', $learner->id)->where('word_id', $first)->orderBy('id')->get();
    expect($rows->pluck('quality')->all())->toBe([2, 2, 4, 4])
        ->and($rows->pluck('answer_text')->all()[1])->not->toBeNull();

    $firstRow = WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $first)->sole();
    $secondRow = WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $second)->sole();
    expect($firstRow->state)->toBe(RepetitionState::Review)->and($firstRow->interval_days)->toBe(1)->and($firstRow->is_hard)->toBeTrue()
        ->and($firstRow->meta[LadderPolicy::LAST_EXERCISE_META] ?? null)->toBe('meaning')
        ->and($secondRow->state)->toBe(RepetitionState::Review)->and($secondRow->interval_days)->toBe(4);

    // The failed card's steps: wrong meaning → step 0 again; right reverse → step 1; right meaning → graduated.
    expect($round['last']['done_reason'])->toBe('sprint_complete')->and($round['last']['can_continue'])->toBeTrue();
    // The estimate follows the grades: the failed meeting owes two checks (+1), "already knew" took one grade instead of
    // two (-1), the failed meaning owes its repeat (+1), the passed reverse leaves one step (-1 pending, +1 done).
    expect(array_map(static fn (array $s): array => $s['progress'], $round['serves']))->toBe([
        ['done' => 0, 'total' => 4], ['done' => 1, 'total' => 5], ['done' => 2, 'total' => 4], ['done' => 3, 'total' => 5], ['done' => 4, 'total' => 5],
    ]);
});

// --- the end of a round -----------------------------------------------------------------------

it('tells a Free learner apart: sprint_complete while the day has new words left, new_quota_exhausted once they are spent', function (): void {
    // Noon of the learner's day: "an hour ago" below must be the same local day. Run in the first hour after the local
    // midnight (19:00–20:00 UTC for Tashkent), the words would belong to yesterday and the day would have all 20 left.
    $this->travelTo(now('Asia/Tashkent')->setTime(12, 0));

    ['course' => $course, 'lessons' => $lessons] = spCourse(3);
    $learner = spLearner($course);

    // 14 words introduced earlier today (before the session): the day has 6 left — exactly one round.
    foreach ($lessons[2]->words()->orderBy('id')->limit(10)->get() as $i => $word) {
        if ($i >= 4) {
            spDue($learner, $word, 1, 1, ['next_review_at' => now()->addDay(), 'created_at' => now()->subHour()]);
        }
    }
    foreach ($lessons[1]->words()->orderBy('id')->limit(8)->get() as $word) {
        spDue($learner, $word, 1, 1, ['next_review_at' => now()->addDay(), 'created_at' => now()->subHour()]);
    }
    expect(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(14);

    $round = spRound($this, $learner);
    expect($round['serves'])->toHaveCount(12)
        ->and($round['last']['done_reason'])->toBe('new_quota_exhausted')
        ->and($round['last']['can_continue'])->toBeFalse()
        ->and($round['last']['quota'])->toBe(['new_words_remaining' => 0]);

    // A fresh session the same day: nothing to do, the same reason, no round offered.
    spFinish($this, $learner, $round['session_id']);
    $again = spRound($this, $learner);
    expect($again['serves'])->toBe([])
        ->and($again['last']['done_reason'])->toBe('new_quota_exhausted')
        ->and($again['last']['can_continue'])->toBeFalse()
        ->and($again['last']['progress'])->toBe(['done' => 0, 'total' => 0]);
});

it('caps the day-based reviews of a round and offers another round for the rest, until nothing is due', function (): void {
    config(['learning.sprint.due_cards' => 5]);
    ['course' => $course, 'lessons' => $lessons] = spCourse(1);
    $learner = Learner::factory()->create(['active_course_id' => null]); // no course: due cards only
    foreach ($lessons[0]->words()->orderBy('id')->limit(8)->get() as $word) {
        spDue($learner, $word, 2, 6);
    }

    $round = spRound($this, $learner);
    expect($round['serves'])->toHaveCount(5)
        ->and(array_unique(array_map(static fn (array $s): string => $s['card']['card_kind'], $round['serves'])))->toBe(['due'])
        // The estimate is the cap's remainder, not the eight due cards: 5 all along.
        ->and(array_map(static fn (array $s): array => $s['progress'], $round['serves']))->toBe([['done' => 0, 'total' => 5], ['done' => 1, 'total' => 5], ['done' => 2, 'total' => 5], ['done' => 3, 'total' => 5], ['done' => 4, 'total' => 5]])
        ->and($round['last']['progress'])->toBe(['done' => 5, 'total' => 5])
        ->and($round['last']['done_reason'])->toBe('sprint_complete')
        ->and($round['last']['can_continue'])->toBeTrue();

    spFinish($this, $learner, $round['session_id']);

    $rest = spRound($this, $learner);
    expect($rest['serves'])->toHaveCount(3)
        ->and($rest['serves'][0]['progress'])->toBe(['done' => 0, 'total' => 3])
        ->and($rest['last']['done_reason'])->toBe('nothing_due')
        ->and($rest['last']['can_continue'])->toBeFalse();
});

it('gives Pro the same short round with another one always on offer while the course has words', function (): void {
    ['course' => $course] = spCourse(2);
    $learner = spLearner($course, pro: true);

    $round = spRound($this, $learner);
    expect($round['serves'])->toHaveCount(12)
        ->and($round['last']['done_reason'])->toBe('sprint_complete')
        ->and($round['last']['can_continue'])->toBeTrue()
        ->and($round['last']['quota'])->toBe(['new_words_remaining' => null]);

    // The last round of the course (20 words: 6 + 6 + 6 + 2): the rest of the words, then nothing — no round to offer.
    for ($i = 0; $i < 3; $i++) {
        spFinish($this, $learner, $round['session_id']);
        $round = spRound($this, $learner);
    }
    expect($round['serves'])->toHaveCount(4)
        ->and($round['last']['done_reason'])->toBe('nothing_due')
        ->and($round['last']['can_continue'])->toBeFalse();
});

it('serves a failed check again at once and never ends the round with a word hanging in its minute steps', function (): void {
    config(['learning.sprint.new_words' => 1]);
    ['course' => $course] = spCourse(1);
    $learner = spLearner($course);

    $round = spRound($this, $learner, fn (array $card, int $i): int => $i === 1 ? 2 : 4);
    $kinds = array_map(static fn (array $s): string => $s['card']['card_kind'], $round['serves']);
    $tokens = array_map(static fn (array $s): ?string => $s['card']['serve_token'], $round['serves']);

    // meet → check (wrong) → check again (right, step 1) → check (right, graduated): all four in one sitting, with tickets.
    expect($kinds)->toBe(['new', 'learning', 'learning', 'learning'])
        ->and(array_filter($tokens))->toHaveCount(3)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->sole()->state)->toBe(RepetitionState::Review)
        ->and($round['last']['done_reason'])->toBe('sprint_complete')
        // "Remembered" on the meeting card (its answer was open) followed by a wrong check is no dishonest self-grade.
        ->and(ProductEvent::query()->where('name', ProductEventName::ReviewSelfGradeMismatch->value)->count())->toBe(0);
});

// --- the switches ---------------------------------------------------------------------------------

it('restores the previous behaviour behind the switches: no round, the daily goal as the total, flash in the minute steps', function (): void {
    config(['learning.sprint.enabled' => false, 'learning.ladder.early.enabled' => false]);
    ['course' => $course] = spCourse(1);
    $learner = spLearner($course, attributes: ['daily_goal' => 10]);

    $types = [];
    $round = spRound($this, $learner, function (array $card) use (&$types): int {
        $types[] = $card['prompt']['type'];

        return 4;
    });

    // Ten words met and checked on flash cards, the session runs to the end of the course: 20 grades over the goal of 10.
    expect($round['serves'])->toHaveCount(20)
        ->and(array_unique($types))->toBe(['flash'])
        ->and($round['serves'][0]['progress'])->toBe(['done' => 0, 'total' => 10])
        ->and($round['last']['progress'])->toBe(['done' => 20, 'total' => 10])
        ->and($round['last']['done_reason'])->toBe('nothing_due')
        ->and($round['last']['can_continue'])->toBeFalse();
});

it('keeps the round but the flash checks when only the early ladder is off', function (): void {
    config(['learning.ladder.early.enabled' => false]);
    ['course' => $course] = spCourse(1);
    $learner = spLearner($course);

    $round = spRound($this, $learner);
    expect($round['serves'])->toHaveCount(12)
        ->and(array_unique(array_map(static fn (array $s): string => $s['card']['prompt']['type'], $round['serves'])))->toBe(['flash'])
        ->and($round['last']['done_reason'])->toBe('sprint_complete');
});

// --- learners with progress ----------------------------------------------------------------------

it('serves the mature cards of an existing learner objectively and in another format than their last one', function (): void {
    config(['learning.ladder.typed_from_interval_days' => 6]); // the default of config/learning.php, whatever a local .env says
    ['course' => $course, 'lessons' => $lessons] = spCourse(1);
    $learner = spLearner($course, attributes: ['active_course_id' => null]);
    $words = $lessons[0]->words()->orderBy('id')->limit(5)->get();
    foreach ($words as $word) {
        spDue($learner, $word, 3, 15); // three passes, no `last_exercise` yet (graded before the method)
    }

    $first = spRound($this, $learner);
    $firstTypes = [];
    foreach ($first['serves'] as $serve) {
        $firstTypes[(int) $serve['card']['word_id']] = $serve['card']['prompt']['type'];
    }
    // The form list for Free without audio files starts at the reverse.
    expect(array_unique(array_values($firstTypes)))->toBe(['reverse']);

    // Due again: the other format of the list (a recall flash), never the reverse twice in a row.
    WordRepetition::query()->where('learner_id', $learner->id)->update(['next_review_at' => now()->subMinute()]);
    spFinish($this, $learner, $first['session_id']);
    $second = spRound($this, $learner);
    foreach ($second['serves'] as $serve) {
        expect($serve['card']['prompt']['type'])->toBe('flash')->and($firstTypes[(int) $serve['card']['word_id']])->toBe('reverse');
    }

    // Pro at the typed threshold: the typed exercises, alternating.
    $pro = spLearner($course, pro: true, attributes: ['active_course_id' => null]);
    spDue($pro, $words[0], 2, 6, ['meta' => [LadderPolicy::LAST_EXERCISE_META => 'gap_fill']]);
    spDue($pro, $words[1], 2, 6, ['meta' => [LadderPolicy::LAST_EXERCISE_META => 'spelling']]);
    $cards = [];
    $response = $this->withHeaders(spHeaders($pro))->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $cards[(int) $response['card']['word_id']] = $response['card']['prompt']['type'];
    $response = $this->withHeaders(spHeaders($pro))->postJson("/api/twa/training/sessions/{$response['session_id']}/review", CardAnswers::body($response['card'], 4))->assertOk()->json();
    $cards[(int) $response['card']['word_id']] = $response['card']['prompt']['type'];
    expect($cards[(int) $words[0]->id])->toBe('spelling')->and($cards[(int) $words[1]->id])->toBe('gap_fill');
});

// --- the summary: stages -------------------------------------------------------------------------

it('counts the words that grew in the session: graduated new words, promoted and mastered older ones', function (): void {
    config(['learning.sprint.new_words' => 1]);
    ['course' => $course, 'lessons' => $lessons] = spCourse(1);
    $learner = spLearner($course);
    $words = $lessons[0]->words()->orderBy('id')->get();
    $climbs = spDue($learner, $words[1], 2, 6);     // learning (6 days) → a pass gives 15: known
    $tops = spDue($learner, $words[2], 3, 15);      // known → a pass gives 37 (× 2.5): mastered
    $falls = spDue($learner, $words[3], 3, 15);     // known → a lapse: relearning = learning, no climb
    $stays = spDue($learner, $words[4], 1, 1);      // learning → 6 days: still learning

    $failed = false;
    $round = spRound($this, $learner, function (array $card) use ($falls, &$failed): int {
        if ((int) $card['word_id'] === (int) $falls->word_id && ! $failed) {
            $failed = true;

            return 2; // the lapse; the relearning check that follows passes
        }

        return 4;
    });
    $summary = $this->withHeaders(spHeaders($learner))->postJson("/api/twa/training/sessions/{$round['session_id']}/finish")->assertOk()->json();

    expect($summary['new_words'])->toBe(1)
        ->and($summary['stages'])->toBe(['graduated' => 1, 'promoted' => 2, 'mastered' => 1])
        ->and(WordStage::of($climbs->fresh()))->toBe(WordStage::Known)
        ->and(WordStage::of($tops->fresh()))->toBe(WordStage::Mastered)
        ->and(WordStage::of($falls->fresh()))->toBe(WordStage::Learning)
        ->and(WordStage::of($stays->fresh()))->toBe(WordStage::Learning);

    // The thresholds come from the config; a new word in its minute steps is `new`.
    config(['learning.stages.known_from_interval_days' => 20]);
    expect(WordStage::of($climbs->fresh()))->toBe(WordStage::Learning)
        ->and(WordStage::of(new WordRepetition(['state' => 'learning', 'interval_days' => 0])))->toBe(WordStage::New)
        ->and(WordStage::thresholds())->toBe(['known' => 20, 'mastered' => 21]);
});

// --- the cost ---------------------------------------------------------------------------------------

it('keeps the daily round inside the statement budget of the lesson cycle, and the round\'s end costs two reads more', function (): void {
    ['course' => $course, 'lessons' => $lessons] = spCourse(1);
    $learner = spLearner($course);
    $headers = spHeaders($learner);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    // With the caches warm, as under load (TrainingCycleQueryBudgetTest): the translations and the hooks of the lesson's words.
    foreach ($lessons[0]->words as $word) {
        app(WordTranslationResolver::class)->resolve($word, 'ru');
        app(WordHookResolver::class)->for($word, 'ru');
    }

    $opened = $this->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $base = "/api/twa/training/sessions/{$opened['session_id']}";
    $card = $opened['card'];
    // The first grade of the day places the learner in the league and checks the first achievement: not measured.
    $card = $this->withHeaders($headers)->postJson("{$base}/review", CardAnswers::body($card, 4))->assertOk()->json('card');

    // A new card graded, the next one new: the counters (one statement with the round's subqueries), the due pick, the
    // learner lock, the candidates (quota and round in the same statement), the insert — the lesson cycle's 14.
    $body = CardAnswers::body($card, 4);
    $review = QueryLog::during(function () use (&$card, $base, $headers, $body): void {
        $card = $this->withHeaders($headers)->postJson("{$base}/review", $body)->assertOk()->json('card');
    });
    expect(count($review))->toBeLessThanOrEqual(14)
        ->and(QueryLog::matching($review, '/count\(\*\).*["`]created_at["`] >= \?/'))->toHaveCount(2) // the quota and the round, one statement each
        ->and(QueryLog::matching($review, '/from ["`]training_reviews["`]/'))->toHaveCount(1); // the session counters, once

    // Walk to the last check; its grade ends the round: the usual grade plus the two "is there more" reads.
    $last = null;
    for ($i = 0; $i < 20 && $card !== null; $i++) {
        $body = CardAnswers::body($card, 4);
        $end = QueryLog::during(function () use (&$card, &$last, $base, $headers, $body): void {
            $last = $this->withHeaders($headers)->postJson("{$base}/review", $body)->assertOk()->json();
            $card = $last['card'];
        });
    }
    expect($last['done_reason'] ?? null)->toBe('sprint_complete')
        ->and(count($end ?? []))->toBeLessThanOrEqual(14);

    // finish: the summary with its stages is still ONE statement over training_reviews.
    $finish = QueryLog::during(function () use ($base, $headers): void {
        $this->withHeaders($headers)->postJson("{$base}/finish")->assertOk();
    });
    expect(count($finish))->toBeLessThanOrEqual(9)
        ->and(QueryLog::matching($finish, '/from ["`]training_reviews["`]/'))->toHaveCount(1);
});

it('does not touch lesson sessions: a lesson has no round and keeps its own progress', function (): void {
    ['course' => $course, 'lessons' => $lessons] = spCourse(1);
    $learner = spLearner($course);
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lessons[0]);
    $flow = app(TrainingFlowService::class);

    $served = 0;
    $step = $flow->nextStep($session, $learner);
    while ($step->word !== null && $served < 40) {
        $served++;
        $step = CardAnswers::grade($flow, $session, $learner, $step, 4)->next;
    }

    expect($served)->toBe(20) // ten words met and checked: no cap of six
        ->and($step->doneReason)->toBe(DoneReason::LessonComplete)
        ->and($step->canContinue)->toBeFalse()
        ->and($step->progress)->toBe(['done' => 10, 'total' => 10])
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

// --- the review debt, the limits, the counters (review of 2026-11-17) ----------------------------------

it('holds new words back while more reviews are due than one round takes, and lets them in once the debt fits a round', function (): void {
    config(['learning.sprint.due_cards' => 15]);
    ['course' => $course, 'lessons' => $lessons] = spCourse(3);
    $learner = spLearner($course, pro: true);
    // Twenty day-based cards due (two passes: the recognition list, the meaning first), in lessons 2 and 3; lesson 1 is unseen.
    foreach ([$lessons[1], $lessons[2]] as $lesson) {
        foreach ($lesson->words()->orderBy('id')->get() as $word) {
            spDue($learner, $word, 2, 6, ['meta' => [LadderPolicy::LAST_EXERCISE_META => 'flash']]);
        }
    }

    // Round 1: fifteen reviews, not one new word; the bar says fifteen from the first card; another round is on offer.
    $first = spRound($this, $learner);
    expect($first['serves'])->toHaveCount(15)
        ->and(array_unique(array_map(static fn (array $s): string => $s['card']['card_kind'], $first['serves'])))->toBe(['due'])
        ->and($first['serves'][0]['progress'])->toBe(['done' => 0, 'total' => 15])
        ->and($first['last']['done_reason'])->toBe('sprint_complete')->and($first['last']['can_continue'])->toBeTrue()
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(20);

    // Round 2: the five left fit a round - the new words come back: 5 + 6 × 2.
    spFinish($this, $learner, $first['session_id']);
    $second = spRound($this, $learner);
    $kinds = array_count_values(array_map(static fn (array $s): string => $s['card']['card_kind'], $second['serves']));
    expect($second['serves'])->toHaveCount(17)
        ->and($kinds['due'])->toBe(5)->and($kinds['new'])->toBe(6)
        ->and($second['serves'][0]['progress'])->toBe(['done' => 0, 'total' => 17])
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(26);

});

it('lets the backlog and the new words share a round when the debt rule is switched off', function (): void {
    config(['learning.sprint.due_cards' => 15, 'learning.sprint.debt_holds_new' => false]);
    ['course' => $course, 'lessons' => $lessons] = spCourse(3);
    $learner = spLearner($course, pro: true);
    foreach ([$lessons[1], $lessons[2]] as $lesson) {
        foreach ($lesson->words()->orderBy('id')->get() as $word) {
            spDue($learner, $word, 2, 6, ['meta' => [LadderPolicy::LAST_EXERCISE_META => 'flash']]);
        }
    }

    $round = spRound($this, $learner);
    expect(array_count_values(array_map(static fn (array $s): string => $s['card']['card_kind'], $round['serves'])))->toMatchArray(['due' => 15, 'new' => 6]);
});

it('reads a limit of 0 new words as no limit: the whole lesson in one round, then nothing due - no endless empty rounds', function (): void {
    config(['learning.sprint.new_words' => 0]);
    ['course' => $course] = spCourse(1);
    $learner = spLearner($course, pro: true);

    $round = spRound($this, $learner);
    expect($round['serves'])->toHaveCount(20)
        ->and($round['last']['done_reason'])->toBe('nothing_due')->and($round['last']['can_continue'])->toBeFalse()
        // Without a limit the bar cannot foresee the new words: it grows with each one met, and never runs past.
        ->and($round['serves'][0]['progress'])->toBe(['done' => 0, 'total' => 2])
        ->and($round['last']['progress'])->toBe(['done' => 20, 'total' => 20]);
});

it('reads a limit of 0 due cards as no limit: twenty due in one round, the bar counting all of them', function (): void {
    config(['learning.sprint.due_cards' => 0]);
    ['lessons' => $lessons] = spCourse(1);
    $reviewer = Learner::factory()->create(['active_course_id' => null]);
    foreach ($lessons[0]->words()->orderBy('id')->get() as $word) {
        spDue($reviewer, $word, 3, 15, ['meta' => [LadderPolicy::LAST_EXERCISE_META => 'flash']]);
    }
    foreach (spCourse(1)['lessons'][0]->words()->orderBy('id')->get() as $word) {
        spDue($reviewer, $word, 3, 15, ['meta' => [LadderPolicy::LAST_EXERCISE_META => 'flash']]);
    }

    $all = spRound($this, $reviewer);
    expect($all['serves'])->toHaveCount(20)
        ->and($all['serves'][0]['progress'])->toBe(['done' => 0, 'total' => 20])
        ->and($all['last']['done_reason'])->toBe('nothing_due')->and($all['last']['can_continue'])->toBeFalse();
});

it('does not let a withdrawn word in its minute steps keep the round\'s total above done', function (): void {
    ['course' => $course, 'lessons' => $lessons] = spCourse(1);
    $learner = Learner::factory()->create(['active_course_id' => null]);
    $word = $lessons[0]->words()->orderBy('id')->first();
    spDue($learner, $word, 0, 0, ['state' => RepetitionState::Learning->value, 'learning_step' => 1, 'next_review_at' => now()->subHour()]);
    $word->forceFill(['is_active' => false])->save();

    $round = spRound($this, $learner);
    expect($round['serves'])->toBe([])
        ->and($round['last']['progress'])->toBe(['done' => 0, 'total' => 0])
        ->and($round['last']['done_reason'])->toBe('nothing_due');
});

it('runs the round for a learner scheduled by FSRS: the first round, then the checks of the next day', function (): void {
    ['course' => $course] = spCourse(2);
    $learner = spLearner($course, attributes: ['scheduler' => 'fsrs']);

    $first = spRound($this, $learner);
    expect($first['serves'])->toHaveCount(12)
        ->and($first['last']['done_reason'])->toBe('sprint_complete')
        ->and(TrainingReview::query()->where('learner_id', $learner->id)->distinct()->pluck('scheduler')->all())->toBe(['fsrs']);
    $rows = WordRepetition::query()->where('learner_id', $learner->id)->get();
    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('state')->unique()->all())->toBe([RepetitionState::Review])
        ->and($rows->min('interval_days'))->toBeGreaterThanOrEqual(1);

    // When the six are due again: a round of their checks in another format than the meaning (the recognition list goes on).
    spFinish($this, $learner, $first['session_id']);
    $this->travelTo($rows->max('next_review_at')->addMinute());
    $next = spRound($this, $learner);
    $due = array_values(array_filter($next['serves'], static fn (array $s): bool => $s['card']['card_kind'] === 'due'));
    expect($due)->toHaveCount(6)
        ->and(array_unique(array_map(static fn (array $s): string => $s['card']['prompt']['type'], $due)))->toBe(['reverse'])
        ->and($next['last']['done_reason'])->toBe('sprint_complete')
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->where('repetitions', '>=', 2)->count())->toBe(6);
});

it('counts a word left in its learning steps by an earlier session as promoted when it graduates here, and a relearning one as nothing', function (): void {
    ['course' => $course, 'lessons' => $lessons] = spCourse(1);
    $learner = Learner::factory()->create(['active_course_id' => null]);
    $words = $lessons[0]->words()->orderBy('id')->get();
    // Met yesterday, left at the second step (stage `new`): graduating here is a climb.
    $left = spDue($learner, $words[0], 0, 0, ['state' => RepetitionState::Learning->value, 'learning_step' => 1, 'next_review_at' => now()->subHour(), 'created_at' => now()->subDay()]);
    // Lapsed yesterday, in relearning (stage `learning`): graduating back to one day is no climb.
    $lapsed = spDue($learner, $words[1], 0, 0, ['state' => RepetitionState::Relearning->value, 'learning_step' => 0, 'lapses' => 1, 'next_review_at' => now()->subHour(), 'created_at' => now()->subDays(9)]);
    // Known, lapses here: its first grade says known, the relearning grade says nothing new - no climb either.
    $falls = spDue($learner, $words[2], 3, 15);

    $failed = false;
    $round = spRound($this, $learner, function (array $card) use ($falls, &$failed): int {
        if ((int) $card['word_id'] === (int) $falls->word_id && ! $failed) {
            $failed = true;

            return 2;
        }

        return 4;
    });
    expect($round['serves'])->toHaveCount(4);
    $summary = spFinish($this, $learner, $round['session_id']);

    expect($summary)->toMatchArray(['reviewed' => 4, 'correct' => 3, 'new_words' => 0, 'stages' => ['graduated' => 0, 'promoted' => 1, 'mastered' => 0]])
        ->and(WordStage::of($left->fresh()))->toBe(WordStage::Learning)
        ->and(WordStage::of($lapsed->fresh()))->toBe(WordStage::Learning)
        ->and(WordStage::of($falls->fresh()))->toBe(WordStage::Learning);
});

it('stops estimating new words once the picker has seen the course run dry: the last round\'s bar ends exact', function (): void {
    ['course' => $course] = spCourse(1); // ten words: 6 + 4
    $learner = spLearner($course, pro: true);
    $first = spRound($this, $learner);
    spFinish($this, $learner, $first['session_id']);

    $last = spRound($this, $learner);
    $totals = array_map(static fn (array $s): int => $s['progress']['total'], $last['serves']);
    // Four new words are met (the limit of six still counted while the picker has not looked past them), then the
    // picker finds nothing new and the bar is the four checks that are left: 8 from the fifth card on, 8 / 8 at the end.
    expect($last['serves'])->toHaveCount(8)
        ->and(array_slice($totals, 4))->toBe([8, 8, 8, 8])
        ->and($last['last']['progress'])->toBe(['done' => 8, 'total' => 8])
        ->and($last['last']['done_reason'])->toBe('nothing_due');
});
