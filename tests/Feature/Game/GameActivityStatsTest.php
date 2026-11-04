<?php

declare(strict_types=1);

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Services\GameActivityStats;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Widgets\GameActivityWidget;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\TrainingReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/*
 * docs/18 §10: the pilot metrics of the widget "Караван - 7 дней": runs per player a day, the paywall-to-payment conversion, the study
 * control (right answers in cards of the players and of the others). Their rules live in `GameActivityStats`.
 */

function gas_event(string $name, Learner $learner, CarbonImmutable $at): void
{
    ProductEvent::query()->create(['name' => $name, 'learner_id' => $learner->id, 'props' => [], 'occurred_at' => $at]);
}

function gas_reviews(Learner $learner, int $right, int $wrong, ?CarbonInterface $at = null): void
{
    TrainingReview::factory()->count($right)->create(['learner_id' => $learner->id, 'quality' => 4, 'created_at' => $at ?? now()->subDay()]);
    TrainingReview::factory()->count($wrong)->create(['learner_id' => $learner->id, 'quality' => 1, 'created_at' => $at ?? now()->subDay()]);
}

it('divides the completed and failed runs by the players and by 7, to one decimal', function (): void {
    $a = Learner::factory()->create();
    $b = Learner::factory()->create();
    GameRun::factory()->finished()->count(5)->create(['learner_id' => $a->id, 'started_at' => now()->subDays(2)]);
    GameRun::factory()->finished(RunFinishReason::Failed)->count(2)->create(['learner_id' => $b->id, 'started_at' => now()->subDays(3)]);
    GameRun::factory()->finished(RunFinishReason::Abandoned)->create(['learner_id' => $b->id, 'started_at' => now()->subDays(3)]);
    GameRun::factory()->finished()->create(['learner_id' => $b->id, 'started_at' => now()->subDays(9)]);

    $stats = app(GameActivityStats::class)->lastWeek();

    // 7 runs / 2 players / 7 days = 0.5 (the abandoned run and the run of 9 days ago are not counted)
    expect($stats['finished_runs'])->toBe(7)->and($stats['players'])->toBe(2)->and($stats['runs_per_player_day'])->toBe(0.5);
});

it('has zeros and nulls, not a division by zero, when nobody played or paid', function (): void {
    Learner::factory()->create(['last_seen_at' => now()]);

    expect(app(GameActivityStats::class)->lastWeek())->toMatchArray([
        'runs' => 0, 'players' => 0, 'share' => 0, 'runs_per_player_day' => 0.0,
        'paywall_learners' => 0, 'paywall_paid' => 0, 'paywall_percent' => 0,
        'study_players_percent' => null, 'study_others_percent' => null,
    ]);
});

it('counts a payment after the first refusal of the Free limit, not one before it', function (): void {
    $now = CarbonImmutable::now();
    [$paid, $paidBefore, $notPaid, $noRefusal, $oldRefusal] = Learner::factory()->count(5)->create()->all();

    gas_event('game.limit_reached', $paid, $now->subDays(5));
    gas_event('game.limit_reached', $paid, $now->subDays(1)); // a second refusal: the first one counts
    gas_event('payment.succeeded', $paid, $now->subDays(2));

    gas_event('payment.succeeded', $paidBefore, $now->subDays(6)); // before the refusal
    gas_event('game.limit_reached', $paidBefore, $now->subDays(4));

    gas_event('game.limit_reached', $notPaid, $now->subDays(3));

    gas_event('payment.succeeded', $noRefusal, $now->subDay());

    gas_event('game.limit_reached', $oldRefusal, $now->subDays(10)); // out of the window: not a refused learner of this week
    gas_event('payment.succeeded', $oldRefusal, $now->subDay());

    $stats = app(GameActivityStats::class)->lastWeek($now);

    expect($stats['paywall_learners'])->toBe(3)->and($stats['paywall_paid'])->toBe(1)->and($stats['paywall_percent'])->toBe(33);
});

it('does not count a payment that comes later than 7 days after the first refusal', function (): void {
    $now = CarbonImmutable::now();
    $learner = Learner::factory()->create();
    gas_event('game.limit_reached', $learner, $now->subDays(6));
    gas_event('payment.succeeded', $learner, $now->addDays(2)); // 8 days after the refusal

    // the window of the figures ends at `now`: the payment is beyond it
    expect(app(GameActivityStats::class)->lastWeek($now)['paywall_paid'])->toBe(0)
        ->and(app(GameActivityStats::class)->lastWeek($now->addDays(2))['paywall_learners'])->toBe(0);
});

it('compares the right grades of the players with those of the other active learners', function (): void {
    $player = Learner::factory()->create(['last_seen_at' => now()]);
    $other = Learner::factory()->create(['last_seen_at' => now()->subDay()]);
    $sleeper = Learner::factory()->create(['last_seen_at' => now()->subDays(20)]);
    $abandoner = Learner::factory()->create(['last_seen_at' => now()->subDays(20)]);

    GameRun::factory()->finished()->create(['learner_id' => $player->id, 'started_at' => now()->subDay()]);
    GameRun::factory()->finished(RunFinishReason::Abandoned)->create(['learner_id' => $abandoner->id, 'started_at' => now()->subDay()]);

    gas_reviews($player, 3, 1); // 75 %
    gas_reviews($other, 1, 3); // 25 %
    gas_reviews($sleeper, 5, 0); // not active: not counted
    gas_reviews($abandoner, 5, 0); // no finished run, not active: not counted
    gas_reviews($player, 9, 0, now()->subDays(9)); // out of the window

    expect(app(GameActivityStats::class)->lastWeek())->toMatchArray([
        'study_players_reviews' => 4, 'study_players_percent' => 75,
        'study_others_reviews' => 4, 'study_others_percent' => 25,
    ]);
});

it('keeps the query count of the widget flat as the learners and the runs grow, cold and warm cache', function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $admin = User::factory()->create(['is_active' => true, 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $fill = function (int $n): void {
        foreach (Learner::factory()->count($n)->create(['last_seen_at' => now()])->all() as $learner) {
            GameRun::factory()->finished()->count(2)->create(['learner_id' => $learner->id, 'started_at' => now()->subDay()]);
            gas_event('game.limit_reached', $learner, CarbonImmutable::now()->subDays(2));
            gas_event('payment.succeeded', $learner, CarbonImmutable::now()->subDay());
            gas_reviews($learner, 2, 1);
        }
    };
    $render = fn (): array => QueryLog::during(fn () => Livewire::test(GameActivityWidget::class)->assertOk());
    $key = WidgetCache::key('game_activity', 'all');
    $clear = fn () => PureCache::store()->deleteMultiple([$key, Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key]);

    $fill(2);
    $render(); // the first render also warms the permission and session lookups
    $clear();
    $coldSmall = $render();
    $warm = $render();

    $fill(12);
    $clear();
    $coldBig = $render();

    expect(count($coldBig))->toBe(count($coldSmall))
        ->and(QueryLog::matching($coldBig, '/game_runs|product_events|training_reviews/'))->toHaveCount(count(QueryLog::matching($coldSmall, '/game_runs|product_events|training_reviews/')))
        ->and(QueryLog::matching($warm, '/game_runs|product_events|training_reviews|"learners"/'))->toBe([])
        ->and(PureCache::store()->get($key))->toMatchArray(['players' => 14, 'paywall_learners' => 14, 'paywall_paid' => 14]);
});
