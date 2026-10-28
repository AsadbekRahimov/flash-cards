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
];
