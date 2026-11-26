<?php

declare(strict_types=1);

/*
 * Real races of the word points on PostgreSQL (docs/32 §4, S4–S6): forked workers, each with its own connection and transaction,
 * released at the same instant (Tests\Support\Game\GameRace: lock_timeout 8 s, so a deadlock or an endless wait is a crash, not a hang).
 *
 *   - the same card from two devices: one grade, one credit, the other a 409;
 *   - two copies of one lexeme (two courses) answered at once: one credit, the other `already_today`;
 *   - the game and the training on one lexeme at once: one credit, no deadlock;
 *   - the last slot and the rest under the cap taken by parallel answers: the limits hold exactly;
 *   - the first credit of a fresh day from four answers: one day row, one week fixed, the counters add up;
 *   - the answer that finishes a run (the finish locks the map, the wallet, the day) against training answers of the same learner: no
 *     deadlock, the order `… → learner_lexemes → learner_score_days → learner_daily_stats` holds on both sides.
 * Committed rows: DatabaseTruncation. Skipped on sqlite.
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
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScorePeriod;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CardAnswers;
use Tests\Support\Game\GameKit;
use Tests\Support\Game\GameRace;
use Tests\Support\HotPathFixtures as Fx;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the word points on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    CarbonImmutable::setTestNow('2026-11-25 05:00:00'); // Wednesday 10:00 in Tashkent
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.game_enabled' => true,
        'leagues.scoring.versions.2.starts_on' => '2026-11-01',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/** A due day-based card (interval 5: a repetition, below the typed rung) of the learner on the word: the exercise is `meaning`. */
function wsrDue(int $learnerId, int $wordId): void
{
    // The right option of `meaning` is the translation in the language of the session: Russian here (CardAnswers answers in it).
    DB::table('learners')->where('id', $learnerId)->update(['interface_locale' => 'ru']);
    WordRepetition::factory()->create([
        'learner_id' => $learnerId, 'word_id' => $wordId, 'state' => RepetitionState::Review->value, 'repetitions' => 1, 'interval_days' => 5,
        'last_reviewed_at' => now()->subDays(5)->subHour(), 'next_review_at' => now()->subHour(), 'easiness_factor' => 2.5,
        'is_hard' => false, 'last_quality' => 4, 'lapses' => 0, 'meta' => [],
    ]);
}

/** Answers a due `meaning` card right through the review service; a refusal is a result. */
function wsrTrainingAnswer(int $sessionId, int $learnerId, int $wordId): string
{
    $session = TrainingSession::query()->findOrFail($sessionId);
    $learner = Learner::query()->findOrFail($learnerId);
    try {
        $accepted = app(ReviewSubmissionService::class)->submitAnswer($session, $learner, $wordId, CardAnswers::correctAnswer(['word_id' => $wordId, 'prompt' => ['type' => 'meaning']]), 900);
    } catch (ReviewSubmissionException $e) {
        return $e->errorCode;
    }

    return (string) $accepted->score?->reason->value;
}

/** @return array{learner: int, words: list<int>, lexeme: int} a learner, a course of 8 words, the lexeme shared by the first two */
function wsrWorld(): array
{
    ['words' => $words] = Fx::course([1 => [1 => 4, 2 => 4]]);
    $ids = [...$words['1.1']['active'], ...$words['1.2']['active']];
    foreach ($ids as $wordId) {
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
    }
    // Two copies of one word in two lessons: one lexeme.
    $lexeme = (int) DB::table('words')->where('id', $ids[0])->value('lexeme_id');
    DB::table('words')->where('id', $ids[4])->update(['lexeme_id' => $lexeme]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent'])->id;
    LearnerLexeme::factory()->create(['learner_id' => $learner, 'lexeme_id' => $lexeme, 'confirmed_on' => '2026-10-01']);

    return ['learner' => $learner, 'words' => $ids, 'lexeme' => $lexeme];
}

it('grades the same card from two devices once and credits it once', function (): void {
    ['learner' => $learner, 'words' => $words] = wsrWorld();
    wsrDue($learner, $words[0]);
    $a = TrainingSession::factory()->personal(Learner::query()->findOrFail($learner), 'daily_review')->create()->id;

    $results = GameRace::run(array_fill(0, 2, fn (): string => wsrTrainingAnswer($a, $learner, $words[0])));

    expect(GameRace::crashes($results))->toBe([])
        ->and(collect($results)->pluck('value')->sort()->values()->all())->toBe(['card_not_due', 'credited'])
        ->and(ScoreCredit::query()->count())->toBe(1)
        ->and(DB::table('training_reviews')->count())->toBe(1);
});

it('credits one of two copies of a lexeme answered at the same instant from two sessions', function (): void {
    ['learner' => $learner, 'words' => $words, 'lexeme' => $lexeme] = wsrWorld();
    wsrDue($learner, $words[0]);
    wsrDue($learner, $words[4]);
    $model = Learner::query()->findOrFail($learner);
    $daily = TrainingSession::factory()->personal($model, 'daily_review')->create()->id;
    $lessonSession = TrainingSession::factory()->personal($model, 'lesson')->create(['lesson_id' => Word::query()->findOrFail($words[4])->lesson_id])->id;

    $results = GameRace::run([
        fn (): string => wsrTrainingAnswer($daily, $learner, $words[0]),
        fn (): string => wsrTrainingAnswer($lessonSession, $learner, $words[4]),
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(collect($results)->pluck('value')->sort()->values()->all())->toBe(['already_today', 'credited'])
        ->and(ScoreCredit::query()->where('lexeme_id', $lexeme)->count())->toBe(1)
        ->and(LearnerScoreDay::query()->sole()->week_review)->toBe(1)
        ->and(DB::table('training_reviews')->count())->toBe(2); // both grades stand: the schedule is the training's, the points are once
});

it('credits one of the game and the training answering one lexeme at the same instant, without a deadlock', function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    [$gamer, $course, $lesson, $list] = GameKit::stop(8);
    foreach ($list as $word) {
        DB::table('words')->where('id', $word->id)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
    }
    DB::table('word_repetitions')->where('learner_id', $gamer->id)->update(['state' => 'review', 'interval_days' => 8, 'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour()]);
    $run = app(RunService::class)->start($gamer, $course->slug, $lesson->id);
    for ($guard = 0; $guard < 20 && ! TaskKind::from(GameKit::current($run)['kind'])->isWordTask(); $guard++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            app(RunService::class)->answer($run, $gamer, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $element = GameKit::current($run);
    $lexeme = (int) $element['lexeme_id'];
    LearnerLexeme::query()->where('learner_id', $gamer->id)->delete();
    LearnerLexeme::factory()->create(['learner_id' => $gamer->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2026-10-01']);
    // A copy of the word in another lesson of the course, due in the training.
    $copy = Word::factory()->for(Fx::course([1 => [1 => 0]])['lessons']['1.1'])->create(['word' => 'copyword', 'translation' => 'копия', 'lexeme_id' => $lexeme]);
    foreach (range(1, 3) as $i) {
        Word::factory()->for($copy->lesson)->create(['word' => "filler{$i}", 'translation' => "заполнитель {$i}"]);
    }
    wsrDue($gamer->id, $copy->id);
    $session = TrainingSession::factory()->personal($gamer, 'daily_review')->create()->id;
    $gamerId = $gamer->id;
    $runId = $run->id;

    $results = GameRace::run([
        fn (): string => (string) (app(RunService::class)->answer($runId, GameRace::learner($gamerId), (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0])->body['score']['reason'] ?? 'none'),
        fn (): string => wsrTrainingAnswer($session, $gamerId, $copy->id),
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(collect($results)->pluck('value')->sort()->values()->all())->toBe(['already_today', 'credited'])
        ->and(ScoreCredit::query()->where('lexeme_id', $lexeme)->count())->toBe(1);
});

it('holds the last slot and the cap of a day against parallel answers', function (string $what): void {
    $learner = Learner::factory()->create()->id;
    $lexemes = Lexeme::factory()->count(6)->create()->modelKeys();
    $now = CarbonImmutable::now();
    // The day exists (one credit), then is brought next to its limits.
    DB::transaction(fn () => app(AnswerScoreRecorder::class)->record(new ScoredAnswer($learner, $lexemes[0], 1, ScoreSource::Training, 1, AnswerVerdict::Passed, null, Plan::Free, $now)));
    if ($what === 'slot') {
        LearnerScoreDay::query()->update(['week_new' => 19, 'month_new' => 19]);
    } else {
        LearnerScoreDay::query()->update(['week_points' => 96, 'month_points' => 96]);
        foreach (array_slice($lexemes, 1) as $lexeme) {
            LearnerLexeme::factory()->create(['learner_id' => $learner, 'lexeme_id' => $lexeme, 'confirmed_on' => '2026-10-01']);
        }
    }
    $before = $what === 'slot' ? null : new CardStateBefore(RepetitionState::Review, 9, $now->subDays(9)->subHour());

    $results = GameRace::run(array_map(fn (int $lexeme): Closure => fn (): array => DB::transaction(function () use ($learner, $lexeme, $before, $now): array {
        $outcome = app(AnswerScoreRecorder::class)->record(new ScoredAnswer($learner, $lexeme, $lexeme, ScoreSource::Training, 1, AnswerVerdict::Passed, $before, Plan::Free, $now));
        usleep(30_000); // the rest of the answer, the locks held

        return [$outcome->reason->value, $outcome->weekAdded];
    }), array_slice($lexemes, 1)));

    $day = LearnerScoreDay::query()->sole();
    expect(GameRace::crashes($results))->toBe([])
        ->and(ScoreCredit::query()->count())->toBe(6);
    if ($what === 'slot') {
        expect($day->week_new)->toBe(20)
            ->and(collect($results)->where('value.1', 2)->count())->toBe(1)
            ->and($day->week_points)->toBe(4);
    } else {
        expect($day->week_points)->toBe(100)
            ->and(collect($results)->sum('value.1'))->toBe(4)
            ->and($day->week_review)->toBe(5)
            ->and($day->week_capped_at)->not->toBeNull();
    }
})->with(['slot', 'cap']);

it('creates the day once and fixes the week once when four answers credit a fresh day at once', function (): void {
    $learner = Learner::factory()->create()->id;
    $lexemes = Lexeme::factory()->count(4)->create()->modelKeys();
    $now = CarbonImmutable::now();

    $results = GameRace::run(array_map(fn (int $lexeme): Closure => fn (): array => DB::transaction(function () use ($learner, $lexeme, $now): array {
        $outcome = app(AnswerScoreRecorder::class)->record(new ScoredAnswer($learner, $lexeme, $lexeme, ScoreSource::Training, 1, AnswerVerdict::Passed, null, fn (): Plan => Plan::Pro, $now));
        usleep(30_000);

        return [$outcome->weekAdded, $outcome->firstCreditOfWeek];
    }), $lexemes));

    $day = LearnerScoreDay::query()->sole();
    expect(GameRace::crashes($results))->toBe([])
        ->and($day->week_new)->toBe(4)
        ->and($day->week_points)->toBe(8)
        ->and($day->raw_points)->toBe(8)
        ->and(collect($results)->where('value.1', true)->count())->toBe(1)
        ->and(LearnerScorePeriod::query()->count())->toBe(2)
        ->and(ScoreCredit::query()->sum('week_points'))->toBe(8);
});

it('does not deadlock the answer that finishes a run against training answers of the same learner', function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    [$learner, $course, $lesson, $list] = GameKit::stop(8);
    foreach ($list as $word) {
        DB::table('words')->where('id', $word->id)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
    }
    DB::table('word_repetitions')->where('learner_id', $learner->id)->update(['state' => 'review', 'interval_days' => 8, 'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour()]);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    // Up to the last element: its answer finishes the run (the map, the wallet, the profile, the day) in the same transaction.
    for ($guard = 0; $guard < 60; $guard++) {
        $run->refresh();
        if ((int) $run->state['current'] >= $run->total - 1) {
            break;
        }
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            app(RunService::class)->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $last = GameKit::current($run);
    expect($last)->not->toBeNull();

    // Two other words of the learner, in another course, due in the training (their own lexemes).
    ['words' => $other] = Fx::course([1 => [1 => 6]]);
    foreach ($other['1.1']['active'] as $wordId) {
        DB::table('words')->where('id', $wordId)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
    }
    [$w1, $w2] = $other['1.1']['active'];
    wsrDue($learner->id, $w1);
    wsrDue($learner->id, $w2);
    $s1 = TrainingSession::factory()->personal($learner, 'daily_review')->create()->id;
    $s2 = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => Word::query()->findOrFail($w2)->lesson_id])->id;
    $learnerId = $learner->id;
    $runId = $run->id;

    $results = GameRace::run([
        function () use ($runId, $learnerId, $last): string {
            $body = [];
            foreach (GameKit::rightAnswers($last) as $answer) {
                $body = app(RunService::class)->answer($runId, GameRace::learner($learnerId), (string) Str::uuid(), $last['id'], $answer)->body;
            }

            return 'finished';
        },
        fn (): string => wsrTrainingAnswer($s1, $learnerId, $w1),
        fn (): string => wsrTrainingAnswer($s2, $learnerId, $w2),
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(collect($results)->pluck('value')->all())->toBe(['finished', 'credited', 'credited'])
        ->and(GameRun::query()->findOrFail($runId)->isActive())->toBeFalse()
        ->and(ScoreCredit::query()->where('source', 'training')->count())->toBe(2)
        ->and(DB::table('learner_daily_stats')->where('learner_id', $learnerId)->count())->toBe(1);
});
