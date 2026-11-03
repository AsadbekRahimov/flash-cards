<?php

declare(strict_types=1);

use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameStopProgress;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Game\GameRace;

/*
 * docs/18 §12.5.4 (G2.5a): the real races of the wallet and of the finish. Several OS processes, each with its own PostgreSQL
 * connection, move the same wallet / finish the same run at the same instant. The `FOR UPDATE` of `game_wallets`, the unique
 * `idempotency_key` of the ledger, the `FOR UPDATE` of the run and of the row of the day are what the assertions rely on, so this
 * file runs only on pgsql with pcntl (the harness: Tests\Support\Game\GameRace, a lock timeout in every worker - a deadlock or a
 * wait that never ends is a crash, never a hang). Data is committed (DatabaseTruncation).
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }

    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/** The invariant of docs/18 §12.2.5 after a race: the ledger sums to the balance, no entry ever went below zero. */
function expectWalletRaceInvariant(int $learnerId, int $balance): void
{
    expect(GameWallet::query()->where('learner_id', $learnerId)->value('balance'))->toBe($balance)
        ->and((int) GameCoinLedgerEntry::query()->where('learner_id', $learnerId)->sum('amount'))->toBe($balance)
        ->and((int) GameCoinLedgerEntry::query()->where('learner_id', $learnerId)->min('balance_after'))->toBeGreaterThanOrEqual(0)
        ->and(app(WalletService::class)->mismatches())->toBe([]);
}

/** A wallet with coins AND the ledger that explains them (the invariant holds from the start). */
function walletRaceFunded(int $learnerId, int $coins): void
{
    DB::transaction(fn () => app(WalletService::class)->credit($learnerId, $coins, LedgerKind::AdminGrant, "admin:seed:{$learnerId}"));
}

it('lets five tabs spend 4 of 10 coins at once: exactly two purchases, the balance 2, the rest refused, no entry below zero', function (): void {
    $learner = Learner::factory()->create();
    $learnerId = $learner->id;
    walletRaceFunded($learnerId, 10);

    $results = GameRace::run(array_map(
        fn (int $i): Closure => fn (): int => DB::transaction(fn (): int => app(WalletService::class)->debit($learnerId, 4, LedgerKind::ItemPurchase, "shop:{$learnerId}:tab-{$i}", 'game_item', 1)->balance),
        range(1, 5),
    ));

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['coins_insufficient', 'coins_insufficient', 'coins_insufficient', 'ok', 'ok'])
        ->and(GameCoinLedgerEntry::query()->where('kind', LedgerKind::ItemPurchase->value)->count())->toBe(2)
        ->and(GameWallet::query()->where('learner_id', $learnerId)->value('spent_total'))->toBe(8);
    foreach ($results as $r) {
        if (! $r['ok']) {
            expect($r['details']['price'])->toBe(4)->and($r['details']['balance'])->toBe(2); // refused under the lock, after the winners
        }
    }
    expectWalletRaceInvariant($learnerId, 2);
});

it('credits one idempotency key once when eight workers claim it at the same instant', function (): void {
    $learner = Learner::factory()->create();
    $learnerId = $learner->id;

    $results = GameRace::run(array_fill(0, 8, fn (): array => DB::transaction(function () use ($learnerId): array {
        $entry = app(WalletService::class)->credit($learnerId, 25, LedgerKind::RunReward, 'run:77:reward', 'game_run', 77, ['stars' => 3]);

        return ['balance' => $entry->balance, 'applied' => $entry->applied];
    })));

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(array_fill(0, 8, 'ok'))
        ->and(array_sum(array_map(static fn (array $r): int => $r['value']['applied'] ? 1 : 0, $results)))->toBe(1)
        ->and(array_unique(array_map(static fn (array $r): int => $r['value']['balance'], $results)))->toBe([25])
        ->and(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and(GameWallet::query()->count())->toBe(1)
        ->and(GameWallet::query()->where('learner_id', $learnerId)->value('earned_total'))->toBe(25);
    expectWalletRaceInvariant($learnerId, 25);
});

it('makes one wallet when the very first credits of a learner arrive together, with different keys', function (): void {
    $learner = Learner::factory()->create();
    $learnerId = $learner->id;

    $results = GameRace::run(array_map(
        fn (int $i): Closure => fn (): int => DB::transaction(fn (): int => app(WalletService::class)->credit($learnerId, 10, LedgerKind::RunReward, "run:{$i}:reward")->balance),
        range(1, 4),
    ));

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(array_fill(0, 4, 'ok'))
        ->and(GameWallet::query()->count())->toBe(1)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(4)
        ->and(collect($results)->pluck('value')->sort()->values()->all())->toBe([10, 20, 30, 40]); // every worker saw a distinct running total
    expectWalletRaceInvariant($learnerId, 40);
});

it('interleaves debits and credits of one wallet without a deadlock and keeps the ledger equal to the balance', function (): void {
    $learner = Learner::factory()->create();
    $learnerId = $learner->id;
    walletRaceFunded($learnerId, 10);

    $tasks = [];
    foreach (range(1, 4) as $i) {
        $tasks[] = fn (): int => DB::transaction(fn (): int => app(WalletService::class)->credit($learnerId, 3, LedgerKind::RunReward, "run:{$i}:reward")->balance);
        $tasks[] = fn (): int => DB::transaction(fn (): int => app(WalletService::class)->debit($learnerId, 2, LedgerKind::BazaarHint, "run:{$i}:hint:e1")->balance);
    }

    $results = GameRace::run($tasks);

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(array_fill(0, 8, 'ok')) // 10 coins pay the four debits in any order
        ->and(GameCoinLedgerEntry::query()->count())->toBe(9);
    $wallet = GameWallet::query()->where('learner_id', $learnerId)->firstOrFail();
    expect($wallet->earned_total)->toBe(22)->and($wallet->spent_total)->toBe(8);
    expectWalletRaceInvariant($learnerId, 14);
});

it('finishes a run once when two tabs send the last answer together: one reward entry, one finished, the day counted once', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $last = GameKit::current($run);
    expect($last)->not->toBeNull();
    $learnerId = $learner->id;
    $runId = $run->id;
    $answer = GameKit::rightAnswers($last)[0];

    $results = GameRace::run(array_fill(0, 2, fn (): array => app(RunService::class)->answer($runId, GameRace::learner($learnerId), (string) Str::uuid(), $last['id'], $answer)->run->toArray()['summary']));

    $run->refresh();
    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['game_run_closed', 'ok'])
        ->and($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->coins_awarded)->toBe(25)->and($run->league_points)->toBe(2 * $run->total)
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', "run:{$runId}:reward")->count())->toBe(1)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and(GameStopProgress::query()->where('learner_id', $learnerId)->value('runs_count'))->toBe(1)
        ->and(GameRace::events('game.run_finished'))->toHaveCount(1)
        ->and(GameRace::events('game.coins_earned'))->toHaveCount(1);
    $winner = collect($results)->first(static fn (array $r): bool => $r['ok']);
    expect($winner['value'])->toMatchArray(['stars' => 3, 'coins' => 25, 'league_points' => 2 * $run->total]);

    $day = LearnerDailyStats::query()->where('learner_id', $learnerId)->get();
    expect($day)->toHaveCount(1)
        ->and($day[0]->game_runs_count)->toBe(1)
        ->and($day[0]->game_points)->toBe(2 * $run->total)
        ->and($day[0]->game_held_at)->not->toBeNull();
    expectWalletRaceInvariant($learnerId, 25);
});

it('finishes a run while the same learner grades a card of the same local day: no deadlock, the day row holds both the grade and the run', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learnerId = $learner->id;
    $now = CarbonImmutable::now();

    // A card of the lesson, due, and an open session to grade it in (the transaction of a grade: training_sessions -> word_repetitions -> learner_daily_stats).
    $word = Word::factory()->for($lesson)->create(['word' => 'racecard', 'translation' => 'карта гонки']);
    WordRepetition::factory()->create([
        'learner_id' => $learnerId, 'word_id' => $word->id, 'state' => 'review', 'repetitions' => 3, 'interval_days' => 10, 'easiness_factor' => 2.5,
        'last_reviewed_at' => $now->subDays(10), 'next_review_at' => $now->subHour(), 'introduced_mode' => 'lesson', 'stability' => null, 'difficulty' => null,
    ]);
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);
    $sessionId = $session->id;
    $wordId = $word->id;

    // The run, played to its last element in the parent.
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $last = GameKit::current($run);
    $runId = $run->id;
    $answer = GameKit::rightAnswers($last)[0];

    $results = GameRace::run([
        // the last answer: the finish, holding the rows of the game and then the row of the day
        fn (): string => app(RunService::class)->answer($runId, GameRace::learner($learnerId), (string) Str::uuid(), $last['id'], $answer)->run->status->value,
        // the real grade of a card, the way POST /review does it
        fn (): int => app(ReviewSubmissionService::class)->submit(TrainingSession::query()->findOrFail($sessionId), GameRace::learner($learnerId), $wordId, 4, 1500, null, $now)->repetitions,
        // a grade that holds the row of the day for a while, so that the finish is made to wait on it while holding the rows of the game
        fn (): int => DB::transaction(function () use ($learnerId, $now): int {
            $row = app(DailyStatsService::class)->recordReview(GameRace::learner($learnerId), 5, 100, false, $now);
            usleep(300_000);

            return $row->reviews_count;
        }),
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['ok', 'ok', 'ok'])
        ->and($results[0]['value'])->toBe('finished')
        ->and($results[1]['value'])->toBe(4);

    $run->refresh();
    $day = LearnerDailyStats::query()->where('learner_id', $learnerId)->get();
    expect($run->finish_reason)->toBe(RunFinishReason::Completed)->and($run->coins_awarded)->toBe(25)
        ->and($day)->toHaveCount(1)
        ->and($day[0]->reviews_count)->toBe(2)->and($day[0]->correct_count)->toBe(2)
        ->and($day[0]->game_runs_count)->toBe(1)->and($day[0]->game_points)->toBe(2 * $run->total)
        ->and($day[0]->game_held_at)->not->toBeNull()
        ->and(WordRepetition::query()->where('learner_id', $learnerId)->where('word_id', $wordId)->value('repetitions'))->toBe(4);
    expectWalletRaceInvariant($learnerId, 25);
});
