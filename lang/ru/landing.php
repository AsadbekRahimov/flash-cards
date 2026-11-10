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
            'chip' => 'Повторение',
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
        'lead' => 'Слово забывается не сразу, а постепенно. LexiFlow возвращает его перед тем, как оно сотрётся, — и с каждым разом пауза длиннее.',
        // The path of one word through its reviews: approximate moments in words, not the exact intervals of the engine.
        'timeline' => [
            'word' => 'breakfast',
            'points' => [
                ['when' => 'Сегодня', 'what' => 'Новое слово'],
                ['when' => 'Завтра', 'what' => 'Первое повторение'],
                ['when' => 'Через неделю', 'what' => 'Второе'],
                ['when' => 'Через две недели', 'what' => 'Третье'],
                ['when' => 'Через месяц', 'what' => 'Слово с вами надолго'],
            ],
            'note' => 'Не вспомнили — слово вернётся раньше. Вспомнили легко — позже.',
        ],
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
        // Pro's practice tick: only while practice is open to everybody and Pro has something to lift there - a Free daily limit, or
        // practice that is Pro's as a whole (ProPromises, the rule of the Mini App too); the second sentence while the battles are switched
        // off (PRACTICE_BATTLE=false). The same two sentences as `benefits.practice*` of the Mini App.
        'pro_practice' => 'Практика и баттлы без дневного лимита',
        'pro_practice_solo' => 'Практика без дневного лимита',
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
                ':practice',
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
            'tagline' => 'Фиксированная оплата — за месяц или за год',
            'features' => [
                'Центр платит фиксированную сумму, а не за каждого ученика',
                'Ученики центра получают Pro в пределах мест тарифа, пока состоят в центре',
                'Группы в Telegram, тренировки и экзамены для учителей',
            ],
            'contact' => 'Подключить центр:',
        ],
    ],

    // The section for teachers and learning centers (#teachers). The tiers and their seats come from config (LandingPricing::orgTiers()).
    'teachers' => [
        'title' => 'Учителям и учебным центрам',
        'lead' => 'Тренировки и экзамены проходят прямо в Telegram-группе класса, а активность учеников видна в кабинете центра.',
        'steps' => [
            ['title' => 'Оставьте заявку', 'text' => 'Мы создадим кабинет центра и подскажем, как добавить бота в группу.'],
            ['title' => 'Добавьте бота в группу класса', 'text' => 'Ученики заходят на занятие одной кнопкой — ничего устанавливать не нужно.'],
            ['title' => 'Запускайте тренировки и экзамены', 'text' => 'Одной командой в группе. Итоги экзамена бот публикует сам.'],
        ],
        'tiers_title' => 'Тарифы для центров',
        // Plural forms (ru): 1 ученик | 2–4 ученика | 5+ учеников (see trans_choice).
        'seats' => 'до :count ученика|до :count учеников|до :count учеников',
        'seats_unlimited' => 'без ограничения мест',
        'seat_note' => 'Место — это один ученик, который получает Pro за счёт центра.',
        'year_discount' => 'При оплате за год — скидка :percent%.',
        'cta' => 'Подключить центр',
        'login' => 'Войти в кабинет',
    ],

    'footer' => [
        'tagline' => 'LexiFlow — английский в Telegram.',
        'contact' => 'Поддержка:',
        'language' => 'Язык',
        'legal_label' => 'Документы',
    ],

];
