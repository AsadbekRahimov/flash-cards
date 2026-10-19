<?php

declare(strict_types=1);

return [

    // Plural forms (ru): 1 слово | 2–4 слова | 5+ слов (see trans_choice).
    'review' => 'Пора повторить :count слово в LexiFlow.|Пора повторить :count слова в LexiFlow.|Пора повторить :count слов в LexiFlow.',
    // :count = current streak in days (docs/12 §7.5: sent when the daily goal is not met yet).
    'streak_at_risk' => 'Ваша серия — :count день. Выполните цель дня, чтобы не прервать её.|Ваша серия — :count дня. Выполните цель дня, чтобы не прервать её.|Ваша серия — :count дней. Выполните цель дня, чтобы не прервать её.',
    'open_button' => 'Открыть LexiFlow',

];
