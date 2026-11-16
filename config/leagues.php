<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Leagues (docs/13 §1.5, §2.4, §7.3 — task 2C.3)
|--------------------------------------------------------------------------
|
| Weekly groups of up to `capacity` learners per tier. A learner joins the current week's group
| of their tier on the first graded card of the week (after the review transaction commits).
| Points are never written in the hot path: they are summed from `learner_daily_stats` for the
| dates of the week. On Monday 00:00 (`timezone`, a safety run at 00:05) the week is closed group by
| group: the top `promote` of every group move one tier up, the bottom `demote` move one tier down.
*/

return [
    /** Master switch: `false` = nobody is placed, the API answers "no league", nothing is closed. */
    'enabled' => (bool) env('LEAGUES_ENABLED', true),

    /** One boundary for everyone: weeks run Monday–Sunday in this zone (docs/13 §7.3). */
    'timezone' => env('LEAGUES_TIMEZONE', 'Asia/Tashkent'),

    /**
     * Members per group; a new group of the tier is opened when every existing one is full. At most 500, whatever the
     * environment says: the final points / ranks / results of a group are written in ONE statement with 4 bound
     * parameters per member (LeagueService::writeOutcomes) and PostgreSQL takes at most 65 535 parameters per
     * statement (16 383 members) - a larger group would never close. A group of hundreds is no league anyway.
     */
    'capacity' => min(500, (int) env('LEAGUES_GROUP_CAPACITY', 30)),

    /** Top-N of a group promoted / bottom-N demoted at the close (tier 0 never demotes, the top tier never promotes). */
    'promote' => (int) env('LEAGUES_PROMOTE', 5),
    'demote' => (int) env('LEAGUES_DEMOTE', 5),

    /** Tier keys, index = `league_groups.tier` (0 = the lowest). Names live in lang/<locale>/leagues.php and the TWA. */
    'tiers' => ['bronze', 'silver', 'gold', 'platinum', 'diamond'],

    /** Weights of the points formula over `learner_daily_stats` rows of the week (docs/13 §2.4). */
    'points' => [
        'review' => (int) env('LEAGUES_POINTS_REVIEW', 1),
        'correct' => (int) env('LEAGUES_POINTS_CORRECT', 1),
        'new_word' => (int) env('LEAGUES_POINTS_NEW_WORD', 2),
        'goal_met' => (int) env('LEAGUES_POINTS_GOAL_MET', 10),
    ],

    /** How long a group's standings stay cached (`league:standings:{group}`). */
    'standings_cache_seconds' => (int) env('LEAGUES_STANDINGS_CACHE_SECONDS', 60),

    /** A week-result DM that could not be sent within this many hours (no Telegram slot, queue lag) is dropped. */
    'notify_ttl_hours' => (int) env('LEAGUES_NOTIFY_TTL_HOURS', 12),

    /** Members per `SendLeagueResultsJob` (one job sends a batch of DMs, each through the bot-wide send budget). */
    'notify_batch_size' => (int) env('LEAGUES_NOTIFY_BATCH_SIZE', 100),

    /** Seconds one batch job may spend sending before it hands the rest to a fresh job (keep well below the worker --timeout). */
    'notify_batch_seconds' => (int) env('LEAGUES_NOTIFY_BATCH_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Monthly rating with prizes (docs/13 §7.6, owner's decision of 2026-11-16)
    |--------------------------------------------------------------------------
    |
    | One rating of everybody for the calendar month of `timezone`, on the SAME points as the weekly leagues
    | (`points` above + `game_points`), summed from `learner_daily_stats` by the local date of the row — the rule the
    | weeks use. The sums are materialised in `league_month_scores` outside the review transaction
    | (`leagues:refresh-month`, the finish screen of a session), so the top-N and a learner's place are two indexed reads.
    | The prizes are handed out BY HAND: the month closes into `pending_review`, an administrator confirms the winners.
    */
    'monthly' => [
        /** Off = no month rows, no refresh, no close; the API answers "disabled". The weekly leagues do not depend on it. */
        'enabled' => (bool) env('LEAGUES_MONTHLY_ENABLED', true),

        /**
         * The most points ONE local day can add to the monthly sum. The weekly formula is not capped (the weekly groups stay as
         * they are); the cap protects the prizes from a script that grades thousands of cards a day. 0 = no cap.
         */
        'daily_cap' => (int) env('LEAGUES_MONTHLY_DAILY_CAP', 300),

        /** Rows of the public top list (`GET /api/twa/league/month`). */
        'top' => (int) env('LEAGUES_MONTHLY_TOP', 50),

        /** Prize places (1..places get a prize when the month is confirmed). */
        'places' => (int) env('LEAGUES_MONTHLY_PLACES', 3),

        /** Candidates frozen at the close (the best eligible participants), a reserve for disqualifications. */
        'candidates' => (int) env('LEAGUES_MONTHLY_CANDIDATES', 10),

        /** Prize eligibility: at least this many days of the month with points, and the account at least this old (days) at the month's end. */
        'min_active_days' => (int) env('LEAGUES_MONTHLY_MIN_ACTIVE_DAYS', 10),
        'min_account_days' => (int) env('LEAGUES_MONTHLY_MIN_ACCOUNT_DAYS', 14),

        /**
         * Minutes after the 1st 00:00 (`timezone`) before the month is closed, so that learners whose local day lags behind the
         * league zone still get their last evening counted (00:00 Tashkent is 22:00 in Istanbul). The schedule of
         * `leagues:close-month` is derived from it (routes/console.php). Up to 23 h.
         */
        'close_delay_minutes' => min(23 * 60, max(0, (int) env('LEAGUES_MONTHLY_CLOSE_DELAY_MINUTES', 180))),

        /** The incremental refresh recomputes the learners with a day row dated within this many days back (a day row older than that never changes). */
        'refresh_recent_days' => (int) env('LEAGUES_MONTHLY_REFRESH_RECENT_DAYS', 3),

        /** How long the public top list stays cached (`league:month:top:v1:{month}`). */
        'top_cache_seconds' => (int) env('LEAGUES_MONTHLY_TOP_CACHE_SECONDS', 120),

        /**
         * The prizes of a month that has none of its own (`league_months.prizes` IS NULL), by place, ru / uz. An empty title for a
         * place = no prize for it; no prizes at all = the prize block is hidden and the rating still runs.
         * Empty by default: a prize is a promise to everybody who sees it, so none is made until the owner sets one (here, or per month in /admin).
         */
        'prizes' => [
            1 => ['ru' => env('LEAGUES_MONTHLY_PRIZE_1_RU', ''), 'uz' => env('LEAGUES_MONTHLY_PRIZE_1_UZ', '')],
            2 => ['ru' => env('LEAGUES_MONTHLY_PRIZE_2_RU', ''), 'uz' => env('LEAGUES_MONTHLY_PRIZE_2_UZ', '')],
            3 => ['ru' => env('LEAGUES_MONTHLY_PRIZE_3_RU', ''), 'uz' => env('LEAGUES_MONTHLY_PRIZE_3_UZ', '')],
        ],

        /**
         * Thresholds of the signals shown next to a candidate (nothing is decided by them — the administrator does):
         * accuracy below / above (self-graded cards: 100 % of "easy" is as odd as 40 %), the mean time per graded card below,
         * the account younger than, the best day above `spike_ratio` × the mean day, the cap hit on at least `capped_days`.
         */
        'signals' => [
            'min_accuracy' => (float) env('LEAGUES_MONTHLY_SIGNAL_MIN_ACCURACY', 0.55),
            'max_accuracy' => (float) env('LEAGUES_MONTHLY_SIGNAL_MAX_ACCURACY', 0.98),
            'min_ms_per_review' => (int) env('LEAGUES_MONTHLY_SIGNAL_MIN_MS_PER_REVIEW', 1500),
            'young_account_days' => (int) env('LEAGUES_MONTHLY_SIGNAL_YOUNG_ACCOUNT_DAYS', 30),
            'spike_ratio' => (float) env('LEAGUES_MONTHLY_SIGNAL_SPIKE_RATIO', 3.0),
            'capped_days' => (int) env('LEAGUES_MONTHLY_SIGNAL_CAPPED_DAYS', 5),
        ],
    ],
];
