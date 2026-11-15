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
            "• /org — кабинет центра\n".
            '• /unlink — отключить этот Telegram от кабинета',
        // The last line of `private` for everybody who is not a linked teacher; left out without a support contact.
        'teacher_hint' => 'Вы учитель или владелец центра? Напишите нам: :contact',
        'group' => "LexiFlow в группе.\n\nКоманды учителя:\n".
            "/start_training — запустить тренировку\n".
            "/start_exam — запустить экзамен\n".
            "/close_exam — завершить экзамен\n".
            ':caravan'.
            "/stats — личный отчёт в чате с ботом\n".
            "/help — эта справка\n\n".
            'Группа учебного центра? Привяжите её из кабинета центра: Группы → «Привязать группу».',
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
        'welcome' => "👋 LexiFlow добавлен в группу «:title».\n\nЧто дальше:\n1. Сделайте бота администратором группы.\n2. Группа учебного центра? Привяжите её из кабинета центра: Группы → «Привязать группу» — у центра с подпиской она заработает сразу.\n3. Иначе группа ждёт активации администратором LexiFlow — после неё заработают команды учителя.",
        // HTML. The answer to a teacher command in a group that is not active yet.
        'pending_command' => 'Группа ещё не активирована, поэтому команды пока не работают.',
        'pending_help' => "Группа ещё не активирована, поэтому команды пока не работают.\n\nГруппа учебного центра? Привяжите её из кабинета центра: Группы → «Привязать группу».",
        'bot_not_admin' => '⚠️ Сделайте бота администратором группы. Без этого он не видит сообщения и выход участников, и LexiFlow работает не полностью. '.
            'Группа → Управление → Администраторы → Добавить администратора.',
    ],

    'stats' => [
        // HTML (`:id` in <code>): the id is still shown — a Phase-1 teacher without a cabinet passes it to the platform's administrator.
        'not_linked' => "Отчёт доступен учителям.\nСотрудник центра: подключите Telegram в кабинете — Профиль → «Подключить Telegram». Учитель без кабинета: передайте администратору ваш Telegram ID <code>:id</code>.\nЕсли вы ученик — откройте /today.",
    ],

    // Deep links of the `/org` cabinet (docs/13 §6.2): `/start link_<token>` connects a staff user's Telegram (answered in the
    // sender's Telegram language), `/start grp_<token>` / `/link <token>` in a group links it to the center (answered in the
    // center's language; every refusal — one neutral text, the reason is shown in the cabinet).
    'link' => [
        // The question before anything is connected (W2): the account is named by its masked e-mail, the cabinet by its centers.
        'account_prompt' => "Подключить этот Telegram к кабинету центра :centers?\n\nАккаунт: :name (:email).\n\nПосле подключения бот будет считать вас сотрудником центра: в этом чате он перестанет отвечать вам как ученику. Отключить можно в любой момент командой /unlink.",
        'account_prompt_no_center' => 'LexiFlow',
        'account_yes' => 'Да, подключить',
        'account_no' => 'Нет',
        'account_declined' => 'Хорошо, ничего не подключено. Если передумаете — откройте ссылку из кабинета ещё раз.',
        'account_linked' => "✅ Telegram подключён к аккаунту :name.\n\nТеперь бот узнаёт вас как учителя: команды в группах работают от вашего имени, а в этом чате бот отвечает вам как учителю — см. /help. Отключить: /unlink.",
        'account_taken' => 'Этот Telegram уже подтверждён у другого аккаунта кабинета. Сначала отключите его там (Профиль → «Отключить» или /unlink здесь), затем откройте ссылку снова.',
        'account_refused' => 'Ссылка для подключения недействительна или устарела. Откройте кабинет центра → Профиль → «Подключить Telegram» и получите новую.',
        // `/unlink`: the owner of the Telegram account disconnects it from the cabinet themselves.
        'unlink_confirm' => 'Отключить этот Telegram от кабинета (аккаунт :name)? Бот перестанет узнавать вас как учителя и снова будет отвечать вам как ученику.',
        'unlink_yes' => 'Да, отключить',
        'unlink_no' => 'Нет',
        'unlink_done' => 'Telegram отключён от аккаунта :name. Бот снова отвечает вам как ученику — отправьте /start.',
        'unlink_none' => 'Этот Telegram не подключён ни к одному кабинету. Отключать нечего.',
        'unlink_cancelled' => 'Хорошо, Telegram остаётся подключённым.',
        // `/link` outside a group, and from an anonymous administrator.
        'use_in_group' => 'Эту команду отправляют в группе, которую нужно привязать.',
        'group_anonymous' => 'Отправьте команду от своего имени, не анонимно: бот должен узнать, кто её отправил.',
        'group_linked' => '✅ Группа «:title» привязана к центру «:organization».',
        'group_next_active' => 'Группа активна: команды учителя работают. Дальше выберите курс группы в кабинете: Группы → «Курс группы».',
        'group_next_pending' => 'Группа ждёт активации администратором LexiFlow — после неё заработают команды учителя. Курс группы можно выбрать уже сейчас: кабинет → Группы → «Курс группы».',
        'group_refused' => 'Не удалось привязать группу. Откройте кабинет центра → Группы → «Привязать группу»: там видна причина, и можно получить новую ссылку.',
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
