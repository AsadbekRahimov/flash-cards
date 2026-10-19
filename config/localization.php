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

    /*
    | Each language in its own name, for the language picker of the onboarding — the same
    | whatever the current locale, so a person can always find their language.
    */
    'names' => [
        'ru' => '🇷🇺 Русский',
        'uz' => "🇺🇿 O'zbekcha",
    ],

];
