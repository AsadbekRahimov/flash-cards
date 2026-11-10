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

    /**
     * One-time deep links of the cabinet (docs/13 §6.2, audit 2026-11-10 H1 / P1): `t.me/<bot>?start=link_<token>` connects the
     * staff user's Telegram account, `?startgroup=grp_<token>` / `/link <token>` links a group. Tokens are CSPRNG, stored hashed.
     */
    'telegram_link' => [
        'account_ttl_minutes' => 15,
        'group_ttl_minutes' => 30,
        /** Links one user may issue per 10 minutes (both kinds together). */
        'issue_per_10_minutes' => 10,
        /** `/link` / `/start grp_` attempts one Telegram sender may make per minute (beyond — dropped without an answer). */
        'attempts_per_minute' => 10,
    ],

    /**
     * A group linked from a chat by a verified owner of a center with a live subscription is activated at once instead of
     * waiting for the platform's administrator (audit 2026-11-10 §4 B1). The group goes back to `pending` when the center
     * unlinks it. Set to false to keep every new group behind the manual activation of `/admin`.
     */
    'auto_activate_linked_groups' => (bool) env('ORGANIZATIONS_AUTO_ACTIVATE_LINKED_GROUPS', true),

    /**
     * "Add a learner" from the cabinet is an INVITATION the learner accepts in the bot (audit 2026-11-10 M2, review finding B1).
     * Limits are per hour and count every call, found learner or not (the answer to the staff is the same either way).
     */
    'member_invite' => [
        'per_organization_per_hour' => (int) env('ORGANIZATIONS_MEMBER_INVITES_PER_ORG_PER_HOUR', 30),
        'per_staff_per_hour' => (int) env('ORGANIZATIONS_MEMBER_INVITES_PER_STAFF_PER_HOUR', 20),
        /** An invitation reads "waiting" in the cabinet for this many days and "expired" after; a new one may then be sent. */
        'ttl_days' => 7,
        /**
         * W7: one center sends one "Join / Decline" DM to one learner at most once per this many days, whatever the staff do in
         * between (revoke and invite again, let it expire and invite again). The cabinet shows every attempt the same way.
         */
        'dm_cooldown_days' => (int) env('ORGANIZATIONS_MEMBER_INVITE_DM_COOLDOWN_DAYS', 7),
        /** A learner who declined gets no DM from the same center for this many days (the invitation row is still written). */
        'redecline_cooldown_days' => 7,
    ],

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
