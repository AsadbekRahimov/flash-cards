<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Referrals (docs/13 §1.5, §2.4, §7.1 — task 2C.1)
|--------------------------------------------------------------------------
|
| "Invite a friend": the inviter shares `t.me/<bot>?start=ref_<referral_code>`; a NEW learner
| created by that /start is linked (`referrals.status = pending`). The referral is activated
| once the friend has really studied (`activation`), and only then both get `reward_days` of Pro.
| Limits per inviter and the burst flag keep farming expensive.
*/

return [
    /** Days of Pro each side gets when a referral is activated (provider `reward`). */
    'reward_days' => (int) env('REFERRALS_REWARD_DAYS', 7),

    /**
     * Activation rule: at least `days` local days with `learner_daily_stats.reviews_count >= reviews_per_day`
     * within `window_days` calendar days counted from the local day of the link (the link day is day 1).
     */
    'activation' => [
        'days' => (int) env('REFERRALS_ACTIVATION_DAYS', 3),
        'reviews_per_day' => (int) env('REFERRALS_ACTIVATION_REVIEWS_PER_DAY', 10),
        'window_days' => (int) env('REFERRALS_ACTIVATION_WINDOW_DAYS', 14),
    ],

    /** Rewarded referrals one inviter may collect in a rolling 30 days / in total; beyond — `rejected {referrer_capped}`. */
    'max_rewards_per_30d' => (int) env('REFERRALS_MAX_REWARDS_PER_30D', 10),
    'max_rewards_lifetime' => (int) env('REFERRALS_MAX_REWARDS_LIFETIME', 50),

    /**
     * Rewarded invited learners of one inviter in a rolling 30 days (owner's decision, docs/13 §12 q.17); the next
     * invited learner is activated and `rewarded` but gets no Pro (`meta.rewards.referee.skipped = invitee_capped`).
     */
    'max_invitee_rewards_per_30d' => (int) env('REFERRALS_INVITEE_REWARDS_PER_30D', 20),

    /**
     * Links of one inviter within 24 hours at which the NEXT link is flagged (`flagged_at`): its reward
     * waits until an admin clears the flag (Filament, learner page → Referrals).
     */
    'flag_links_per_day' => (int) env('REFERRALS_FLAG_LINKS_PER_DAY', 20),

    /** Length of a generated `learners.referral_code` (alphabet without 0/O/1/I; the column is varchar(12)). */
    'code_length' => 8,
];
