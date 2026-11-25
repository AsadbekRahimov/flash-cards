<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL around the daily goal and the plan (docs/34 §2б.2, P2; lock order docs/06 §4.2): parallel enforce() calls,
 * enforce() against a goal chosen in the settings, enforce() against a grade (no deadlock, the day credited exactly once), and a
 * parallel re-delivery of a Stars payment bought during the trial. Each contender is a forked process with its own connection;
 * committed rows: DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Learners\Services\DailyGoalLimiter;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Payment;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the daily goal limiter on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config()->set('billing.plans.free.limits.daily_goal_max', 10);
    config()->set('learners.daily_goal_options', [10, 20, 40]);
});

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function goalRaceInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-goal-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = "{$dir}/go";
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $outcome = [];
            try {
                ForkSafe::releaseConnections();
                // A deadlock must surface as an error, not as a hang of the suite.
                DB::statement("SET lock_timeout = '10s'");

                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $outcome = ['ok' => true, 'result' => $task()];
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($outcome, JSON_THROW_ON_ERROR));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[] = $pid;
    }

    touch($go);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($tasks) as $i) {
        /** @var array{ok: bool, result?: mixed, error?: string, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

/** @param  list<array{ok: bool, result?: mixed, error?: string, message?: string}>  $results */
function goalRaceAllOk(array $results): void
{
    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
}

function goalRaceEvents(int $learnerId, ProductEventName $name): int
{
    return ProductEvent::query()->where('learner_id', $learnerId)->where('name', $name->value)->count();
}

it('lowers the goal once when three requests enforce it at the same instant', function (): void {
    $learner = Learner::factory()->onboarded()->create(['daily_goal' => 40]);

    $results = goalRaceInParallel(array_fill(0, 3, function () use ($learner): ?string {
        // Every contender holds a copy of the row that still says 40.
        $copy = Learner::query()->findOrFail($learner->id);

        return app(DailyGoalLimiter::class)->enforce($copy, Entitlement::free(), DailyGoalLimiter::VIA_TODAY)?->kind;
    }));

    goalRaceAllOk($results);
    expect(collect($results)->pluck('result')->filter()->values()->all())->toBe(['limited'])
        ->and($learner->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => 40])
        ->and(goalRaceEvents($learner->id, ProductEventName::DailyGoalLimited))->toBe(1)
        ->and(ProductEvent::query()->where('name', 'daily_goal.limited')->sole()->props)->toEqual(['from' => 40, 'to' => 10, 'via' => 'today']);
});

it('ends with the goal chosen in the settings whichever of enforce and PATCH wins', function (int $round): void {
    // Free, 40 -> enforce lowers; the learner picks 10 by hand at the same moment. Either order: 10 and nothing remembered.
    $free = Learner::factory()->onboarded()->create(['daily_goal' => 40]);
    // Pro is back, 10 (40 remembered) -> enforce restores; the learner picks 20 by hand. Either order: 20 and nothing remembered.
    $pro = Learner::factory()->onboarded()->create(['daily_goal' => 10, 'daily_goal_before_limit' => 40]);
    Subscription::factory()->create(['subscriber_id' => $pro->id, 'provider' => 'manual', 'current_period_ends_at' => now()->addDays(30)]);
    $proPlan = Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, null);
    $stagger = $round % 2 === 0 ? 0 : 20_000;

    $results = goalRaceInParallel([
        function () use ($free, $stagger): string {
            usleep($stagger);
            app(DailyGoalLimiter::class)->enforce(Learner::query()->findOrFail($free->id), Entitlement::free(), DailyGoalLimiter::VIA_TODAY);

            return 'enforce';
        },
        function () use ($free, $stagger): string {
            usleep(20_000 - $stagger);
            app(LearnerSettingsService::class)->update(Learner::query()->findOrFail($free->id), ['daily_goal' => 10]);

            return 'patch';
        },
        function () use ($pro, $proPlan, $stagger): string {
            usleep($stagger);
            app(DailyGoalLimiter::class)->enforce(Learner::query()->findOrFail($pro->id), $proPlan, DailyGoalLimiter::VIA_SETTINGS);

            return 'enforce';
        },
        function () use ($pro, $stagger): string {
            usleep(20_000 - $stagger);
            app(LearnerSettingsService::class)->update(Learner::query()->findOrFail($pro->id), ['daily_goal' => 20]);

            return 'patch';
        },
    ]);

    goalRaceAllOk($results);
    expect($free->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => null])
        ->and($pro->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 20, 'daily_goal_before_limit' => null]);
})->with([1, 2, 3, 4]);

it('credits the day exactly once and never deadlocks when a lowering meets a grade', function (string $first): void {
    CarbonImmutable::setTestNow('2026-11-25 10:00:00');
    // 9 cards done of 40, a series of 2 up to yesterday: the 10th card and the lowering to 10 race for completing the day.
    $learner = Learner::factory()->onboarded()->create([
        'timezone' => 'Asia/Tashkent', 'daily_goal' => 40,
        'streak_current' => 2, 'streak_best' => 2, 'streak_last_date' => '2026-11-24',
    ]);
    for ($i = 0; $i < 9; $i++) {
        DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 400, false, CarbonImmutable::now()));
    }

    $results = goalRaceInParallel([
        function () use ($learner, $first): string {
            CarbonImmutable::setTestNow('2026-11-25 10:00:00');
            usleep($first === 'grade' ? 50_000 : 0);
            app(DailyGoalLimiter::class)->enforce(Learner::query()->findOrFail($learner->id), Entitlement::free(), DailyGoalLimiter::VIA_TODAY);

            return 'enforce';
        },
        function () use ($learner, $first): string {
            CarbonImmutable::setTestNow('2026-11-25 10:00:00');
            usleep($first === 'enforce' ? 50_000 : 0);
            // The review transaction: it holds today's row of learner_daily_stats (and never `learners`) for a while.
            DB::transaction(function () use ($learner): void {
                app(DailyStatsService::class)->recordReview(Learner::query()->findOrFail($learner->id), 4, 400, false, CarbonImmutable::now());
                usleep(300_000);
            });

            return 'grade';
        },
    ]);

    goalRaceAllOk($results);
    $row = LearnerDailyStats::query()->where('learner_id', $learner->id)->sole();
    expect($row->reviews_count)->toBe(10)
        ->and($row->goal_met_at)->not->toBeNull()
        ->and($learner->fresh()->only(['daily_goal', 'streak_current', 'streak_best']))->toBe(['daily_goal' => 10, 'streak_current' => 3, 'streak_best' => 3])
        ->and(goalRaceEvents($learner->id, ProductEventName::DailyGoalMet))->toBe(1)
        ->and(goalRaceEvents($learner->id, ProductEventName::DailyGoalLimited))->toBe(1);
})->with(['enforce', 'grade', 'together']);

it('credits a Stars month bought on the trial once when the same payment arrives twice at the same instant', function (): void {
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    $learner = Learner::factory()->onboarded()->create();
    $trial = app(SubscriptionService::class)->grantTrial($learner, 14, CarbonImmutable::now());
    $trialEnd = CarbonImmutable::instance($trial->current_period_ends_at);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    $message = ['message_id' => 1, 'date' => CarbonImmutable::now()->getTimestamp(), 'chat' => ['id' => $learner->telegram_user_id, 'type' => 'private'],
        'from' => ['id' => $learner->telegram_user_id, 'first_name' => 'Ali'],
        'successful_payment' => ['currency' => 'XTR', 'total_amount' => $order->amount, 'invoice_payload' => $order->token,
            'telegram_payment_charge_id' => 'ch_race', 'provider_payment_charge_id' => 'p_race',
            'subscription_expiration_date' => CarbonImmutable::now()->addDays(30)->getTimestamp(), 'is_recurring' => true, 'is_first_recurring' => true]];

    $results = goalRaceInParallel(array_fill(0, 3, function () use ($message): string {
        try {
            app(PaymentApplier::class)->applyCharge(app(TelegramStarsProvider::class)->parseSuccessfulPayment($message));

            return 'credited';
        } catch (DuplicatePaymentException) {
            return 'duplicate';
        }
    }));

    goalRaceAllOk($results);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['credited', 'duplicate', 'duplicate'])
        ->and(Payment::query()->count())->toBe(1)
        ->and(CarbonImmutable::instance(Subscription::query()->sole()->current_period_ends_at)->toIso8601String())->toBe($trialEnd->addDays(30)->toIso8601String());
});
