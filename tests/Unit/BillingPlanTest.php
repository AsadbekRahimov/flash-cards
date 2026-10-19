<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Exceptions\PlanException;

it('ranks plans and reads limits and features from config/billing.php', function (): void {
    expect(Plan::Free->rank())->toBe(0)
        ->and(Plan::Pro->rank())->toBe(1)
        ->and(Plan::Pro->outranks(Plan::Free))->toBeTrue()
        ->and(Plan::Free->outranks(Plan::Pro))->toBeFalse()
        ->and(Plan::Free->outranks(Plan::Free))->toBeFalse()
        ->and(Plan::Free->newWordsPerDay())->toBe(20)
        ->and(Plan::Pro->newWordsPerDay())->toBeNull()
        ->and(Plan::Free->features())->toBe(['courses.public', 'training.daily', 'training.lesson'])
        ->and(Plan::Pro->features())->toContain('stats.detailed');

    config()->set('billing.plans.free.limits.new_words_per_day', -5);
    expect(Plan::Free->newWordsPerDay())->toBe(0);
});

it('accepts only configured plan keys and reports the rest as a domain error (plan_unknown, 422)', function (): void {
    expect(Plan::fromKey('pro'))->toBe(Plan::Pro)
        ->and(fn () => Plan::fromKey('platinum'))->toThrow(PlanException::class);

    try {
        Plan::fromKey('platinum');
    } catch (PlanException $e) {
        expect($e)->toBeInstanceOf(BillingException::class)
            ->and($e->errorCode)->toBe(PlanException::UNKNOWN)
            ->and($e->httpStatus())->toBe(422)
            ->and($e->getMessage())->toContain('platinum');
    }

    config()->set('billing.plans.pro', null);
    expect(fn () => Plan::fromKey('pro'))->toThrow(PlanException::class);
});

it('knows which statuses are live and which providers have a grace period', function (): void {
    expect(SubscriptionStatus::liveValues())->toBe(['trialing', 'active', 'past_due'])
        ->and(SubscriptionStatus::Canceled->isLive())->toBeFalse()
        ->and(SubscriptionStatus::Expired->isLive())->toBeFalse()
        ->and(SubscriptionProvider::Manual->hasGrace())->toBeFalse()
        ->and(SubscriptionProvider::Promo->hasGrace())->toBeFalse()
        ->and(SubscriptionProvider::TelegramStars->hasGrace())->toBeTrue()
        ->and(SubscriptionProvider::Payme->hasGrace())->toBeTrue()
        ->and(SubscriptionProvider::Click->hasGrace())->toBeTrue();
});
