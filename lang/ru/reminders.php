<?php

declare(strict_types=1);

return [

    // Plural forms (ru): 1 слово | 2–4 слова | 5+ слов (see trans_choice).
    'review' => "Пора повторить :count слово в LexiFlow.\n\nИзменить время или отключить: /settings|Пора повторить :count слова в LexiFlow.\n\nИзменить время или отключить: /settings|Пора повторить :count слов в LexiFlow.\n\nИзменить время или отключить: /settings",
    // :count = current streak in days (docs/12 §7.5: sent when the daily goal is not met yet).
    'streak_at_risk' => "Ваша серия — :count день. Выполните цель дня, чтобы не прервать её.\n\nИзменить время или отключить: /settings|Ваша серия — :count дня. Выполните цель дня, чтобы не прервать её.\n\nИзменить время или отключить: /settings|Ваша серия — :count дней. Выполните цель дня, чтобы не прервать её.\n\nИзменить время или отключить: /settings",
    // Break in learning (owner decision 2026-11-16, docs/12 §7.5). :count = local days without lessons;
    // the plural form follows it (1 день | 2–4 дня | 5+ дней). :due is the number of due words, written
    // after a colon so it needs no agreement.
    'inactive' => "Вы :count день не занимались. Слова забываются без повторения — хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings|Вы :count дня не занимались. Слова забываются без повторения — хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings|Вы :count дней не занимались. Слова забываются без повторения — хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings",
    'inactive_due' => "Вы :count день не занимались. Слов к повторению: :due. Хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings|Вы :count дня не занимались. Слов к повторению: :due. Хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings|Вы :count дней не занимались. Слов к повторению: :due. Хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings",
    'never_started' => "Вы ещё не начали заниматься в LexiFlow. Первое занятие займёт всего 5 минут.\n\nИзменить время или отключить: /settings",
    'new_words' => "Сегодня вас ждут новые слова в LexiFlow. Хватит 5 минут.\n\nИзменить время или отключить: /settings",
    'choose_course' => "Выберите курс в LexiFlow, чтобы начать заниматься.\n\nИзменить время или отключить: /settings",
    'open_button' => 'Открыть LexiFlow',

];
