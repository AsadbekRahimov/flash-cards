<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;

/*
 * The tariff numbers of the launch (docs/34 §1): without env the config files give 14 days of the welcome trial,
 * 10 new words a day and 3 practice sessions a day for Free, and 7 days of Pro for a referral milestone. The env
 * variables are hidden for the time of the reading, so a local `.env` with the older numbers does not mask the defaults.
 */

/**
 * Reads a config file with the given env variables unset (and restores them afterwards).
 *
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function configWithoutEnv(string $file, array $keys): array
{
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path($file);

        return $config;
    } finally {
        foreach ($saved as $key => [$server, $env, $put]) {
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($put !== false) {
                putenv($key.'='.$put);
            }
        }
    }
}

it('gives a new learner 14 days of the welcome trial by default', function (): void {
    $billing = configWithoutEnv('billing.php', ['BILLING_TRIAL_DAYS', 'BILLING_FREE_NEW_WORDS_PER_DAY']);

    expect($billing['trial']['days'])->toBe(14);
});

it('gives a Free learner 10 new words a day by default', function (): void {
    $billing = configWithoutEnv('billing.php', ['BILLING_TRIAL_DAYS', 'BILLING_FREE_NEW_WORDS_PER_DAY']);

    expect($billing['plans']['free']['limits']['new_words_per_day'])->toBe(10)
        ->and($billing['plans']['pro']['limits']['new_words_per_day'])->toBeNull();

    config()->set('billing.plans.free.limits.new_words_per_day', $billing['plans']['free']['limits']['new_words_per_day']);
    expect(Plan::Free->newWordsPerDay())->toBe(10);
});

it('gives a Free learner 3 practice sessions and 1 battle a day by default', function (): void {
    $practice = configWithoutEnv('practice.php', ['PRACTICE_FREE_SESSIONS_PER_DAY', 'PRACTICE_FREE_BATTLES_PER_DAY']);

    expect((int) $practice['free_limits']['sessions_per_day'])->toBe(3)
        ->and((int) $practice['free_limits']['battles_per_day'])->toBe(1);
});

it('keeps the referral milestone reward at 7 days by default', function (): void {
    $referrals = configWithoutEnv('referrals.php', ['REFERRALS_MILESTONE_DAYS']);

    expect($referrals['milestone_days'])->toBe(7);
});
