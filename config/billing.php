<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Billing: plans, limits, paywall
|--------------------------------------------------------------------------
|
| Reference: docs/12_PHASE1_DESIGN.md §3.4, §5.
| The single place where plan ranks, feature lists, limits and prices live.
| Phase 1 has no payment provider: Pro is granted manually, via a promo code
| or sponsored by an organization (docs/12 §5.5).
*/

return [
    /** TTL (seconds) of the per-learner entitlement cache ("entitlement:learner:{id}"). */
    'cache_ttl' => (int) env('BILLING_CACHE_TTL', 300),

    /**
     * Grace period (days) after a paid period ends before the subscription expires
     * (status `past_due`). Applies only to auto-renewing providers; `manual` and
     * `promo` grants have a fixed end and expire immediately (docs/12 §5.6).
     */
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),

    /**
     * Plans by key (= `subscriptions.plan`, `promo_codes.plan`). `rank` orders them:
     * a learner's effective plan is the highest-ranked one among Free, their own
     * live subscription and the live subscription of a sponsoring organization.
     * `limits.new_words_per_day`: null = unlimited.
     */
    'plans' => [
        'free' => [
            'rank' => 0,
            'limits' => ['new_words_per_day' => (int) env('BILLING_FREE_NEW_WORDS_PER_DAY', 20)],
            'features' => ['courses.public', 'training.daily', 'training.lesson'],
        ],
        'pro' => [
            'rank' => 1,
            'limits' => ['new_words_per_day' => null],
            'features' => ['courses.public', 'training.daily', 'training.lesson', 'stats.detailed', 'streak.freeze'],
        ],
    ],

    /** Filled in when a payment provider is connected: ['pro' => ['month' => ['XTR' => …, 'UZS' => …], 'year' => […]]]. */
    'prices' => [],

    /**
     * Paywall shown when the Free new-word quota is exhausted (docs/12 §5.4).
     * mode: `coming_soon` (Phase 1) | `purchase` (once a provider exists).
     * contact: how a learning center can reach us to sponsor its groups; the
     * paywall mentions it only when non-empty (resolved to null otherwise).
     */
    'paywall' => [
        'mode' => env('BILLING_PAYWALL_MODE', 'coming_soon'),
        'contact' => env('BILLING_SUPPORT_CONTACT', ''),
    ],
];
