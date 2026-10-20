<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Organizations (centers / tutors)
|--------------------------------------------------------------------------
|
| Reference: docs/13_PHASE2_DESIGN.md §1.3, §6.1–6.2. Tariffs (seats, prices) live in
| config/billing.php `org_tiers`.
*/

return [
    /** Length of a generated invite code (alphabet without 0/O/1/I; `organization_invites.code` is varchar(16)). */
    'invite_code_length' => 10,

    /**
     * Joins through one invite code within 24 hours above which `organizations.join_burst` is
     * logged as a warning (docs/13 §6.1: a center "for the whole city" must be noticed, not blocked).
     */
    'invite_burst_per_day' => (int) env('ORGANIZATIONS_INVITE_BURST_PER_DAY', 50),

    'assignments' => [
        /** The group reminder goes out once, when the due date is at most this many hours away (docs/13 §1.4). */
        'remind_before_hours' => 24,

        /** An active assignment whose due date passed more than this many days ago is closed by `assignments:refresh`. */
        'overdue_grace_days' => 14,

        /** The report snapshot is recomputed on opening when older than this many minutes (docs/13 §1.4). */
        'stale_after_minutes' => 10,

        /** Failed group announcements are retried by `assignments:refresh` at most this many times. */
        'announce_attempts' => 3,

        /** Active assignments of the learner's groups shown on "Today". */
        'twa_limit' => 20,
    ],
];
