<?php

declare(strict_types=1);

return [

    'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку.',

    // The five onboarding steps (docs/12 §7.1), one message each.
    'steps' => [
        'locale' => 'Выберите язык интерфейса',
        'goal' => 'Для чего вам английский?',
        'level' => 'Какой у вас уровень? Оцените себя как получится — позже его можно изменить.',
        'timezone' => 'В каком часовом поясе вы живёте? Так напоминания придут вовремя.',
        'reminder' => 'Когда напоминать о повторении слов?',
    ],

    'options' => [
        'goal' => [
            'general' => 'Общий английский',
            'ielts' => 'IELTS',
            'sat' => 'SAT',
            'school' => 'Школа',
        ],
        'level' => [
            'A1' => 'A1 — начинаю',
            'A2' => 'A2 — базовый',
            'B1' => 'B1 — средний',
            'B2' => 'B2 — выше среднего',
            'unknown' => 'Не знаю',
        ],
        // City names; `:offset` is filled in from the current UTC offset of the zone.
        'timezone' => [
            'tashkent' => 'Ташкент (:offset)',
            'almaty' => 'Алматы (:offset)',
            'bishkek' => 'Бишкек (:offset)',
            'dushanbe' => 'Душанбе (:offset)',
            'ashgabat' => 'Ашхабад (:offset)',
            'moscow' => 'Москва (:offset)',
            'istanbul' => 'Стамбул (:offset)',
        ],
        'reminder' => [
            'time' => ':time',
            'off' => 'Без напоминаний',
        ],
    ],

    'done' => [
        'text' => 'Готово! Ваш курс: :course. Нажмите кнопку — первая тренировка займёт пару минут.',
        'text_plain' => 'Готово! Нажмите кнопку — первая тренировка займёт пару минут.',
        'button' => '🎯 Начать первую тренировку',
    ],

    // `/start c_<slug>` of a learner who has already finished the onboarding: the course opens in the app.
    'course_link' => [
        'text' => 'Курс «:course» доступен в LexiFlow. Откройте его кнопкой ниже — текущий курс не изменится, пока вы сами его не выберете.',
        'button' => 'Открыть курс',
    ],

    // Minimal "Today" in the bot; the full screen is in the app.
    'today' => [
        // Plural forms (ru): 1 слово | 2–4 слова | 5+ слов (see trans_choice).
        'due' => 'Сегодня к повторению: :count слово.|Сегодня к повторению: :count слова.|Сегодня к повторению: :count слов.',
        'empty' => 'На сегодня повторений нет. Откройте LexiFlow, чтобы выучить новые слова.',
        'button' => 'Открыть LexiFlow',
    ],

    'menu' => [
        'text' => "Что дальше?\n/today — занятие на сегодня\n/settings — настройки\n/promo КОД — промокод на Pro\n/help — справка",
    ],

    'settings' => [
        'menu' => 'Что хотите изменить?',
        'saved' => 'Сохранено.',
        'timezone_too_soon' => 'Часовой пояс можно менять не чаще одного раза в сутки. Следующая смена — после :when (по вашему текущему времени).',
        'timezone_undo' => 'Отменить',
        'timezone_reverted' => 'Часовой пояс возвращён.',
        'timezone_revert_unavailable' => 'Отменить смену часового пояса уже нельзя: прошло больше 10 минут или она уже отменена.',
        'buttons' => [
            'locale' => 'Язык',
            'goal' => 'Цель',
            'level' => 'Уровень',
            'timezone' => 'Часовой пояс',
            'reminder' => 'Напоминания',
        ],
    ],

];
