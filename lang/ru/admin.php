<?php

declare(strict_types=1);

/*
 * Админ-панель `/admin` (docs/14 §7–§8): навигация, ресурсы, таблицы, формы, действия, виджеты, страницы.
 * Только ru (fallback_locale = ru). Термины — словарь docs/14 §8: Stage → «Модуль», Lesson → «Урок»,
 * Learner → «Ученик», Organization → «Учебный центр», Group → «Группа». Показываются только подписи:
 * имена полей, значения в БД и коды статусов остаются прежними.
 */
return [

    'nav' => [
        'content' => 'Контент',
        'learning' => 'Обучение',
        'administration' => 'Администрирование',
        'billing' => 'Оплата',
    ],

    // Общее для нескольких экранов: Да/Нет и подписи значений, которые встречаются в разных ресурсах.
    'common' => [
        'yes' => 'Да',
        'no' => 'Нет',
        'all' => 'Все',
        'plan' => [
            'free' => 'Free',
            'pro' => 'Pro',
        ],
        'period' => [
            'month' => 'Месяц',
            'year' => 'Год',
        ],
        'subscription_status' => [
            'trialing' => 'Пробный период',
            'active' => 'Активна',
            'past_due' => 'Ожидает оплаты',
            'canceled' => 'Отменена',
            'expired' => 'Истекла',
        ],
        // Откуда взялась подписка или платёж: выдача вручную / промокод / награда либо платёжный провайдер.
        'provider' => [
            'manual' => 'Вручную',
            'promo' => 'Промокод',
            'reward' => 'Награда',
            'telegram_stars' => 'Telegram Stars',
            'payme' => 'Payme',
            'click' => 'Click',
        ],
        'subscriber_type' => [
            'learner' => 'Ученик',
            'organization' => 'Учебный центр',
        ],
        'tier' => [
            'tutor' => 'Репетитор',
            'center_s' => 'Центр S',
            'center_m' => 'Центр M',
            'center_l' => 'Центр L',
        ],
    ],

    // --- Контент ---------------------------------------------------------------------------------

    'courses' => [
        'singular' => 'Курс',
        'plural' => 'Курсы',
        'nav' => 'Курсы',
        'titles' => [
            'create' => 'Новый курс',
            'edit' => 'Редактирование курса',
        ],
        'empty' => 'Курсов пока нет',
        'fields' => [
            'slug' => 'Код в ссылках (slug)',
            'slug_help' => 'Строчные латинские буквы, цифры и дефисы, до :max символов (ссылка на курс в боте — c_<код> — должна уложиться в 64 символа Telegram). Используется в ссылках и в файле импорта (поле "course"); после создания изменить нельзя.',
            'status' => 'Статус',
            'status_help' => 'Меняется кнопками выше: черновик → на проверке → опубликован → в архиве.',
            'title_locale' => 'Название (:locale)',
            'description_locale' => 'Описание (:locale)',
            'level' => 'Уровень',
            'exam_tag' => 'Цель',
            'visibility' => 'Видимость',
            'position' => 'Порядок в каталоге',
            'is_featured' => 'Рекомендуемый',
        ],
        'columns' => [
            'words_count' => 'Слов',
            'stages_count' => 'Модулей',
            'published_at' => 'Опубликован',
        ],
        'status' => [
            'draft' => 'Черновик',
            'review' => 'На проверке',
            'published' => 'Опубликован',
            'archived' => 'В архиве',
        ],
        'visibility' => [
            'public' => 'Публичный (в каталоге)',
            'unlisted' => 'По ссылке или из группы',
            'private' => 'Приватный',
        ],
        'exam_tag' => [
            'general' => 'Общий английский',
            'school' => 'Школа',
            'ielts' => 'IELTS',
            'sat' => 'SAT',
        ],
        'actions' => [
            'create' => 'Создать курс',
            'review' => 'Отправить на проверку',
            'published' => 'Опубликовать',
            'archived' => 'Архивировать',
            'draft' => 'Вернуть в черновик',
            'publish_help' => 'Для публикации нужны названия на всех языках, хотя бы один урок и переводы (uz и ru) почти для всех слов.',
        ],
        'notifications' => [
            'failed' => 'Статус не изменён',
            'moved' => 'Курс переведён в статус «:status»',
        ],
        'stages' => [
            'title' => 'Модули курса',
        ],
    ],

    'stages' => [
        'singular' => 'Модуль',
        'plural' => 'Модули',
        'nav' => 'Модули',
        'titles' => [
            'create' => 'Новый модуль',
            'edit' => 'Редактирование модуля',
        ],
        'empty' => 'Модулей пока нет',
        'fields' => [
            'course' => 'Курс',
            'number' => 'Номер',
            'title' => 'Название',
            'description' => 'Описание',
        ],
        'columns' => [
            'lessons_count' => 'Уроков',
            'created_at' => 'Создан',
        ],
        'actions' => [
            'create' => 'Создать модуль',
            'lessons' => 'Уроки',
        ],
        'lessons' => [
            'title' => 'Уроки модуля',
        ],
    ],

    'lessons' => [
        'singular' => 'Урок',
        'plural' => 'Уроки',
        'nav' => 'Уроки',
        'titles' => [
            'create' => 'Новый урок',
            'edit' => 'Редактирование урока',
        ],
        'empty' => 'Уроков пока нет',
        'fields' => [
            'stage' => 'Модуль',
            'number' => 'Номер',
            'title' => 'Название',
        ],
        'columns' => [
            'words_count' => 'Слов',
        ],
        'filters' => [
            'stage' => 'Модуль',
        ],
        'actions' => [
            'create' => 'Создать урок',
        ],
        'words' => [
            'title' => 'Слова урока',
            'empty' => 'Слов пока нет',
            'create' => 'Добавить слово',
            'translations' => 'Переводы',
        ],
    ],

    'words' => [
        'singular' => 'Слово',
        'plural' => 'Слова',
        'nav' => 'Слова',
        'titles' => [
            'edit' => 'Редактирование слова',
        ],
        'empty' => 'Слов пока нет',
        'fields' => [
            'word' => 'Слово',
            'translation' => 'Перевод (RU, основной)',
            'translation_help' => 'Русский текст. Переводы на другие языки — в таблице «Переводы» ниже.',
            'example' => 'Пример',
            'part_of_speech' => 'Часть речи',
            'transcription' => 'Транскрипция',
            'is_active' => 'Активно',
        ],
        'columns' => [
            'translation' => 'Перевод (RU)',
            'course' => 'Курс',
            'stage' => 'Модуль',
            'lesson' => 'Урок',
            'languages' => 'Языков',
        ],
        'part_of_speech' => [
            'noun' => 'Существительное',
            'verb' => 'Глагол',
            'adjective' => 'Прилагательное',
            'adverb' => 'Наречие',
            'pronoun' => 'Местоимение',
            'preposition' => 'Предлог',
            'conjunction' => 'Союз',
            'interjection' => 'Междометие',
        ],
        'actions' => [
            'translations' => 'Переводы',
        ],
        'translations' => [
            'title' => 'Переводы',
            'singular' => 'Перевод',
            'plural' => 'Переводы',
            'empty' => 'Переводов пока нет',
            'create' => 'Добавить перевод',
            'mark_reviewed' => 'Отметить проверенным',
            'fields' => [
                'locale' => 'Язык',
                'translation' => 'Перевод',
                'example_translation' => 'Перевод примера',
            ],
            'columns' => [
                'source' => 'Источник',
                'reviewed' => 'Проверен',
            ],
            'source' => [
                'import' => 'Импорт',
                'manual' => 'Вручную',
            ],
        ],
    ],

    // --- Обучение --------------------------------------------------------------------------------

    'exams' => [
        'singular' => 'Экзамен',
        'plural' => 'Экзамены',
        'nav' => 'Экзамены',
        'titles' => [
            'view' => 'Просмотр экзамена',
        ],
        'empty' => 'Экзаменов пока нет',
        'lesson_label' => 'Модуль :stage · Урок :lesson',
        'fields' => [
            'group' => 'Группа',
            'lesson' => 'Урок',
            'status' => 'Статус',
            'started_by' => 'Запустил',
            'started_at' => 'Начало',
            'ends_at' => 'Окончание',
            'closed_at' => 'Закрыт',
            'participants' => 'Участников',
            'answers' => 'Ответов',
        ],
        'status' => [
            'open' => 'Открыт',
            'closed' => 'Закрыт',
        ],
        'results' => [
            'title' => 'Результаты учеников',
            'empty' => 'Результатов пока нет',
            'singular' => 'результат',
            'plural' => 'Результаты',
            'seconds' => ':value с',
            'columns' => [
                'learner' => 'Ученик',
                'total_score' => 'Баллы',
                'correct_count' => 'Верно',
                'total_count' => 'Отвечено',
                'time' => 'Время',
                'rank' => 'Место',
            ],
        ],
        'answers' => [
            'title' => 'Ответы по словам',
            'empty' => 'Ответов пока нет',
            'singular' => 'ответ',
            'plural' => 'Ответы',
            'timeout' => 'Время вышло',
            'columns' => [
                'learner' => 'Ученик',
                'word' => 'Слово',
                'selected' => 'Выбранный перевод',
                'correct' => 'Правильный перевод',
                'is_correct' => 'Верно?',
                'score' => 'Баллы',
                'time_spent_ms' => 'Время, мс',
                'answered_at' => 'Время ответа',
            ],
        ],
    ],

    'learners' => [
        'singular' => 'Ученик',
        'plural' => 'Ученики',
        'nav' => 'Ученики',
        'titles' => [
            'view' => 'Карточка ученика',
        ],
        'empty' => 'Учеников пока нет',
        'fields' => [
            'name' => 'Имя',
            'first_name' => 'Имя',
            'last_name' => 'Фамилия',
            'username' => 'Username',
            'telegram_id' => 'Telegram ID',
            'groups' => 'Группы',
            'last_seen_at' => 'Был в сети',
            'never' => 'Не заходил',
            'created_at' => 'Зарегистрирован',
            'blocked_at' => 'Заблокирован',
            'blocked' => 'Заблокирован',
            'onboarding' => 'Первичная настройка',
            'onboarded' => 'Настройка пройдена',
            'plan' => 'Тариф',
            'scheduler' => 'Алгоритм повторений',
            'repetitions_count' => 'Начато карточек',
            'training_reviews_count' => 'Повторений в тренировках',
            'exam_answers_count' => 'Ответов в экзаменах',
            'referrals_count' => 'Приглашено',
        ],
        'onboarding' => [
            'done' => 'Пройдена',
            'in_progress' => 'В процессе (шаг: :step)',
            'step' => [
                'locale' => 'язык',
                'goal' => 'цель',
                'level' => 'уровень',
                'timezone' => 'часовой пояс',
                'reminder' => 'напоминания',
            ],
        ],
        'plan_sponsored' => '(от центра)',
        'plan_center' => 'учебный центр #:id',
        'plan_until' => 'до :date',
        'scheduler_default' => '(по умолчанию)',
        'filters' => [
            'group' => 'Группа',
            'plan' => 'Тариф',
            'plan_pro' => 'Pro (свой или от центра)',
            'onboarding' => 'Первичная настройка',
            'onboarding_done' => 'Пройдена',
            'onboarding_not_done' => 'Не пройдена',
        ],
        'actions' => [
            'block' => 'Заблокировать',
            'unblock' => 'Разблокировать',
            'scheduler' => 'Алгоритм повторений',
            'scheduler_field' => 'Алгоритм',
            'scheduler_help' => 'При переключении на FSRS память уже изученных карточек рассчитывается по их состоянию в SM-2; при возврате на SM-2 карточки не меняются.',
        ],
        'subscriptions' => [
            'title' => 'Подписки',
            'empty' => 'Подписок пока нет',
            'singular' => 'подписка',
            'plural' => 'Подписки',
            'no_end' => 'Без срока',
            'open_in_resource' => 'Открыть в разделе «Подписки»',
            'columns' => [
                'plan' => 'Тариф',
                'status' => 'Статус',
                'provider' => 'Источник',
                'starts_at' => 'Начало',
                'ends_at' => 'Действует до',
                'note' => 'Заметка',
            ],
        ],
        'offers' => [
            'title' => 'Персональные скидки',
            'empty' => 'Скидок пока нет',
            'singular' => 'скидка',
            'plural' => 'Скидки',
            'any' => 'любой',
            'columns' => [
                'kind' => 'Тип',
                'discount_pct' => 'Скидка',
                'plan' => 'Тариф',
                'period' => 'Срок',
                'state' => 'Состояние',
                'expires_at' => 'Действует до',
                'redeemed_at' => 'Использована',
                'center' => 'Учебный центр',
                'order' => 'Заказ',
            ],
            'kind' => [
                'post_org_discount' => 'После выхода из центра',
                'winback' => 'Возвращение ученика',
                'campaign' => 'Кампания',
            ],
            'state' => [
                'open' => 'Действует',
                'redeemed' => 'Использована',
                'expired' => 'Истекла',
            ],
            'actions' => [
                'annul' => 'Аннулировать',
                'annul_heading' => 'Аннулировать скидку',
                'annul_help' => 'Ученик сразу теряет скидку. Действие записывается в журнал аудита.',
            ],
            'notifications' => [
                'failed' => 'Скидка не аннулирована',
                'annulled' => 'Скидка аннулирована',
            ],
        ],
        'referrals' => [
            'title' => 'Приглашённые',
            'empty' => 'Приглашённых пока нет',
            'singular' => 'приглашение',
            'plural' => 'Приглашения',
            'columns' => [
                'referee' => 'Приглашённый ученик',
                'status' => 'Статус',
                'reject_reason' => 'Причина отказа',
                'flagged' => 'Подозрительное',
                'linked_at' => 'Пришёл по ссылке',
                'activated_at' => 'Активирован',
                'rewarded_at' => 'Награждён',
            ],
            'status' => [
                'pending' => 'Ожидает',
                'activated' => 'Активирован',
                'rewarded' => 'Награждён',
                'rejected' => 'Отклонён',
            ],
            'reject_reason' => [
                'self' => 'Своя ссылка',
                'existing_learner' => 'Уже был в боте',
                'referrer_capped' => 'Лимит наград (устарело)',
                'flagged' => 'Отклонено администратором',
            ],
            'actions' => [
                'unflag' => 'Снять пометку',
                'unflag_heading' => 'Снять пометку о всплеске приглашений',
                'unflag_help' => 'Приглашение считается настоящим: активированное награждается сразу (7 дней Pro обоим). Действие записывается в журнал аудита.',
                'reject' => 'Отклонить',
                'reject_heading' => 'Отклонить приглашение',
                'reject_help' => 'Награда за него не будет выдана никогда. Действие записывается в журнал аудита.',
            ],
            'notifications' => [
                'unflagged' => 'Пометка снята',
                'rejected' => 'Приглашение отклонено',
            ],
        ],
        'events' => [
            'title' => 'Последние события',
            'empty' => 'Событий пока нет',
            'singular' => 'событие',
            'plural' => 'События',
            'all_except' => 'Все, кроме :event',
            'columns' => [
                'occurred_at' => 'Когда',
                'name' => 'Событие',
                'props' => 'Параметры',
            ],
        ],
        'daily_stats' => [
            'title' => 'Последние 14 дней',
            'empty' => 'За 14 дней данных нет',
            'singular' => 'день',
            'plural' => 'Дни',
            'columns' => [
                'date' => 'Дата',
                'reviews_count' => 'Повторений',
                'correct_count' => 'Верно',
                'new_words_count' => 'Новых слов',
                'time' => 'Время, мин',
                'goal_met' => 'Цель выполнена',
            ],
        ],
    ],

    // --- Администрирование ------------------------------------------------------------------------

    'telegram_groups' => [
        'singular' => 'Telegram-группа',
        'plural' => 'Telegram-группы',
        'nav' => 'Telegram-группы',
        'titles' => [
            'edit' => 'Редактирование Telegram-группы',
        ],
        'empty' => 'Telegram-групп пока нет',
        'fields' => [
            'chat_id' => 'ID чата',
            'title' => 'Название',
            'status' => 'Статус',
            'course' => 'Курс',
            'course_placeholder' => 'Курс по умолчанию',
            'course_help' => 'Номера модулей и уроков в командах учителя /start_training и /start_exam берутся из этого курса. Пусто — курс по умолчанию.',
            'locale' => 'Язык бота',
            'locale_help' => 'Язык сообщений бота в этой группе. Пусто — язык по умолчанию.',
            'locale_placeholder' => 'По умолчанию',
            'meta' => 'Служебные данные (meta)',
            'teachers' => 'Учителя',
            'learners' => 'Учеников',
            'created_at' => 'Добавлена',
        ],
        'status' => [
            'pending' => 'Ожидает активации',
            'active' => 'Активна',
            'disabled' => 'Отключена',
        ],
        'actions' => [
            'activate' => 'Активировать',
            'deactivate' => 'Отключить',
        ],
    ],

    'users' => [
        'singular' => 'Сотрудник',
        'plural' => 'Сотрудники',
        'nav' => 'Сотрудники',
        'titles' => [
            'create' => 'Новый сотрудник',
            'edit' => 'Редактирование сотрудника',
        ],
        'empty' => 'Сотрудников пока нет',
        'fields' => [
            'name' => 'Имя',
            'email' => 'Email',
            'password' => 'Пароль',
            'telegram_user_id' => 'Telegram ID',
            'telegram_user_id_help' => 'Числовой Telegram ID. Обязателен для роли «Учитель»: по нему бот узнаёт учителя.',
            'roles' => 'Роли',
            'is_active' => 'Активен',
            'last_login_at' => 'Последний вход',
            'created_at' => 'Создан',
        ],
        'filters' => [
            'is_active' => 'Активность',
            'role' => 'Роль',
        ],
        'roles' => [
            'admin' => 'Администратор',
            'teacher' => 'Учитель',
        ],
        'actions' => [
            'create' => 'Создать сотрудника',
            'delete_selected' => 'Удалить выбранных сотрудников',
        ],
    ],

    'pages' => [
        'import_lesson' => [
            'title' => 'Импорт урока',
            'nav' => 'Импорт урока',
            'download_sample' => 'Скачать пример',
            'file' => 'Файл урока (JSON)',
            'file_help' => 'До 2 МБ. Формат смотрите в примере («Скачать пример»). Необязательное поле "course" — slug существующего курса (по умолчанию — основной курс); у слов можно задать "translations": {"ru", "uz"}.',
            'submit' => 'Импортировать',
            'no_file' => 'Файл не выбран.',
            'imported' => 'Импорт выполнен: новых — :added, обновлено — :updated, пропущено — :skipped.',
            'failed' => 'Импорт не выполнен — ничего не записано.',
            'report' => [
                'heading' => 'Отчёт о последнем импорте',
                'added' => 'Добавлено',
                'updated' => 'Обновлено',
                'skipped' => 'Пропущено',
                'translations' => 'Переводы',
                'translations_added' => 'новых: :count',
                'translations_updated' => 'обновлено: :count',
                'aborted' => 'Прервано',
                'errors' => 'Ошибки',
            ],
        ],
    ],

];
