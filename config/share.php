<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| "Share" through the bot (docs/36 §1в, docs/04 A.22)
|--------------------------------------------------------------------------
|
| The Mini App asks the server to prepare a message (`savePreparedInlineMessage`) and hands its id to `WebApp.shareMessage`.
*/

return [
    /** Master switch: `false` = `POST /share/prepare` answers 403 `share_unavailable`, the Mini App opens `t.me/share/url`. */
    'enabled' => (bool) env('SHARE_ENABLED', true),

    'prepare' => [
        /** `throttle:twa-share`, per learner: cached answers count too (docs/07 §6). */
        'per_minute' => 20,
        'per_day' => 100,

        /**
         * A prepared message is cached until Telegram's `expiration_date` minus `cache_margin_seconds`, but never longer than
         * `cache_max_seconds`: the id must still work when the learner picks a chat.
         */
        'cache_max_seconds' => 3600,
        'cache_margin_seconds' => 300,

        /**
         * Two taps at once: the second waits for the first at most `lock_wait_seconds`, then gets 503 (the client falls back). The lock
         * outlives one Bot API call (the SDK timeout is 30 s), so it is not lost while the first request still waits for Telegram.
         */
        'lock_seconds' => 40,
        'lock_wait_seconds' => 3,
    ],

    /** `throttle:twa-share-event` (`POST /share/events`, analytics only), per learner. */
    'events_per_minute' => 30,
];
