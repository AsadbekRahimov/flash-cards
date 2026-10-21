<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Billing: plans, limits, paywall
|--------------------------------------------------------------------------
|
| Reference: docs/12_PHASE1_DESIGN.md §3.4, §5; docs/13_PHASE2_DESIGN.md §5.
| The single place where plan ranks, feature lists, limits, prices, periods,
| organization tiers and payment providers live. Prices are env-driven
| (BILLING_PRICE_*): 0 / missing = not for sale.
*/

return [
    /** TTL (seconds) of the per-learner entitlement cache ("entitlement:learner:{id}"). */
    'cache_ttl' => (int) env('BILLING_CACHE_TTL', 300),

    /**
     * Grace period (days) after a paid period ends before the subscription expires
     * (status `past_due`). Applies only to the auto-renewing provider (Telegram
     * Stars); grants and pay-per-period providers have a fixed end and expire at
     * once (docs/12 §5.6, docs/13 §0).
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
            'features' => ['courses.public', 'training.daily', 'training.lesson', 'stats.detailed', 'streak.freeze', 'exam.simulator', 'exercise.spelling', 'exercise.gap_fill'],
        ],
    ],

    /**
     * Learner prices in minor units (UZS: tiyin; XTR: whole Stars), per plan x period x currency
     * (docs/13 §5). 0 / missing = not for sale in that currency. Defaults are the guide prices of
     * docs/13 §12 until the owner sets BILLING_PRICE_*.
     */
    'prices' => [
        'pro' => [
            'month' => ['XTR' => (int) env('BILLING_PRICE_PRO_MONTH_XTR', 150), 'UZS' => (int) env('BILLING_PRICE_PRO_MONTH_UZS', 2_900_000)],
            'year' => ['XTR' => (int) env('BILLING_PRICE_PRO_YEAR_XTR', 1_300), 'UZS' => (int) env('BILLING_PRICE_PRO_YEAR_UZS', 24_900_000)],
        ],
    ],

    /** Billing periods in days (`payment_orders.period`). */
    'periods' => ['month' => 30, 'year' => 365],

    /**
     * Organization tiers (docs/13 §0, §6): `subscriptions.plan` stays `pro` (what the learners
     * get), `tier` sets the seats and the price. `seats` null = unlimited; an empty `prices`
     * = manual activation by a superadmin only. Stars are not offered to organizations.
     */
    'org_tiers' => [
        'tutor' => ['seats' => 30, 'prices' => ['month' => ['UZS' => (int) env('BILLING_PRICE_TUTOR_MONTH_UZS', 12_900_000)]]],
        'center_s' => ['seats' => 100, 'prices' => ['month' => ['UZS' => (int) env('BILLING_PRICE_CENTER_S_MONTH_UZS', 34_900_000)]]],
        'center_m' => ['seats' => 300, 'prices' => ['month' => ['UZS' => (int) env('BILLING_PRICE_CENTER_M_MONTH_UZS', 84_900_000)]]],
        'center_l' => ['seats' => null, 'prices' => []],
    ],

    /**
     * Payment providers (docs/13 §4). `enabled` makes a registered adapter sellable; keys and
     * merchant ids come from the providers' cabinets. Allowlists are comma-separated IPs/CIDRs,
     * empty = off.
     */
    'providers' => [
        'telegram_stars' => [
            'enabled' => (bool) env('BILLING_STARS_ENABLED', false),
            // Telegram's cap for a Stars *subscription* price; a month above it is refused (price_not_configured).
            'max_subscription_price' => (int) env('BILLING_STARS_MAX_SUBSCRIPTION_PRICE', 10_000),
        ],
        'payme' => [
            'enabled' => (bool) env('PAYME_ENABLED', false),
            'merchant_id' => (string) env('PAYME_MERCHANT_ID', ''),
            'key' => (string) env('PAYME_KEY', ''),
            'test_key' => (string) env('PAYME_TEST_KEY', ''),
            // The test key is refused in `production` unless this is set explicitly (go-live checks on the prod host).
            'allow_test_key' => (bool) env('PAYME_ALLOW_TEST_KEY', false),
            'checkout_url' => (string) env('PAYME_CHECKOUT_URL', 'https://checkout.paycom.uz'),
            'ip_allowlist' => (string) env('PAYME_IP_ALLOWLIST', ''),
            // An unpaid Payme transaction lives 12 h; after that it is canceled with reason 4 (docs/13 §4.2).
            'transaction_timeout_ms' => 43_200_000,
            // Days after PerformTransaction during which Payme may still cancel (= refund) it; empty = always
            // (the owner's refund policy, docs/13 §12 q.7). Beyond the window CancelTransaction is answered -31007.
            'cancel_after_perform_days' => env('PAYME_CANCEL_AFTER_PERFORM_DAYS'),
            // Fiscal receipt lines of CheckPerformTransaction (`detail.items[]`); sent only when `ikpu` is set.
            'fiscal' => [
                'ikpu' => (string) env('PAYME_FISCAL_IKPU', ''),
                'package_code' => (string) env('PAYME_FISCAL_PACKAGE_CODE', ''),
                'vat_percent' => (int) env('PAYME_FISCAL_VAT_PERCENT', 0),
            ],
        ],
        'click' => [
            'enabled' => (bool) env('CLICK_ENABLED', false),
            'merchant_id' => (string) env('CLICK_MERCHANT_ID', ''),
            'service_id' => (string) env('CLICK_SERVICE_ID', ''),
            'merchant_user_id' => (string) env('CLICK_MERCHANT_USER_ID', ''),
            'secret_key' => (string) env('CLICK_SECRET_KEY', ''),
            'checkout_url' => (string) env('CLICK_CHECKOUT_URL', 'https://my.click.uz/services/pay'),
            'ip_allowlist' => (string) env('CLICK_IP_ALLOWLIST', ''),
            // An open Prepare older than this is treated as dead (Prepare -> Complete takes seconds; the margin
            // covers Click's retries of Complete): it no longer holds an offer or keeps an expired order payable.
            'transaction_timeout_ms' => (int) env('CLICK_TRANSACTION_TIMEOUT_MS', 1_800_000),
        ],
    ],

    /** Stars subscription rules (docs/13 §4.1, "Реализация 2A.8"). */
    'stars' => [
        // A Stars subscription (month) is sold on top of a live prepaid period only when that period ends within
        // this many days: Telegram's first charge starts its own 30 days at once, so the rest would be wasted.
        'subscribe_window_days' => (int) env('BILLING_STARS_SUBSCRIBE_WINDOW_DAYS', 7),
    ],

    /** Checkout rules (docs/13 §2.1, §4.4). */
    'checkout' => [
        'order_ttl_minutes' => (int) env('BILLING_ORDER_TTL_MINUTES', 1440),
        'max_pending_per_subscriber' => 3,
        // Days before the period end a non-renewing live subscription is reminded (one reminder per day value).
        'renewal_reminder_days' => [3, 1],
        // How long (days) after Pro expired `/me.entitlement.lapsed` offers "Pro ended — renew" (0 = off).
        'lapsed_banner_days' => 14,
    ],

    /** Personal offers (docs/13 §1.2 `offers`): the B2B -> B2C discount for a learner who lost a seat. */
    'offers' => [
        'post_org_discount' => ['pct' => (int) env('BILLING_POST_ORG_DISCOUNT_PCT', 30), 'valid_days' => 14, 'period' => 'month'],
    ],

    // Referral rewards (docs/13 §2.4, 2C.1) live in config/referrals.php (env-backed).

    /**
     * Promo codes (docs/12 §8). Every failed redemption is a `promo.rejected`
     * product event; `rejections_alert` logs a warning (`billing.promo_rejected_burst`)
     * when one learner collects `threshold` rejections within `window` seconds
     * (code guessing / a broken client). threshold 0 disables the alert.
     */
    'promo' => [
        'rejections_alert' => [
            'threshold' => (int) env('BILLING_PROMO_REJECTIONS_ALERT_THRESHOLD', 5),
            'window' => (int) env('BILLING_PROMO_REJECTIONS_ALERT_WINDOW', 600),
        ],
    ],

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

    /**
     * "Request an invoice" in the `/org` cabinet (docs/13 §6.5): the owner's request goes by e-mail
     * to this address (the platform owner / a superadmin); empty = `site.legal.email`; both empty =
     * the button is hidden.
     */
    'invoice' => [
        'request_email' => env('BILLING_INVOICE_REQUEST_EMAIL', ''),
    ],
];
