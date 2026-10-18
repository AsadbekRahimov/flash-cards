<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | User-facing locales (bot messages, reminders, 2FA pages)
    |--------------------------------------------------------------------------
    |
    | Interface languages only — the learned language is always English.
    | `default` is used for teachers and for anything we cannot map; `fallback`
    | is the last resort if `default` is ever set to an unsupported value.
    | See App\Domain\Localization\Services\LocaleResolver.
    |
    */

    'supported' => ['ru', 'uz'],

    'default' => 'ru',

    'fallback' => 'ru',

];
