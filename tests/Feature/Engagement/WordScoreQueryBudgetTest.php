<?php

declare(strict_types=1);

/*
 * The statement budget of the word points (docs/32 §4 "Бюджет", owner: no extra SQL on the answer). What one answer adds:
 *
 *   off / no lexeme / the first meeting of a new card            0
 *   the attempt of the day spent already                          1   learner_lexemes FOR UPDATE
 *   closed / early / same_cycle / not_due                         2   + UPDATE learner_lexemes (first attempt ever: SELECT + INSERT)
 *   a credit on a day that has its row                            5   + learner_score_days FOR UPDATE, INSERT score_credits, UPDATE day
 *   the first credit of a rating day (week and month fixed)       7   + periods read, INSERT day instead of nothing (no re-read)
 *   the first credit of a week (the month fixed)                  9   + INSERT period, periods re-read
 *   the first credit of a week and a month                       10
 *   the game, a repetition: + 1 (the card, read without a lock)
 *
 * and the whole `POST /review` of the training and the answer of the game, with the points off and on. Same numbers on sqlite and
 * PostgreSQL (every statement is a plain one: INSERT … ON CONFLICT DO NOTHING / insert or ignore).
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\Lexeme;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CardAnswers;
use Tests\Support\Game\GameKit;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

const WSB_SCORE_TABLES = '/["`](learner_lexemes|learner_score_days|learner_score_periods|score_credits)["`]/';

beforeEach(function (): void {
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2026-11-01');
    // The weekly placement after the commit (v1: the first grade of a local day; v2: the first credit of a rating day) is not the
    // scoring's budget: LeagueV2BudgetTest pins it.
    config()->set('leagues.enabled', false);
});

/** @return list<string> the statements of one recorded answer */
function wsbRecord(int $learnerId, int $lexemeId, AnswerVerdict $verdict, CarbonImmutable $now, ?CardStateBefore $before = null): array
{
    return DB::transaction(fn (): array => QueryLog::during(fn () => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learnerId, $lexemeId, 1, ScoreSource::Training, 1, $verdict, $before, Plan::Free, $now),
    )));
}

function wsbAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

it('adds exactly the budgeted statements to one answer on every path of the decision', function (): void {
    $learner = Learner::factory()->create()->id;
    $lexemes = Lexeme::factory()->count(12)->create()->modelKeys();
    $known = static function (int $lexemeId) use ($learner): int {
        LearnerLexeme::factory()->create(['learner_id' => $learner, 'lexeme_id' => $lexemeId, 'confirmed_on' => '2026-10-01']);

        return $lexemeId;
    };
    $monday = wsbAt('2026-11-02 09:00'); // a new week, November fixed below first
    $due = static fn (CarbonImmutable $now): CardStateBefore => new CardStateBefore(RepetitionState::Review, 3, $now->subDays(3)->subHour());

    config()->set('leagues.scoring.mode', 'off');
    expect(wsbRecord($learner, $lexemes[0], AnswerVerdict::Passed, $monday))->toBe([]);
    config()->set('leagues.scoring.mode', 'live');
    expect(wsbRecord($learner, $lexemes[0], AnswerVerdict::Introductory, $monday))->toBe([]);

    // The first credit of a week AND a month: 2 (lexeme: SELECT miss, INSERT) + day miss + periods read + 2 INSERT + re-read + INSERT day
    // + INSERT credit + UPDATE day.
    $first = wsbRecord($learner, $lexemes[0], AnswerVerdict::Passed, $monday);
    expect($first)->toHaveCount(10)
        ->and(QueryLog::matching($first, '/insert .*["`]learner_score_periods["`]/'))->toHaveCount(2);

    // A credit on a day that has its row: a lexeme met for the first time (SELECT, INSERT) and a known one (SELECT, UPDATE).
    expect(wsbRecord($learner, $lexemes[1], AnswerVerdict::Passed, $monday->addMinute()))->toHaveCount(5)
        ->and(wsbRecord($learner, $known($lexemes[2]), AnswerVerdict::Passed, $monday->addMinutes(2), $due($monday)))->toHaveCount(5);

    // Spent already: the lock only. Closed, early: the lock and one write.
    expect(wsbRecord($learner, $lexemes[1], AnswerVerdict::Passed, $monday->addMinutes(3)))->toHaveCount(1)
        ->and(wsbRecord($learner, $known($lexemes[3]), AnswerVerdict::Failed, $monday->addMinutes(4)))->toHaveCount(2)
        ->and(wsbRecord($learner, $lexemes[4], AnswerVerdict::Failed, $monday->addMinutes(5)))->toHaveCount(2)
        ->and(wsbRecord($learner, $known($lexemes[5]), AnswerVerdict::Passed, $monday->addMinutes(6), new CardStateBefore(RepetitionState::Review, 9, $monday->subDay())))->toHaveCount(2);

    // Tuesday: the first credit of a rating day of a fixed week and month - the periods are read, nothing fixed, the day inserted.
    $tuesday = wsbRecord($learner, $lexemes[6], AnswerVerdict::Passed, wsbAt('2026-11-03 09:00'));
    expect($tuesday)->toHaveCount(7)
        ->and(QueryLog::matching($tuesday, '/["`]learner_score_periods["`]/'))->toHaveCount(1);

    // The next Monday: a new week inside a fixed month.
    expect(wsbRecord($learner, $lexemes[7], AnswerVerdict::Passed, wsbAt('2026-11-09 09:00')))->toHaveCount(9);

    // The second credit of a day never reads the periods.
    expect(QueryLog::matching(wsbRecord($learner, $lexemes[8], AnswerVerdict::Passed, wsbAt('2026-11-09 09:05')), '/["`]learner_score_periods["`]/'))->toBe([]);
});

/**
 * A Free learner on a daily review of two long repetitions (confirmed lexemes), with the caches warm.
 *
 * @return array{headers: array<string, string>, session: int, card: array<string, mixed>}
 */
function wsbDailyReview(object $test, array $wordIds): array
{
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent']);
    foreach ($wordIds as $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => 'review', 'repetitions' => 1, 'interval_days' => 8,
            'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour(), 'easiness_factor' => 2.5,
            'is_hard' => false, 'last_quality' => 4, 'lapses' => 0, 'meta' => [],
        ]);
        // Had a long repetition before: the credit is not the lexeme's first one, so the after-commit look at `long_recall_25`
        // (WordScoreHooks, +2 statements on a first long repetition only - LongRecallAchievementTest) stays out of this budget.
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => (int) DB::table('words')->where('id', $wordId)->value('lexeme_id'), 'confirmed_on' => '2026-10-01', 'first_long_on' => '2026-10-10']);
    }
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $test->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();

    return ['headers' => $headers, 'session' => (int) $opened['session_id'], 'card' => $opened['card']];
}

/** @return array{0: list<string>, 1: list<string>} the statements of the first and of the second answer */
function wsbTwoAnswers(object $test, array $review): array
{
    $card = $review['card'];
    $logs = [];
    foreach ([0, 1] as $i) {
        $logs[$i] = QueryLog::during(function () use ($test, $review, &$card): void {
            $card = $test->withHeaders($review['headers'])->postJson("/api/twa/training/sessions/{$review['session']}/review", CardAnswers::body($card))->assertOk()->json('card');
        });
    }

    return $logs;
}

it('adds 10 statements to the first /review of a week and a month and 5 to the next one; none with the points off', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    ['words' => $words] = Fx::course([1 => [1 => 6]]);
    foreach ($words['1.1']['active'] as $wordId) {
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
    }
    $pair = array_slice($words['1.1']['active'], 0, 2);

    // A learner of its own warms what is shared (the translations, the distractors of the course) on both paths alike.
    wsbTwoAnswers($this, wsbDailyReview($this, $pair));

    config()->set('leagues.scoring.mode', 'off');
    [$offFirst, $offSecond] = wsbTwoAnswers($this, wsbDailyReview($this, $pair));
    config()->set('leagues.scoring.mode', 'live');
    [$liveFirst, $liveSecond] = wsbTwoAnswers($this, wsbDailyReview($this, $pair));

    expect(QueryLog::matching($offFirst, WSB_SCORE_TABLES))->toBe([])
        ->and(QueryLog::matching($offSecond, WSB_SCORE_TABLES))->toBe([])
        ->and(QueryLog::matching($liveFirst, WSB_SCORE_TABLES))->toHaveCount(10)
        ->and(QueryLog::matching($liveSecond, WSB_SCORE_TABLES))->toHaveCount(5)
        ->and(count($liveFirst))->toBe(count($offFirst) + 10)
        ->and(count($liveSecond))->toBe(count($offSecond) + 5)
        ->and(QueryLog::matching($liveSecond, '/["`]learner_score_periods["`]/'))->toBe([])
        // The lexeme rides in the joined read of the card: no read of `words` or `lexemes` of its own.
        ->and(QueryLog::matching($liveSecond, '/from ["`]lexemes["`]/'))->toBe([]);

    // The pinned totals (sqlite and PostgreSQL alike): the review of a due card, the next card picked in the same request.
    expect(count($offSecond))->toBeLessThanOrEqual(14)
        ->and(count($liveSecond))->toBeLessThanOrEqual(19);
});

/**
 * A Free learner on a daily review of due words of HIS OWN DECK whose spellings the catalog knows (docs/32 §12.12: a deck is outside
 * the catalog - the words have no lexeme), with the caches warm.
 *
 * @param  list<string>  $spellings
 * @return array{headers: array<string, string>, session: int, card: array<string, mixed>}
 */
function wsbDeckReview(object $test, array $spellings): array
{
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent']);
    $decks = app(\App\Domain\Content\Services\LearnerDeckService::class);
    $deck = $decks->create($learner, 'Мои слова');
    $decks->addWords($learner, $deck, array_map(fn (string $w): \App\Domain\Content\DTO\DeckRow => new \App\Domain\Content\DTO\DeckRow($w, 'перевод '.$w), $spellings));
    $lessons = DB::table('lessons')->join('stages', 'stages.id', '=', 'lessons.stage_id')->where('stages.course_id', $deck->id)->pluck('lessons.id');
    foreach (DB::table('words')->whereIn('lesson_id', $lessons)->pluck('id') as $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => (int) $wordId, 'state' => 'review', 'repetitions' => 1, 'interval_days' => 8,
            'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour(), 'easiness_factor' => 2.5,
            'is_hard' => false, 'last_quality' => 4, 'lapses' => 0, 'meta' => [],
        ]);
    }
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $test->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();

    return ['headers' => $headers, 'session' => (int) $opened['session_id'], 'card' => $opened['card']];
}

it('costs a /review of a deck word in live exactly what it costs with the points off: no statement of the word points', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    // The catalog knows the spellings (a course of the platform): the deck words still have no lexeme.
    $spellings = ['harbour', 'lantern', 'meadow', 'orchard'];
    foreach ($spellings as $spelling) {
        Lexeme::factory()->create(['key' => $spelling]);
    }

    wsbTwoAnswers($this, wsbDeckReview($this, $spellings)); // warms what is shared
    config()->set('leagues.scoring.mode', 'off');
    [$offFirst, $offSecond] = wsbTwoAnswers($this, wsbDeckReview($this, $spellings));
    config()->set('leagues.scoring.mode', 'live');
    [$liveFirst, $liveSecond] = wsbTwoAnswers($this, wsbDeckReview($this, $spellings));

    expect(QueryLog::matching($liveFirst, WSB_SCORE_TABLES))->toBe([])
        ->and(QueryLog::matching($liveSecond, WSB_SCORE_TABLES))->toBe([])
        ->and(QueryLog::matching($liveSecond, '/["`]lexemes["`]/'))->toBe([])
        ->and(count($liveFirst))->toBe(count($offFirst))
        ->and(count($liveSecond))->toBe(count($offSecond))
        ->and(count($liveSecond))->toBeLessThanOrEqual(14);
});

it('adds 6 statements to a counted answer of the game (5 and the card read without a lock), none with the points off', function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    $logs = [];
    foreach (['off', 'live'] as $mode) {
        config()->set('leagues.scoring.mode', $mode);
        [$learner, $course, $lesson, $list] = GameKit::stop(8);
        foreach ($list as $word) {
            $lexeme = Lexeme::factory()->create()->id;
            DB::table('words')->where('id', $word->id)->update(['lexeme_id' => $lexeme]);
            // not the first long repetition of the lexeme: no after-commit look at `long_recall_25` (see wsbDailyReview)
            LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2026-10-01', 'first_long_on' => '2026-10-10']);
        }
        DB::table('word_repetitions')->where('learner_id', $learner->id)->update([
            'state' => 'review', 'interval_days' => 8, 'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour(),
        ]);
        DB::table('courses')->where('id', $course->id)->update(['slug' => "budget-{$mode}"]);
        $runs = app(RunService::class);
        $run = $runs->start($learner, "budget-{$mode}", $lesson->id);

        // Two word tasks of different words: the first credit of the day, then an ordinary one.
        $logs[$mode] = [];
        for ($guard = 0; $guard < 30 && count($logs[$mode]) < 2 && ($element = GameKit::current($run)) !== null; $guard++) {
            $counted = TaskKind::from($element['kind'])->isWordTask() && ! in_array($element['word_id'], array_column($logs[$mode], 'word'), true);
            $log = QueryLog::during(function () use ($runs, $run, $learner, $element): void {
                foreach (GameKit::rightAnswers($element) as $answer) {
                    $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
                }
            });
            if ($counted) {
                $logs[$mode][] = ['word' => $element['word_id'], 'log' => $log];
            }
        }
        // A learner of its own per mode: the day row of the first is not the second's.
        DB::table('courses')->where('id', $course->id)->update(['slug' => "done-{$mode}"]);
    }

    [$offFirst, $offSecond] = array_column($logs['off'], 'log');
    [$liveFirst, $liveSecond] = array_column($logs['live'], 'log');
    expect(QueryLog::matching($offFirst, WSB_SCORE_TABLES))->toBe([])
        ->and(QueryLog::matching($offSecond, WSB_SCORE_TABLES))->toBe([])
        ->and(QueryLog::matching($liveFirst, WSB_SCORE_TABLES))->toHaveCount(10)
        ->and(QueryLog::matching($liveSecond, WSB_SCORE_TABLES))->toHaveCount(5)
        ->and(QueryLog::matching($liveSecond, '/from ["`]word_repetitions["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($offSecond, '/from ["`]word_repetitions["`]/'))->toBe([]);

    CarbonImmutable::setTestNow();
});

it('prices the whole first /review of a week with the hooks after the commit: v2 = v1 + 10 (the points) + 2 (the seat)', function (): void {
    // The weekly placement on: v1 seats at the first grade of a local day, v2 at the first credit of a rating day (WordScoreHooks).
    // Both seat the same way (week, membership, last membership, week FOR SHARE, group, member, counter, event); v2 adds the read
    // of the learner (the hook has only the id) and the read of the week by the v1 hook of the grade, which returns at once in a v2
    // week - 2 statements, once per learner and day. The achievement hook stays out (fixtures: not a first long repetition).
    config()->set('leagues.enabled', true);
    ['words' => $words] = Fx::course([1 => [1 => 6]]);
    foreach ($words['1.1']['active'] as $wordId) {
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
    }
    $pair = array_slice($words['1.1']['active'], 0, 2);

    // A v1 week (off): its row is created with version 1 by a learner of its own, then the measured learner.
    config()->set('leagues.scoring.mode', 'off');
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    wsbTwoAnswers($this, wsbDailyReview($this, $pair));
    [$offFirst] = wsbTwoAnswers($this, wsbDailyReview($this, $pair));

    // The next week on v2 (live; its row is created with version 2).
    config()->set('leagues.scoring.mode', 'live');
    $this->travelTo(CarbonImmutable::parse('2026-12-02 05:00:00', 'UTC'));
    wsbTwoAnswers($this, wsbDailyReview($this, $pair));
    [$liveFirst, $liveSecond] = wsbTwoAnswers($this, wsbDailyReview($this, $pair));

    $seat = fn (array $log): int => count(array_filter($log, fn (string $sql): bool => preg_match('/league_(members|groups)/', $sql) === 1 && ! str_contains($sql, 'learner_score_periods')));
    expect(QueryLog::matching($liveFirst, WSB_SCORE_TABLES))->toHaveCount(10)
        ->and($seat($liveFirst))->toBe($seat($offFirst))
        ->and(count($liveFirst))->toBe(count($offFirst) + 12)
        // the next answer of the day: no statement of the league
        ->and(QueryLog::matching($liveSecond, '/league_/'))->toBe([]);
});
