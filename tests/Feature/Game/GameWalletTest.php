<?php

declare(strict_types=1);

use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Services\Runs\RunFinisher;
use App\Domain\Game\Services\Runs\RunService;
use App\Models\ClassCaravan;
use App\Models\GameCityProgress;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.2.5, §12.5.3 (G2.5a): the wallet of coins and the transaction of a finish. The invariant `SUM(game_coin_ledger.amount) =
 * game_wallets.balance` is checked after every scenario; an idempotency key pays once; a reward of nothing is no entry; the table of
 * the rewards (the first clearing, the new stars, the repeat, the failed run, the daily caravan, the bosses); the progress of the map,
 * the profile and the day of the learner (the league points under the cap, the day held once); the command `game:wallets-verify`.
 * The shop, the equipment and the admin entries join this file with G2.5b / G2.11.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function wallets(): WalletService
{
    return app(WalletService::class);
}

/** The invariant of docs/18 §12.2.5 for one learner: the ledger sums to the balance, and the last entry says the same. */
function expectWalletInvariant(int $learnerId): void
{
    $balance = (int) GameWallet::query()->where('learner_id', $learnerId)->value('balance');
    $sum = (int) GameCoinLedgerEntry::query()->where('learner_id', $learnerId)->sum('amount');
    expect($sum)->toBe($balance, "SUM(ledger) {$sum} <> balance {$balance}");

    $last = GameCoinLedgerEntry::query()->where('learner_id', $learnerId)->orderByDesc('id')->first();
    if ($last !== null) {
        expect($last->balance_after)->toBe($balance);
    }
    expect(wallets()->mismatches())->toBe([]);
}

/** @return list<array<string, mixed>> */
function walletEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/** A finish the way the answer does it: in a transaction, under the lock of the run. */
function finishRun(GameRun $run, RunFinishReason $reason, ?CarbonImmutable $now = null): GameRun
{
    $now ??= CarbonImmutable::now();
    $learner = Learner::query()->findOrFail($run->learner_id);

    return DB::transaction(function () use ($run, $reason, $learner, $now): GameRun {
        $locked = GameRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
        app(RunFinisher::class)->finish($locked, $reason, $learner, $now);

        return $locked;
    });
}

/** A run of the factory with its counters set and `$losses` flasks lost in its state. */
function runWith(GameRun $run, int $correct, int $wrong = 0, int $losses = 0): GameRun
{
    $state = RunState::initial($run->flasks_max);
    for ($i = 1; $i <= $losses; $i++) {
        $state->markFlaskLost("e{$i}");
    }
    $state->current = $run->total;
    $state->flasks = max(0, $run->flasks_max - $losses);

    $run->forceFill(['correct' => $correct, 'wrong' => $wrong, 'answered' => $correct + $wrong, 'state' => $state->toArray(), 'flasks' => $state->flasks, 'score' => 10 * $correct])->save();

    return $run->refresh();
}

// --- the wallet --------------------------------------------------------------------

it('credits once per idempotency key: the wallet is made lazily, the entry carries balance_after, a repeat of the key gives nothing twice', function (): void {
    $learner = Learner::factory()->create();

    $first = DB::transaction(fn () => wallets()->credit($learner->id, 25, LedgerKind::RunReward, 'run:1:reward', 'game_run', 1, ['stars' => 3]));
    $again = DB::transaction(fn () => wallets()->credit($learner->id, 25, LedgerKind::RunReward, 'run:1:reward', 'game_run', 1, ['stars' => 3]));
    $more = DB::transaction(fn () => wallets()->credit($learner->id, 5, LedgerKind::DailyReward, 'run:2:reward'));

    expect($first->balance)->toBe(25)->and($first->applied)->toBeTrue()
        ->and($again->balance)->toBe(25)->and($again->applied)->toBeFalse()
        ->and($more->balance)->toBe(30)->and($more->applied)->toBeTrue()
        ->and(GameCoinLedgerEntry::query()->count())->toBe(2)
        ->and(GameCoinLedgerEntry::query()->pluck('balance_after', 'idempotency_key')->all())->toBe(['run:1:reward' => 25, 'run:2:reward' => 30]);

    $wallet = GameWallet::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($wallet->balance)->toBe(30)->and($wallet->earned_total)->toBe(30)->and($wallet->spent_total)->toBe(0)
        ->and(wallets()->balance($learner->id))->toBe(30);

    $entry = GameCoinLedgerEntry::query()->where('idempotency_key', 'run:1:reward')->firstOrFail();
    expect($entry->amount)->toBe(25)->and($entry->kind)->toBe(LedgerKind::RunReward)->and($entry->ref_type)->toBe('game_run')->and($entry->ref_id)->toBe(1)->and($entry->meta)->toBe(['stars' => 3]);
    expectWalletInvariant($learner->id);
});

it('writes no entry and makes no wallet for a credit of zero, and refuses a negative credit or a debit of nothing', function (): void {
    // (The guard "inside a transaction" cannot be seen here: RefreshDatabase keeps every test inside one.)
    $learner = Learner::factory()->create();

    $nothing = wallets()->credit($learner->id, 0, LedgerKind::RunReward, 'run:1:reward');

    expect($nothing->balance)->toBe(0)->and($nothing->applied)->toBeFalse()
        ->and(GameCoinLedgerEntry::query()->count())->toBe(0)
        ->and(GameWallet::query()->count())->toBe(0);

    expect(fn () => DB::transaction(fn () => wallets()->credit($learner->id, -1, LedgerKind::RunReward, 'run:1:reward')))->toThrow(LogicException::class, 'non-negative');
    expect(fn () => DB::transaction(fn () => wallets()->debit($learner->id, 0, LedgerKind::ItemPurchase, 'shop:1:x')))->toThrow(LogicException::class, 'positive');
    expect(GameCoinLedgerEntry::query()->count())->toBe(0);
});

it('debits once per key with the shape of a purchase, refuses what the balance cannot pay and writes nothing then', function (): void {
    $learner = Learner::factory()->create();
    DB::transaction(fn () => wallets()->credit($learner->id, 10, LedgerKind::RunReward, 'run:1:reward'));
    $key = "shop:{$learner->id}:".Str::uuid();

    $bought = DB::transaction(fn () => wallets()->debit($learner->id, 4, LedgerKind::ItemPurchase, $key, 'game_item', 7, ['item_key' => 'camel_bactrian']));
    $again = DB::transaction(fn () => wallets()->debit($learner->id, 4, LedgerKind::ItemPurchase, $key, 'game_item', 7));

    expect($bought->balance)->toBe(6)->and($bought->applied)->toBeTrue()
        ->and($again->balance)->toBe(6)->and($again->applied)->toBeFalse()
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', $key)->value('amount'))->toBe(-4);

    expect(fn () => DB::transaction(fn () => wallets()->debit($learner->id, 7, LedgerKind::ItemPurchase, "shop:{$learner->id}:other")))
        ->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('coins_insufficient')->and($e->details)->toBe(['balance' => 6, 'price' => 7]));

    $wallet = GameWallet::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($wallet->balance)->toBe(6)->and($wallet->earned_total)->toBe(10)->and($wallet->spent_total)->toBe(4)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(2);
    expectWalletInvariant($learner->id);
});

it('treats a key that belongs to another learner as a bug, not as "done already"', function (): void {
    [$a, $b] = Learner::factory()->count(2)->create();
    DB::transaction(fn () => wallets()->credit($a->id, 10, LedgerKind::RunReward, 'run:9:reward'));

    expect(fn () => DB::transaction(fn () => wallets()->credit($b->id, 10, LedgerKind::RunReward, 'run:9:reward')))->toThrow(LogicException::class, 'belongs to learner');
    expect(GameCoinLedgerEntry::query()->count())->toBe(1);
    expectWalletInvariant($a->id);
    expectWalletInvariant($b->id);
});

// --- the finish of a stop, on real runs --------------------------------------------

it('pays the first clearing of a stop (10 + 5 per star), moves the map, the profile and the day, and tells it after the commit', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);

    $run = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);

    $n = $run->total;
    expect($run->finish_reason)->toBe(RunFinishReason::Completed)->and($run->stars)->toBe(3)
        ->and($run->coins_awarded)->toBe(25)->and($run->league_points)->toBe(2 * $n);

    $entry = GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->firstOrFail();
    expect(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and($entry->amount)->toBe(25)->and($entry->kind)->toBe(LedgerKind::RunReward)
        ->and($entry->idempotency_key)->toBe("run:{$run->id}:reward")
        ->and($entry->ref_type)->toBe('game_run')->and($entry->ref_id)->toBe($run->id)
        ->and($entry->balance_after)->toBe(25)->and($entry->meta)->toBe(['stars' => 3, 'run_kind' => 'stop']);
    expectWalletInvariant($learner->id);

    $stop = GameStopProgress::query()->where('learner_id', $learner->id)->where('lesson_id', $lesson->id)->firstOrFail();
    expect($stop->course_id)->toBe($course->id)->and($stop->stage_id)->toBe($lesson->stage_id)
        ->and($stop->stars)->toBe(3)->and($stop->best_score)->toBe($run->score)->and($stop->runs_count)->toBe(1)
        ->and($stop->first_cleared_at?->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($stop->last_run_at->toDateTimeString())->toBe('2026-11-05 10:00:00');

    $profile = GameProfile::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($profile->stops_cleared)->toBe(1)->and($profile->cities_cleared)->toBe(0)->and($profile->tickets)->toBe(0)
        ->and($profile->last_run_at?->toDateTimeString())->toBe('2026-11-05 10:00:00');

    $day = LearnerDailyStats::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($day->date->toDateString())->toBe('2026-11-05')
        ->and($day->game_runs_count)->toBe(1)->and($day->game_points)->toBe(2 * $n)
        ->and($day->game_held_at?->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($day->reviews_count)->toBe(0)->and($day->goal_met_at)->toBeNull()->and($day->correct_count)->toBe(0);

    expect(walletEvents('game.coins_earned'))->toHaveCount(1)
        ->and(walletEvents('game.coins_earned')[0])->toEqualCanonicalizing(['amount' => 25, 'kind' => 'run_reward', 'ref_type' => 'game_run', 'ref_id' => $run->id, 'balance' => 25])
        ->and(walletEvents('game.run_finished'))->toHaveCount(1)
        ->and(walletEvents('game.run_finished')[0])->toMatchArray(['run_id' => $run->id, 'coins' => 25, 'league_points' => 2 * $n, 'stars' => 3]);
});

it('pays 2 for a repeat without new stars, keeps the best stars and score, counts the run and holds the day once; the cap of 40 league points stops the third run', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    $first = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);
    $n = $first->total;
    $firstScore = $first->score;

    CarbonImmutable::setTestNow('2026-11-05 10:10:00');
    $second = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner, wrongAt: [2]); // two stars: worse, nothing new

    expect($second->stars)->toBe(2)->and($second->coins_awarded)->toBe(2)
        ->and($second->league_points)->toBe(min(40, 2 * $n + ($n - 1) * 2 + 1) - 2 * $n) // the cap may already bite with 10 elements
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', "run:{$second->id}:reward")->value('amount'))->toBe(2)
        ->and(wallets()->balance($learner->id))->toBe(27);

    $stop = GameStopProgress::query()->where('learner_id', $learner->id)->where('lesson_id', $lesson->id)->firstOrFail();
    expect($stop->stars)->toBe(3)->and($stop->best_score)->toBe(max($firstScore, $second->score))->and($stop->runs_count)->toBe(2)
        ->and($stop->first_cleared_at?->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($stop->last_run_at->toDateTimeString())->toBe('2026-11-05 10:10:00');

    $day = LearnerDailyStats::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($day->game_runs_count)->toBe(2)->and($day->game_points)->toBe(2 * $n + $second->league_points)
        ->and($day->game_held_at?->toDateTimeString())->toBe('2026-11-05 10:00:00'); // set once, by the first run
    expect(GameProfile::query()->where('learner_id', $learner->id)->value('stops_cleared'))->toBe(1);

    CarbonImmutable::setTestNow('2026-11-05 10:20:00');
    $third = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);
    CarbonImmutable::setTestNow('2026-11-05 10:30:00');
    $fourth = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);

    $day->refresh();
    expect($day->game_points)->toBe(40)->and($day->game_runs_count)->toBe(4)
        ->and($third->league_points)->toBe(40 - (2 * $n + $second->league_points))
        ->and($fourth->league_points)->toBe(0)
        ->and($third->coins_awarded)->toBe(2)->and($fourth->coins_awarded)->toBe(2)
        ->and(array_sum(array_column(walletEvents('game.run_finished'), 'league_points')))->toBe(40);
    expectWalletInvariant($learner->id);
});

it('pays 5 for every new star above the best so far, without the first-clearing bonus again', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);

    $one = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner, wrongAt: [1, 3]); // one star
    expect($one->stars)->toBe(1)->and($one->coins_awarded)->toBe(15); // 10 + 5 × 1

    CarbonImmutable::setTestNow('2026-11-05 10:10:00');
    $three = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner); // three stars: two new
    expect($three->stars)->toBe(3)->and($three->coins_awarded)->toBe(10)
        ->and(GameStopProgress::query()->where('learner_id', $learner->id)->value('stars'))->toBe(3)
        ->and(wallets()->balance($learner->id))->toBe(25);
    expectWalletInvariant($learner->id);
});

it('pays nothing for a failed run of a stop never cleared - no entry, no wallet - yet counts the run, holds the day and scores the league points of the answers', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);

    $failed = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner, wrongAt: [0, 1, 2, 3]);

    expect($failed->finish_reason)->toBe(RunFinishReason::Failed)->and($failed->stars)->toBe(0)
        ->and($failed->coins_awarded)->toBe(0)
        ->and($failed->league_points)->toBe(2 * $failed->correct + $failed->wrong + $failed->typos + $failed->hints)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(0)
        ->and(GameWallet::query()->count())->toBe(0)
        ->and(walletEvents('game.coins_earned'))->toBe([]);

    $stop = GameStopProgress::query()->where('learner_id', $learner->id)->where('lesson_id', $lesson->id)->firstOrFail();
    expect($stop->stars)->toBe(0)->and($stop->runs_count)->toBe(1)->and($stop->first_cleared_at)->toBeNull()->and($stop->best_score)->toBe($failed->score);

    $day = LearnerDailyStats::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($day->game_runs_count)->toBe(1)->and($day->game_points)->toBe($failed->league_points)->and($day->game_held_at)->not->toBeNull();
    expect(GameProfile::query()->where('learner_id', $learner->id)->value('stops_cleared'))->toBe(0);
    expectWalletInvariant($learner->id);
});

it('pays 2 for a failed run of a stop cleared before', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);

    CarbonImmutable::setTestNow('2026-11-05 10:10:00');
    $failed = GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner, wrongAt: [0, 1, 2, 3]);

    expect($failed->finish_reason)->toBe(RunFinishReason::Failed)->and($failed->coins_awarded)->toBe(2)
        ->and(wallets()->balance($learner->id))->toBe(27)
        ->and(GameStopProgress::query()->where('learner_id', $learner->id)->value('stars'))->toBe(3);
    expectWalletInvariant($learner->id);
});

it('pays nothing and writes neither a day nor a progress row for a run abandoned or expired', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);

    $abandoned = $runs->start($learner, $course->slug, $lesson->id);
    $runs->answer($abandoned, $learner, (string) Str::uuid(), GameKit::current($abandoned)['id'], GameKit::rightAnswers(GameKit::current($abandoned))[0]);
    $runs->abandon($abandoned, $learner);
    $expired = $runs->start($learner, $course->slug, $lesson->id);
    $runs->closeExpired(CarbonImmutable::now()->addMinutes(31));

    expect($abandoned->refresh()->finish_reason)->toBe(RunFinishReason::Abandoned)
        ->and($expired->refresh()->finish_reason)->toBe(RunFinishReason::Expired)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(0)
        ->and(GameStopProgress::query()->count())->toBe(0)
        ->and(GameProfile::query()->count())->toBe(0)
        ->and(LearnerDailyStats::query()->count())->toBe(0);
});

it('keeps the invariant through a hint bought and a reward earned in the same run', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameWallet::factory()->withBalance(30)->create(['learner_id' => $learner->id]);
    GameCoinLedgerEntry::factory()->create(['learner_id' => $learner->id, 'amount' => 30, 'balance_after' => 30, 'kind' => LedgerKind::AdminGrant, 'idempotency_key' => 'admin:1']);
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => 0, 'type' => 'bazaar']]]])->save();
    $first = GameKit::current($run);
    if (! in_array($first['kind'], ['translate_fwd', 'translate_back', 'listen', 'spell', 'gap'], true)) {
        $this->markTestSkipped('The first element of this seed has no hint.');
    }
    $runs->hint($run, $learner, (string) Str::uuid(), $first['id']);

    $run = GameKit::play($runs, $run, $learner);

    expect($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->coins_awarded)->toBe(25)
        ->and(wallets()->balance($learner->id))->toBe(30 - 5 + 25)
        ->and(GameCoinLedgerEntry::query()->orderBy('id')->pluck('amount')->all())->toBe([30, -5, 25])
        ->and(GameCoinLedgerEntry::query()->orderBy('id')->pluck('balance_after')->all())->toBe([30, 25, 50])
        ->and(walletEvents('game.coins_spent'))->toHaveCount(1)
        ->and(walletEvents('game.coins_earned'))->toHaveCount(1);
    $wallet = GameWallet::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($wallet->earned_total)->toBe(55)->and($wallet->spent_total)->toBe(5);
    expectWalletInvariant($learner->id);
});

it('writes the day of the START of the run (its local_date) and leaves the learning of that day as it was', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learner->forceFill(['timezone' => 'Asia/Tashkent'])->save(); // UTC+5: 18:40 UTC is 23:40 local
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-11-05', 'reviews_count' => 12, 'correct_count' => 9, 'new_words_count' => 3, 'time_spent_ms' => 1000, 'goal_met_at' => CarbonImmutable::parse('2026-11-05 09:00:00')]);
    $runs = app(RunService::class);
    $start = CarbonImmutable::parse('2026-11-05 18:40:00');
    $run = $runs->start($learner, $course->slug, $lesson->id, now: $start);
    expect($run->local_date->toDateString())->toBe('2026-11-05');

    $run = GameKit::play($runs, $run, $learner, now: CarbonImmutable::parse('2026-11-05 19:05:00')); // 00:05 local, the next day

    $days = LearnerDailyStats::query()->where('learner_id', $learner->id)->orderBy('date')->get();
    expect($days)->toHaveCount(1);
    $day = $days->first();
    expect($day?->date->toDateString())->toBe('2026-11-05')
        ->and($day?->game_runs_count)->toBe(1)->and($day?->game_points)->toBe(2 * $run->total)
        ->and($day?->game_held_at?->toDateTimeString())->toBe('2026-11-05 19:05:00')
        ->and($day?->reviews_count)->toBe(12)->and($day?->correct_count)->toBe(9)->and($day?->new_words_count)->toBe(3)->and($day?->time_spent_ms)->toBe(1000)
        ->and($day?->goal_met_at?->toDateTimeString())->toBe('2026-11-05 09:00:00');
});

// --- the finish of the other kinds, on runs of the factory -------------------------

it('pays the daily caravan 10 + 5 per star when completed and nothing when failed, with no row of the map', function (): void {
    $learner = Learner::factory()->create();

    $done = finishRun(runWith(GameRun::factory()->daily()->create(['learner_id' => $learner->id, 'total' => 8]), correct: 7, wrong: 1, losses: 1), RunFinishReason::Completed);
    expect($done->stars)->toBe(2)->and($done->coins_awarded)->toBe(20)->and($done->league_points)->toBe(15)
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', "run:{$done->id}:reward")->value('kind'))->toBe(LedgerKind::DailyReward)
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', "run:{$done->id}:reward")->value('amount'))->toBe(20);

    CarbonImmutable::setTestNow('2026-11-06 10:00:00');
    $failed = finishRun(runWith(GameRun::factory()->daily()->create(['learner_id' => $learner->id, 'total' => 8]), correct: 3, wrong: 3, losses: 3), RunFinishReason::Failed);
    expect($failed->stars)->toBe(0)->and($failed->coins_awarded)->toBe(0)->and($failed->league_points)->toBe(9)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and(GameStopProgress::query()->count())->toBe(0)->and(GameCityProgress::query()->count())->toBe(0)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->count())->toBe(2)
        ->and(GameProfile::query()->where('learner_id', $learner->id)->value('last_run_at'))->not->toBeNull();
    expectWalletInvariant($learner->id);
});

it('pays a boss 50 for the first win and 5 for the next, nothing for a loss, and keeps the city: attempts, wins, best accuracy, defeated once', function (): void {
    $learner = Learner::factory()->create();
    [$course, $lesson] = PracticeKit::course();
    $stage = Stage::query()->findOrFail($lesson->stage_id);
    $boss = fn (): GameRun => GameRun::factory()->boss()->create(['learner_id' => $learner->id, 'course_id' => $course->id, 'stage_id' => $stage->id]);

    $won = finishRun(runWith($boss(), correct: 10, wrong: 2), RunFinishReason::Completed);
    expect($won->stars)->toBe(3)->and($won->coins_awarded)->toBe(50)->and($won->league_points)->toBe(22)
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', "run:{$won->id}:reward")->value('kind'))->toBe(LedgerKind::BossReward);
    $city = GameCityProgress::query()->where('learner_id', $learner->id)->where('stage_id', $stage->id)->firstOrFail();
    expect($city->course_id)->toBe($course->id)->and($city->boss_attempts)->toBe(1)->and($city->boss_wins)->toBe(1)->and($city->boss_best_accuracy)->toBe(83)
        ->and($city->boss_defeated_at?->toDateTimeString())->toBe('2026-11-05 10:00:00');
    expect(GameProfile::query()->where('learner_id', $learner->id)->value('cities_cleared'))->toBe(1);

    CarbonImmutable::setTestNow('2026-11-05 10:10:00');
    $again = finishRun(runWith($boss(), correct: 12), RunFinishReason::Completed);
    expect($again->coins_awarded)->toBe(5)->and($again->stars)->toBe(3);
    $city->refresh();
    expect($city->boss_attempts)->toBe(2)->and($city->boss_wins)->toBe(2)->and($city->boss_best_accuracy)->toBe(100)
        ->and($city->boss_defeated_at?->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and(GameProfile::query()->where('learner_id', $learner->id)->value('cities_cleared'))->toBe(1);

    CarbonImmutable::setTestNow('2026-11-05 10:20:00');
    $lost = finishRun(runWith($boss(), correct: 9, wrong: 3), RunFinishReason::Completed); // 75 % < 80 %
    expect($lost->coins_awarded)->toBe(0)->and($lost->stars)->toBe(0)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(2)
        ->and(wallets()->balance($learner->id))->toBe(55);
    $city->refresh();
    expect($city->boss_attempts)->toBe(3)->and($city->boss_wins)->toBe(2)->and($city->boss_best_accuracy)->toBe(100);
    expectWalletInvariant($learner->id);
});

it('pays the boss of a class 20 for a win (kind class_reward) and nothing for a loss; the caravan itself is G2.9a', function (): void {
    $learner = Learner::factory()->create();
    $caravan = ClassCaravan::factory()->create();

    $won = finishRun(runWith(GameRun::factory()->classBoss($caravan)->create(['learner_id' => $learner->id]), correct: 11, wrong: 1), RunFinishReason::Completed);
    expect($won->coins_awarded)->toBe(20)->and($won->stars)->toBe(3)
        ->and(GameCoinLedgerEntry::query()->where('idempotency_key', "run:{$won->id}:reward")->value('kind'))->toBe(LedgerKind::ClassReward);

    $other = Learner::factory()->create();
    $lost = finishRun(runWith(GameRun::factory()->classBoss($caravan)->create(['learner_id' => $other->id]), correct: 5, wrong: 7), RunFinishReason::Completed);
    expect($lost->coins_awarded)->toBe(0)->and($lost->stars)->toBe(0)
        ->and(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and(GameCityProgress::query()->count())->toBe(0);
    expectWalletInvariant($learner->id);
    expectWalletInvariant($other->id);
});

it('refuses to finish for a reason that pays nothing, or a run finished already', function (): void {
    $learner = Learner::factory()->create();
    $run = runWith(GameRun::factory()->daily()->create(['learner_id' => $learner->id]), correct: 8);

    expect(fn () => finishRun($run, RunFinishReason::Abandoned))->toThrow(LogicException::class, 'nothing is paid');
    expect(fn () => finishRun($run, RunFinishReason::Expired))->toThrow(LogicException::class, 'nothing is paid');

    finishRun($run, RunFinishReason::Completed);
    expect(fn () => finishRun($run, RunFinishReason::Completed))->toThrow(LogicException::class, 'finished already');
    expect(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and(LearnerDailyStats::query()->value('game_runs_count'))->toBe(1);
});

it('rolls the whole finish back with the transaction of the answer: nothing paid, nothing counted', function (): void {
    $learner = Learner::factory()->create();
    $run = runWith(GameRun::factory()->daily()->create(['learner_id' => $learner->id]), correct: 8);

    try {
        DB::transaction(function () use ($run, $learner): void {
            $locked = GameRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            app(RunFinisher::class)->finish($locked, RunFinishReason::Completed, $learner, CarbonImmutable::now());
            throw new RuntimeException('the answer failed after the finish');
        });
    } catch (RuntimeException) {
    }

    expect($run->refresh()->isActive())->toBeTrue()
        ->and(GameCoinLedgerEntry::query()->count())->toBe(0)
        ->and(GameWallet::query()->count())->toBe(0)
        ->and(GameProfile::query()->count())->toBe(0)
        ->and(LearnerDailyStats::query()->count())->toBe(0)
        ->and(walletEvents('game.run_finished'))->toBe([])
        ->and(walletEvents('game.coins_earned'))->toBe([]);
});

// --- game:wallets-verify ------------------------------------------------------------

it('verifies every wallet against its ledger: consistent wallets pass, a wrong balance and an orphan ledger are reported and change nothing', function (): void {
    Log::spy();
    [$good, $bad, $orphan] = Learner::factory()->count(3)->create();
    DB::transaction(fn () => wallets()->credit($good->id, 10, LedgerKind::RunReward, 'run:1:reward'));
    DB::transaction(fn () => wallets()->credit($bad->id, 10, LedgerKind::RunReward, 'run:2:reward'));

    $this->artisan('game:wallets-verify')->expectsOutputToContain('all consistent')->assertExitCode(0);
    Log::shouldHaveReceived('info')->with('game.wallets_verified', ['wallets' => 2, 'mismatches' => 0])->once();

    GameWallet::query()->where('learner_id', $bad->id)->update(['balance' => 11]);
    GameCoinLedgerEntry::factory()->create(['learner_id' => $orphan->id, 'amount' => 5, 'balance_after' => 5]);

    $this->artisan('game:wallets-verify')->expectsOutputToContain('mismatches: 2')->assertExitCode(1);
    Log::shouldHaveReceived('error')->with('game.wallet_mismatch', ['learner_id' => $bad->id, 'balance' => 11, 'ledger_total' => 10])->once();
    Log::shouldHaveReceived('error')->with('game.wallet_mismatch', ['learner_id' => $orphan->id, 'balance' => null, 'ledger_total' => 5])->once();
    Log::shouldHaveReceived('error')->with('game.wallets_verified', ['wallets' => 2, 'mismatches' => 2])->once();

    expect(GameWallet::query()->where('learner_id', $bad->id)->value('balance'))->toBe(11) // a report, not a repair
        ->and(GameWallet::query()->count())->toBe(2)
        ->and(wallets()->mismatches())->toHaveCount(2);
});

it('is scheduled once a night, not at the hour of the purge of learners, without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'game:wallets-verify'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('shows the coins the finish earned in the block of /me', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);

    $this->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk()->assertJsonPath('game.coins', 25);
});

it('stops the stop of a run from counting twice when the same lesson is played by two learners: each has their own rows', function (): void {
    [$a, $course, $lesson] = GameKit::stop(8);
    $b = Learner::factory()->create();
    foreach (Word::query()->where('lesson_id', $lesson->id)->get() as $word) {
        PracticeKit::introduce($b, $word, 'review');
    }
    $runs = app(RunService::class);

    GameKit::play($runs, $runs->start($a, $course->slug, $lesson->id), $a);
    GameKit::play($runs, $runs->start($b, $course->slug, $lesson->id), $b);

    expect(GameStopProgress::query()->count())->toBe(2)
        ->and(GameStopProgress::query()->pluck('stars')->all())->toBe([3, 3])
        ->and(GameProfile::query()->pluck('stops_cleared', 'learner_id')->all())->toBe([$a->id => 1, $b->id => 1])
        ->and(wallets()->balance($a->id))->toBe(25)->and(wallets()->balance($b->id))->toBe(25)
        ->and(Lesson::query()->count())->toBe(1);
    expectWalletInvariant($a->id);
    expectWalletInvariant($b->id);
});
