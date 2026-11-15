<?php

declare(strict_types=1);

return [

    // HTML. `:seconds` — time for one question.
    'started' => "🏁 <b>Экзамен начался!</b> Модуль :stage · Урок :lesson · :minutes мин.\nНажмите кнопку — LexiFlow откроется прямо в Telegram. На каждый вопрос — :seconds секунд; итоги появятся здесь, когда время выйдет.",
    // `/start_exam` without arguments: the lesson, then the duration.
    'picker' => [
        'prompt' => 'Выберите урок для экзамена:',
        'duration_prompt' => 'Модуль :stage · Урок :lesson. Сколько минут будет идти экзамен?',
        'minutes_button' => ':minutes мин',
    ],
    'open_button' => '🏁 Открыть экзамен',

    'leaderboard' => [
        'title' => "🏁 <b>Итоги экзамена</b>\nУрок :lesson",
        'nobody' => 'Никто не ответил 😅',
    ],

];
