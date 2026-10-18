<?php

declare(strict_types=1);

return [

    'start' => [
        'not_linked' => 'Этот Telegram-аккаунт не привязан к учителю в LexiFlow. Попросите админа прописать ваш Telegram ID: :id.',
        'greeting' => 'Привет, :name! Вы авторизованы как учитель LexiFlow. Команды в группе: /help',
    ],

    'help' => [
        'private' => "LexiFlow — помощник для изучения английского.\n\n".
            "Для учителей: добавьте бота в группу, админ активирует её.\n".
            "Доступные команды в группе: /start_training, /start_exam, /close_exam, /help\n".
            'Личный отчёт: /stats',
        'group' => "LexiFlow Bot в группе.\n\nКоманды учителя:\n".
            "/start_training — запустить тренировку\n".
            "/start_exam — запустить экзамен\n".
            "/close_exam — закрыть экзамен\n".
            "/stats — личный отчёт в чате с ботом\n".
            '/help — эта справка',
    ],

    'stats' => [
        'not_linked' => "Этот Telegram-аккаунт не привязан к учителю LexiFlow.\nВаш Telegram ID: :id",
    ],

];
