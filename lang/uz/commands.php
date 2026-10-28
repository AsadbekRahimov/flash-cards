<?php

declare(strict_types=1);

/**
 * Descriptions of the bot commands in the Telegram "/" menu (`php artisan telegram:setup-bot`, docs/04 B.3).
 * Telegram allows 1–256 characters; `private` is the list of private chats, `group` holds the commands of group chats:
 * `/help` for every member, the teacher's commands for the group administrators only (BotMenuBuilder::SCOPES).
 */
return [

    'private' => [
        'today' => "Bugungi mashg'ulot",
        'settings' => 'Sozlamalar',
        'invite' => "Do'stni taklif qilish",
        'pro' => 'Pro obuna',
        'promo' => 'Promokodni faollashtirish',
        'help' => 'Yordam',
    ],

    'group' => [
        'start_training' => 'Treningni boshlash',
        'start_exam' => 'Imtihonni boshlash',
        'close_exam' => 'Imtihonni yakunlash',
        'help' => 'Yordam',
    ],

];
