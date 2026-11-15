<?php

declare(strict_types=1);

return [

    // Plural forms (ru): 1 слово | 2–4 слова | 5+ слов (see trans_choice).
    'review' => "Пора повторить :count слово в LexiFlow.\n\nИзменить время или отключить: /settings|Пора повторить :count слова в LexiFlow.\n\nИзменить время или отключить: /settings|Пора повторить :count слов в LexiFlow.\n\nИзменить время или отключить: /settings",
    // :count = current streak in days (docs/12 §7.5: sent when the daily goal is not met yet).
    'streak_at_risk' => "Ваша серия — :count день. Выполните цель дня, чтобы не прервать её.\n\nИзменить время или отключить: /settings|Ваша серия — :count дня. Выполните цель дня, чтобы не прервать её.\n\nИзменить время или отключить: /settings|Ваша серия — :count дней. Выполните цель дня, чтобы не прервать её.\n\nИзменить время или отключить: /settings",
    'open_button' => 'Открыть LexiFlow',

];
