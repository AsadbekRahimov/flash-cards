<?php

declare(strict_types=1);

return [

    'meta' => [
        'title' => 'LexiFlow — английский слово за словом в Telegram',
        // `:free` = Free's daily allowance as a phrase (`limits.free_new_words`), from config: LandingPricing::limits().
        'description' => 'LexiFlow — бот и мини-приложение в Telegram для изучения английских слов: карточки, озвучка и умные повторения. Бесплатно: :free.',
    ],

    'nav' => [
        'how' => 'Как это работает',
        'pricing' => 'Тарифы',
        'teachers' => 'Для центров',
        'label' => 'Основная навигация',
    ],

    'hero' => [
        'title' => 'Английские слова, которые остаются в памяти',
        'subtitle' => 'Учите английский прямо в Telegram: карточки с озвучкой и примерами, а бот напоминает повторить слова ровно тогда, когда вы начинаете их забывать.',
        'cta_bot' => 'Открыть бота',
        'cta_unavailable' => 'Бот скоро откроется',
        'note' => 'Бесплатно: :free, повторения — без ограничений.',
        // Decorative phone mock-up next to the headline: a flash card of the Mini App (docs/14 §6.2, §8 grades).
        'mockup' => [
            'progress' => '7 / 20',
            'chip' => 'Новое слово',
            'word' => 'breakfast',
            'ipa' => '/ˈbrekfəst/',
            'translation' => 'завтрак',
            'example' => 'I have breakfast at eight.',
            'question' => 'Насколько хорошо вы помните слово?',
            'grade' => [
                'forgot' => 'Не помню',
                'remembered' => 'Помню',
                'easy' => 'Легко',
            ],
        ],
    ],

    'how' => [
        'title' => 'Как это работает',
        'steps' => [
            ['title' => 'Откройте бота', 'text' => 'Нажмите «Старт» в Telegram и выберите язык интерфейса. Ничего устанавливать не нужно.'],
            ['title' => 'Учите по карточкам', 'text' => 'Мини-приложение открывается прямо в Telegram: слово, перевод, пример и озвучка.'],
            ['title' => 'Повторяйте вовремя', 'text' => 'Интервальные повторения подбирают момент, когда слово пора освежить, а бот присылает напоминание.'],
        ],
    ],

    'features' => [
        'title' => 'Что внутри',
        'items' => [
            ['title' => 'Бот и мини-приложение', 'text' => 'Бот — для старта и напоминаний, мини-приложение — для самих занятий. Всё в одном мессенджере.'],
            ['title' => 'Умные повторения', 'text' => 'Каждое слово возвращается через растущие промежутки времени — так оно переходит в долговременную память.'],
            ['title' => 'Озвучка и примеры', 'text' => 'Слушайте произношение и смотрите, как слово употребляется в предложении.'],
            ['title' => 'Узбекский и русский', 'text' => 'Интерфейс бота и мини-приложения доступен на русском и узбекском языках.'],
        ],
    ],

    // The limits this page quotes (`hero.mockup.progress` is a drawing, not one of them). Their numbers are read from
    // config by LandingPricing::limits() — Free's `billing.plans.free.limits.new_words_per_day` (null = no daily
    // limit) and `decks.limits.{free,pro}` — so none is typed in this file. A tick of a plan card that is just
    // `:new_words` or `:decks` is replaced by the line below; a plan with nothing to say there loses the tick.
    'limits' => [
        // Free's daily allowance; also the `:free` of `meta.description` and `hero.note` (so it reads mid-sentence).
        // Plural forms (ru): 1 слово | 2–4 слова | 5+ слов (see trans_choice).
        'free_new_words' => ':count новое слово в день|:count новых слова в день|:count новых слов в день',
        'free_new_words_unlimited' => 'новые слова без дневного лимита',
        // Pro's first tick: only while Free has a daily limit that Pro lacks.
        'pro_new_words' => 'Без дневного лимита новых слов',
        // One deck / many (a rule of the plan, not of the language: `{1}` is exactly one, 21 is many).
        'decks' => '{1} Своя колода до :deck_words слов|[2,*] Свои колоды: до :decks колод по :deck_words слов',
    ],

    'pricing' => [
        'title' => 'Тарифы',
        // `subtitle`, `soon` and `pro.note` are shown while Pro is not for sale (billing.paywall.mode = coming_soon);
        // `subtitle_open`, `pro.price*` and `pro.note_open` once it is (LandingPricing).
        'subtitle' => 'Начните бесплатно. Платные возможности появятся позже — прогресс при этом сохранится.',
        'subtitle_open' => 'Начните бесплатно. Pro — когда захочется больше.',
        'soon' => 'Скоро',
        'free' => [
            'name' => 'Free',
            'tagline' => 'Для ежедневной практики',
            'features' => [
                'Публичные курсы',
                ':new_words',
                'Повторения без ограничений',
                ':decks',
                'Бот и мини-приложение',
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tagline' => 'Для тех, кто хочет больше',
            // Only what the code really gates behind Pro (config/billing.php features, config/decks.php limits).
            'features' => [
                ':new_words',
                'Упражнения «Написание слова» и «Пропуск в примере»',
                ':decks',
                'Заморозки серии: пропущенный день не обрывает серию',
                'Пробные тесты IELTS и SAT',
            ],
            'price' => 'от :price в месяц',
            'price_stars' => 'или :price через Telegram Stars',
            'note' => 'Состав и цены Pro пока уточняются.',
            'note_open' => 'Оформить Pro можно в боте командой /pro или в мини-приложении.',
        ],
        'centers' => [
            'name' => 'Для учебных центров',
            'tagline' => 'Фиксированная оплата раз в месяц',
            'features' => [
                'Центр платит фиксированную сумму раз в месяц',
                'Студенты центра получают Pro в пределах мест тарифа, пока состоят в центре',
                'Группы в Telegram, тренировки и экзамены для учителей',
            ],
            'contact' => 'Подключить центр:',
        ],
    ],

    'footer' => [
        'tagline' => 'LexiFlow — английский в Telegram.',
        'contact' => 'Поддержка:',
        'language' => 'Язык',
        'legal_label' => 'Документы',
    ],

];
