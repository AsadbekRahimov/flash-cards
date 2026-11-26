<?php

declare(strict_types=1);

/*
 * The limit of the daily goal by plan (docs/34 §2б.1, P1): config, Plan::dailyGoalMax(), Entitlement::$dailyGoalMax, and the
 * consistency of the shipped config - the Free limit is one of the offered goals and the default goal of a new learner fits it.
 */

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;

it('ships a Free limit that is one of the offered goals, a default goal not above it, and no limit for Pro', function (): void {
    $options = array_map('intval', (array) config('learners.daily_goal_options'));
    $freeMax = Plan::Free->dailyGoalMax();

    expect($freeMax)->toBe(10)
        ->and(Plan::Pro->dailyGoalMax())->toBeNull()
        ->and($options)->toContain($freeMax)
        ->and((int) config('learners.default_daily_goal'))->toBeLessThanOrEqual($freeMax)
        ->and($options)->toContain((int) config('learners.default_daily_goal'));
});

it('reads the limit from the config of now, a null limit is no limit and a silly one is at least 1', function (): void {
    config()->set('billing.plans.free.limits.daily_goal_max', 20);
    expect(Plan::Free->dailyGoalMax())->toBe(20);

    config()->set('billing.plans.free.limits.daily_goal_max', null);
    expect(Plan::Free->dailyGoalMax())->toBeNull();

    config()->set('billing.plans.free.limits.daily_goal_max', 0);
    expect(Plan::Free->dailyGoalMax())->toBe(1);
});

it('carries the limit in the entitlement from the plan, also from an older cache snapshot, without writing it there', function (): void {
    $free = Entitlement::free();
    $pro = Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, 7);

    expect($free->dailyGoalMax)->toBe(10)
        ->and($pro->dailyGoalMax)->toBeNull()
        // The cache form keeps its keys (no new key = no new cache version, docs/19 §3).
        ->and(array_keys($free->toArray()))->toBe(['plan', 'source', 'until', 'subscription_id', 'features', 'new_words_per_day']);

    // A snapshot written before this release (no limit in it) reads the limit of the plan now.
    $old = Entitlement::fromArray(['plan' => 'free', 'source' => 'free', 'until' => null, 'subscription_id' => null]);
    expect($old->dailyGoalMax)->toBe(10);

    config()->set('billing.plans.free.limits.daily_goal_max', 20);
    expect(Entitlement::fromArray($free->toArray())->dailyGoalMax)->toBe(20);
});
