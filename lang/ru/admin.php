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

    // Общее для нескольких экранов.
    'common' => [
        'yes' => 'Да',
        'no' => 'Нет',
        'all' => 'Все',
        'fields' => [
            'created_at' => 'Создано',
            'number' => 'Номер',
            'title' => 'Название',
            'description' => 'Описание',
            'status' => 'Статус',
            'note' => 'Заметка',
            'reason' => 'Причина',
            'active' => 'Активно',
            'course' => 'Курс',
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
