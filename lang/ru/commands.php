<?php

declare(strict_types=1);

/**
 * Descriptions of the bot commands in the Telegram "/" menu (`php artisan telegram:setup-bot`, docs/04 B.3).
 * Telegram allows 1–256 characters; `private` is the list of private chats, `group` the one of group chats.
 */
return [

    'private' => [
        'today' => 'Занятие на сегодня',
        'settings' => 'Настройки',
        'invite' => 'Пригласить друга',
        'pro' => 'Подписка Pro',
        'promo' => 'Активировать промокод',
        'help' => 'Справка',
    ],

    'group' => [
        'start_training' => 'Запустить тренировку',
        'start_exam' => 'Запустить экзамен',
        'close_exam' => 'Завершить экзамен',
        'help' => 'Справка',
    ],

];
