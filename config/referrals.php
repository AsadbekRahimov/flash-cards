<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Referrals (docs/13 §1.5, §2.4, §7.1 — tasks 2C.1, 2C.9)
|--------------------------------------------------------------------------
|
| "Invite a friend": the inviter shares `t.me/<bot>?start=ref_<referral_code>`; a NEW learner
| created by that /start is linked (`referrals.status = pending`). The friend is counted once they
| have really started (`activation`, and the onboarding behind them). The friend themselves gets
| nothing beyond the welcome trial every new learner has (config/billing.php `trial`); the inviter
| is rewarded by MILESTONES of counted friends (owner's decision of 16.11.2026): `milestone_days`
| of Pro when the count reaches each value of `milestones`, each milestone once, nothing after the last.
| The burst flag keeps farming expensive.
*/

/** @var list<int> ascending, distinct, positive */
$milestones = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string) env('REFERRALS_MILESTONES', '3,6'))),
    static fn (int $n): bool => $n > 0,
)));
sort($milestones);

return [
    /** Counted friends at which the inviter is rewarded, ascending (`[3, 6]`: +7 days at 3 friends, +7 more at 6). */
    'milestones' => $milestones,

    /** Days of Pro the inviter gets at each milestone (provider `reward`). */
    'milestone_days' => (int) env('REFERRALS_MILESTONE_DAYS', 7),

    /**
     * Activation rule ("the friend started"): at least `days` local days with `learner_daily_stats.reviews_count >= reviews_per_day`
     * within `window_days` calendar days counted from the local day of the link (the link day is day 1), and the onboarding done.
     */
    'activation' => [
        'days' => (int) env('REFERRALS_ACTIVATION_DAYS', 1),
        'reviews_per_day' => (int) env('REFERRALS_ACTIVATION_REVIEWS_PER_DAY', 10),
        'window_days' => (int) env('REFERRALS_ACTIVATION_WINDOW_DAYS', 14),
    ],

    /**
     * Links of one inviter within 24 hours at which the NEXT link is flagged (`flagged_at`): it is not counted
     * until an admin clears the flag (Filament, learner page → Referrals).
     */
    'flag_links_per_day' => (int) env('REFERRALS_FLAG_LINKS_PER_DAY', 20),

    /** `referrals:recover` looks only at referrals activated / milestones reached within this many days (keeps the sweep an index scan). */
    'recover_window_days' => (int) env('REFERRALS_RECOVER_WINDOW_DAYS', 14),

    /** Length of a generated `learners.referral_code` (alphabet without 0/O/1/I; the column is varchar(12)). */
    'code_length' => 8,
];
