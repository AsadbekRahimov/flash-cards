<?php

declare(strict_types=1);

return [

    'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку: /paysupport.',

    // The first message of a new person is a welcome in both languages (the language is chosen only at the first step):
    // this paragraph, then the `welcome` of lang/uz (OnboardingMessenger).
    'welcome' => '👋 Привет! Я Muhrla — помогаю учить английские слова по 5–10 минут в день. Пара коротких вопросов — и начнём.',
    'step_counter' => 'Шаг :n из :total',
    'invited_by_organization' => 'Вас пригласил центр «:organization»',
    'invited_by_learner' => 'Вас пригласил(а) :name',

    // The onboarding steps (the goal one only while several goals are enabled; no time zone step) (docs/12 §7.1), one message each.
    'steps' => [
        'locale' => 'Выберите язык интерфейса',
        // The region (docs/35 §2.3): the first screen, then the list of the regions of Uzbekistan in the same message.
        'region' => 'Где вы живёте? Это нужно для рейтинга вашей области.',
        'region_list' => 'Выберите вашу область',
        'goal' => 'Для чего вам английский?',
        'level' => 'Какой у вас уровень? Оцените себя как получится — позже его можно изменить.',
        'reminder' => 'Когда напоминать о повторении слов?',
    ],

    'options' => [
        'region' => [
            'uzbekistan' => '🇺🇿 Узбекистан',
            'other' => '🌍 Другая страна',
            'none' => 'Не указывать',
            'back' => '← Назад',
        ],
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
            'C1' => 'C1 — продвинутый',
            'C2' => 'C2 — свободно',
            'unknown' => 'Не знаю',
        ],
        'reminder' => [
            'time' => ':time',
            'off' => 'Без напоминаний',
        ],
    ],

    'done' => [
        'text' => "Готово! Ваш курс: :course. Нажмите кнопку — первое занятие займёт пару минут.\n\nПригласите друзей — получите Pro бесплатно: /invite",
        'text_plain' => "Готово! Нажмите кнопку — первое занятие займёт пару минут.\n\nПригласите друзей — получите Pro бесплатно: /invite",
        'button' => '🎯 Начать первое занятие',
    ],

    // `/start c_<slug>` of a learner who has already finished the onboarding: the course opens in the app.
    'course_link' => [
        'text' => 'Курс «:course» доступен в Muhrla. Откройте его кнопкой ниже — текущий курс не изменится, пока вы сами его не выберете.',
        'button' => 'Открыть курс',
    ],

    // Minimal "Today" in the bot; the full screen is in the app.
    'today' => [
        // Plural forms (ru): 1 слово | 2–4 слова | 5+ слов (see trans_choice).
        'due' => 'Сегодня к повторению: :count слово.|Сегодня к повторению: :count слова.|Сегодня к повторению: :count слов.',
        'empty' => 'На сегодня повторений нет. Откройте Muhrla, чтобы выучить новые слова.',
        'button' => 'Открыть Muhrla',
    ],

    'menu' => [
        'text' => "Что дальше?\n/today — занятие на сегодня\n/settings — настройки\n/language — язык\n/invite — пригласить друзей и получить Pro\n/promo КОД — промокод на Pro\n/help — справка",
    ],

    'settings' => [
        'menu' => 'Что хотите изменить?',
        // `/language`: the title is both languages — this line after the uz one (OnboardingMessenger::sendLanguagePicker).
        'language_title' => 'Выберите язык',
        'saved' => 'Сохранено.',
        'timezone_reverted' => 'Часовой пояс возвращён.',
        'timezone_revert_unavailable' => 'Отменить смену часового пояса уже нельзя: прошло больше 10 минут или она уже отменена.',
        'region_saved' => 'Сохранено: :region.',
        'region_none' => 'область не указана',
        'region_undo' => '↩️ Отменить',
        'region_too_soon' => 'Область можно менять раз в 30 дней. Сменить область можно с :date.',
        'region_reverted' => 'Прежняя область возвращена.',
        'region_revert_unavailable' => 'Отменить смену области уже нельзя: прошло больше 10 минут или она уже отменена.',
        'buttons' => [
            'locale' => 'Язык',
            'region' => 'Область',
            'goal' => 'Цель',
            'level' => 'Уровень',
            'reminder' => 'Напоминания',
        ],
    ],

];
