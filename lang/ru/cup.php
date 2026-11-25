<?php

declare(strict_types=1);

// The friends' cup in a Telegram group (docs/33 §6, K6): the messages of the bot in the chat are in the language of the cup
// (`cups.locale`), the toasts under the buttons in the language of the one who tapped. Plain text, no HTML: the names of the
// participants (`display_name`) go in as they are. `:brand` is config('app.brand').
return [
    'photo' => [
        'label' => 'Турнир в группе',
        'title' => 'Кубок друзей',
        'description' => ':days · :tasks слов в день · в зачёт :best',
        'final_title' => 'Итоги кубка',
        'final_description' => 'Кубок друзей · уровень :level',
    ],

    'days' => ':count день|:count дня|:count дней',
    'best_days' => ':count лучший день|:count лучших дня|:count лучших дней',

    'proposal' => [
        'text' => "🏆 Кубок друзей :brand\n«:group» · уровень :level\n\n:days подряд — раунд из :tasks слов в день, за верный ответ 2 очка. В зачёт идут :best. Участвовать может каждый в этом чате.\n\nОпубликовать кубок может администратор чата.",
    ],

    'card' => [
        'title' => '🏆 Кубок друзей :brand · «:group» · :level',
        'scheduled' => 'Старт :start в 00:00 · финиш :end, 00:00',
        'running' => 'День :day из :days · финиш :end, 00:00',
        'finished' => 'Кубок завершён',
        'canceled' => 'Кубок отменён',
        'expired' => 'Предложение истекло',
        'no_players' => 'Кубок отменён: не набралось двух участников',
        'join_hint' => 'Нажмите «Участвовать». Для старта нужно хотя бы два участника.',
        'empty' => 'Пока никто не набрал очков.',
        'row' => ':place. :name — :points',
        'left' => '(вышел(а))',
        'more' => '…и ещё :count',
        'participants' => 'Участников: :count',
        'summary_at' => 'сводка в :time',
        'bot_not_admin' => '⚠️ Бот не администратор чата — участие проверить нельзя. Сделайте бота администратором.',
    ],

    'summary' => [
        'title' => '📊 Кубок друзей · итоги дня :day из :days',
        'footer' => 'Финиш :end, 00:00. Раунд нового дня откроется в полночь.',
        'footer_last' => 'Это последний день: раунд можно сыграть до полуночи. Финиш :end, 00:00 — после него итоги кубка.',
    ],

    'final' => [
        'title' => '🏆 Кубок друзей завершён!',
        'group' => '«:group» · уровень :level',
        'winner' => 'Поздравляем: :names!',
        'empty' => 'Никто не набрал очков.',
    ],

    'command' => [
        'exists' => 'В чате уже есть кубок «:group». Таблица и участие — по кнопке ниже.',
        'draft' => 'В чате уже есть черновик кубка «:group». Администратор чата может настроить и опубликовать его по кнопке ниже.',
        'material' => 'Сейчас кубок недоступен: для уровня не хватает слов.',
    ],

    'welcome' => [
        'line' => '🏆 Хотите соревноваться с друзьями? Создайте кубок друзей: неделя коротких раундов прямо в этом чате.',
    ],

    'toast' => [
        'not_admin' => 'Это может сделать только администратор чата.',
        'stale' => 'Эта кнопка больше не действует.',
        'exists' => 'В этом чате уже есть кубок — смотрите его карточку.',
        'published' => 'Кубок опубликован!',
        'canceled' => 'Кубок отменён.',
        'bot_not_admin' => 'Сделайте бота администратором чата. Особые права не нужны.',
        'unverifiable' => 'Не удалось проверить права, попробуйте ещё раз.',
        'material' => 'Для этого уровня пока не хватает слов.',
        'cooldown' => 'Предложить кубок можно раз в 10 минут.',
        'paused' => 'Кубки в этом чате сейчас недоступны.',
        'failed' => 'Не получилось. Попробуйте позже.',
    ],
];
