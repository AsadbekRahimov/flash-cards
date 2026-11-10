<?php

declare(strict_types=1);

return [

    'start' => [
        'greeting' => "Здравствуйте, :name! Ваш аккаунт учителя подключён.\n\nЧто дальше:\n1. Добавьте бота в группу класса и сделайте его администратором.\n2. В группе отправьте /start_training — тренировка, /start_exam — экзамен.\n3. /stats — отчёт за 7 дней, /org — кабинет центра.",
    ],

    'help' => [
        'private' => "LexiFlow — английские слова в Telegram: карточки с озвучкой и умные повторения.\n\n".
            "Как заниматься:\n".
            "• Кнопка «LexiFlow» слева от поля ввода или /today — занятие на сегодня\n".
            "• /settings — язык, цель, уровень, часовой пояс, напоминания\n".
            "• /invite — пригласить друга и вместе получить Pro\n".
            "• /pro — подписка Pro, /promo КОД — промокод\n".
            "• /paysupport — вопросы по оплате\n".
            '• /delete_account — удалить аккаунт',
        // Instead of `private` for a linked teacher (their DM has no learner commands). `:caravan` as in `group`.
        'private_teacher' => "LexiFlow — команды учителя.\n\n".
            "• Добавьте бота в группу и сделайте его администратором. Команды заработают после активации группы: если она ещё не активна, напишите в поддержку.\n".
            "• В группе: /start_training — тренировка, /start_exam — экзамен, /close_exam — завершить экзамен.\n".
            ':caravan'.
            "• /stats — ваш отчёт за 7 дней\n".
            '• /org — кабинет центра',
        // The last line of `private` for everybody who is not a linked teacher; left out without a support contact.
        'teacher_hint' => 'Вы учитель или владелец центра? Напишите нам: :contact',
        'group' => "LexiFlow в группе.\n\nКоманды учителя:\n".
            "/start_training — запустить тренировку\n".
            "/start_exam — запустить экзамен\n".
            "/close_exam — завершить экзамен\n".
            ':caravan'.
            "/stats — личный отчёт в чате с ботом\n".
            "/help — эта справка\n\n".
            'Номер группы для кабинета центра: <code>:chat_id</code>',
        // The line of `:caravan` in `private` (the part «Для учителей»): shown only with GAME_ACCESS=all, like `group_caravan`.
        'private_caravan' => "• Караван класса: /start_caravan — запустить, /cancel_caravan — отменить.\n",
        // The lines of `:caravan` in `group`: shown only with GAME_ACCESS=all (G2.9b, G2.13).
        'group_caravan' => "/start_caravan — запустить караван класса\n".
            "/cancel_caravan — отменить караван класса\n",
    ],

    'support' => [
        'line' => 'Поддержка: :contact',
    ],

    'teacher' => [
        'learner_command' => 'Эта команда — для учеников. Ваши команды учителя: /help',
    ],

    'group' => [
        'untitled' => 'Группа без названия',
        // HTML. Sent once when the bot is added to a group that waits for activation (`:title` is escaped by the caller).
        'welcome' => "👋 LexiFlow добавлен в группу «:title».\n\nЧто дальше:\n1. Сделайте бота администратором группы.\n2. Группа ждёт активации — после неё заработают команды учителя.\n\nНомер группы для кабинета центра: <code>:chat_id</code>",
        // HTML. The answer to a teacher command in a group that is not active yet.
        'pending_command' => 'Группа ещё не активирована, поэтому команды пока не работают.',
        'pending_help' => "Группа ещё не активирована, поэтому команды пока не работают.\n\nНомер группы для кабинета центра: <code>:chat_id</code>",
        'bot_not_admin' => '⚠️ Сделайте бота администратором группы. Без этого он не видит сообщения и выход участников, и LexiFlow работает не полностью. '.
            'Группа → Управление → Администраторы → Добавить администратора.',
    ],

    'stats' => [
        // HTML (`:id` in <code>).
        'not_linked' => "Отчёт доступен учителям.\nВаш Telegram ID: <code>:id</code> — укажите его в кабинете центра: Профиль → «Мой Telegram ID».\nЕсли вы ученик — откройте /today.",
    ],

    'account' => [
        'restored' => 'С возвращением! Аккаунт восстановлен — слова и прогресс на месте.',
        'restored_pro' => 'Автопродление Pro было выключено при удалении. Включить его снова можно в «Подписке».',
    ],

    'delete_account' => [
        'settings_button' => 'Удалить аккаунт',
        'confirm' => "Удалить аккаунт LexiFlow?\n\nВаши данные будут окончательно удалены через :days дн. Пока этот срок не прошёл, вы можете вернуться — достаточно отправить боту /start. Платёжные записи хранятся по закону, подписка Pro прекращается.",
        'yes_button' => 'Да, удалить',
        'no_button' => 'Отмена',
        'canceled' => 'Хорошо, аккаунт не удалён.',
        'done' => 'Аккаунт удалён. Данные будут окончательно стёрты через :days дн. Передумали — отправьте /start, и аккаунт вернётся.',
        'none' => 'Аккаунта нет или он уже удалён. Чтобы вернуться, отправьте /start.',
        'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку: /paysupport.',
        'use_private' => 'Аккаунт удаляется только в личном чате. Откройте чат с ботом и отправьте /delete_account.',
    ],

    'promo' => [
        'usage' => 'Отправьте промокод так: /promo КОД',
        'use_private' => 'Промокод лучше не показывать в группе. Откройте личный чат с ботом и отправьте /promo КОД.',
        'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку: /paysupport.',
        'rate_limited' => 'Слишком много попыток. Подождите минуту и повторите.',
        'success_until' => 'Готово! LexiFlow Pro активен до :date.',
        'success_forever' => 'Готово! LexiFlow Pro активен без ограничения по сроку.',
        'errors' => [
            'promo_not_found' => 'Промокод не найден. Проверьте, нет ли опечатки.',
            'promo_expired' => 'Срок действия промокода истёк.',
            'promo_exhausted' => 'Этим промокодом уже воспользовалось максимальное число людей.',
            'promo_already_redeemed' => 'Вы уже активировали этот промокод.',
            'promo_not_applicable' => 'У вас уже есть Pro без ограничения по сроку — промокод не нужен.',
            'plan_unknown' => 'Этот промокод сейчас недоступен. Напишите в поддержку.',
        ],
    ],

];
