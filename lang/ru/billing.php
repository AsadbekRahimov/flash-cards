<?php

declare(strict_types=1);

return [

    /* DM after leaving a center (docs/13 §6.5): the discounted buy buttons of /pro follow the text. */
    'offer_dm' => [
        'text' => "Вы покинули центр «:organization». Личный Muhrla Pro со скидкой :pct% на первый месяц — до :date.\nВыберите способ оплаты:",
        'text_generic' => "Ваше место в центре закончилось. Личный Muhrla Pro со скидкой :pct% на первый месяц — до :date.\nВыберите способ оплаты:",
    ],

    'pro' => [
        'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку.',
        'rate_limited' => 'Слишком много попыток. Подождите минуту и повторите.',
        'status_free' => 'Сейчас у вас тариф Free: :count новых слов в день, повторения без лимита.',
        'status_free_generic' => 'Сейчас у вас тариф Free: новые слова ограничены дневным лимитом, повторения без лимита.',
        'status_pro_until' => 'У вас Muhrla Pro до :date.',
        'status_pro_forever' => 'У вас Muhrla Pro без ограничения по сроку.',
        'renewal_auto' => 'Автопродление включено: следующий период Telegram спишет сам.',
        'renewal_canceled' => 'Автопродление отключено — Pro действует до конца оплаченного периода.',
        'soon' => 'Muhrla Pro скоро откроется. Есть промокод? Отправьте /promo КОД.',
        'contact' => 'Учитесь в центре? Попросите администратора центра подключить Muhrla Pro: :contact',
        'choose' => 'Выберите срок и способ оплаты:',
        'price_line' => ':period — :prices',
        'offer' => 'Скидка :pct% на первый платёж действует до :date.',
        'buy_button' => ':provider · :period · :price',
        'cancel_button' => 'Отключить автопродление',
        'resume_button' => 'Включить автопродление',
        'checkout_ready' => "Счёт создан: :period, :price.\nОплатите по кнопке ниже — Pro включится сам, как только придёт подтверждение.",
        'pay_button' => 'Оплатить',
        'canceled' => 'Автопродление отключено. Pro действует до :date.',
        'resumed' => 'Автопродление снова включено.',
        'errors' => [
            'price_not_configured' => 'Этот вариант оплаты пока недоступен.',
            'provider_disabled' => 'Этот способ оплаты сейчас недоступен. Выберите другой.',
            'provider_not_supported' => 'Этот способ оплаты вам недоступен.',
            'checkout_conflict' => 'У вас включено автопродление Telegram Stars. Отключите его в /pro, прежде чем оформлять новую оплату.',
            'already_perpetual' => 'У вас уже есть Muhrla Pro без ограничения по сроку — оплачивать ничего не нужно.',
            'subscribe_too_early' => 'Pro уже оплачен на длительный срок. Подписку Stars можно оформить ближе к окончанию — в последние дни; пока выберите разовую оплату на 3 месяца или на год.',
            'too_many_pending' => 'У вас уже есть неоплаченные счета. Оплатите один из них или подождите — они закроются сами.',
            'offer_not_applicable' => 'Скидка к этой покупке не применяется.',
            'offer_busy' => 'Предыдущая оплата со скидкой ещё обрабатывается — попробуйте через минуту.',
            'invalid_period' => 'Такого варианта оплаты нет.',
            'invalid_tier' => 'Такого варианта оплаты нет.',
            'not_cancelable' => 'Автопродление сейчас нечего отключать.',
            'not_resumable' => 'Автопродление сейчас нельзя включить: оно уже включено или оплаченный период закончился.',
            'provider_capability' => 'Это действие недоступно для вашего способа оплаты.',
            'provider_not_registered' => 'Это действие недоступно для вашего способа оплаты.',
            'provider_unavailable' => 'Платёжный сервис не отвечает. Попробуйте через несколько минут.',
            'generic' => 'Не получилось выполнить действие. Попробуйте позже или напишите в поддержку: /paysupport.',
        ],
    ],

    // The welcome trial (docs/13 §7.1 "2C.9", docs/36 §4б), DM once after the onboarding (TrialWelcomeMessenger): `:days` is
    // "14 дней" (billing.trial.days), `:reward` is "7 дней" (referrals.milestone_days), `:friends` is "3 друзей" (lang/ru/referrals.php),
    // `:benefits` is the lines of `pro_promises` below, each as "• …" on a line of its own (ProPromises::top()).
    'trial' => [
        'welcome' => "🎁 Вам открыт Pro на :days — бесплатно, до :date.\n\nЧто даёт Pro:\n:benefits\n\nПригласите :friends — получите ещё :reward Pro: /invite",
        'welcome_plain' => "🎁 Вам открыт Pro на :days — бесплатно, до :date.\n\nЧто даёт Pro:\n:benefits",
    ],

    // What Pro gives, short lines of the bot (ProPromises::top(), docs/36 §4б): the same list as the Mini App's "what Pro gives". The
    // lines about practice and the game are there only while those are open to the reader.
    'pro_promises' => [
        'new_words' => 'Новые слова без дневного лимита',
        'practice' => 'Практика и баттлы без дневного лимита',
        'practice_solo' => 'Практика без дневного лимита',
        'decks' => 'Свои колоды слов',
        'freezes' => 'Заморозка серии: пропуск дня не обрывает серию',
        'game' => 'Караван без дневного лимита',
        'exercises' => 'Упражнения «Написание слова» и «Пропуск в примере»',
        'mock_exams' => 'Пробные тесты IELTS и SAT',
    ],

    'paysupport' => [
        'with_contact' => "Платёжная поддержка Muhrla.\n\n".
            "Вопросы по оплате и отмена подписки: :contact\n".
            "Возврат возможен только через поддержку: каждое обращение рассматривается индивидуально. Опишите проблему и приложите чек или номер платежа. Условия — в оферте: :offer\n".
            'Автопродление Stars можно отключить командой /pro.',
        'without_contact' => "Платёжная поддержка Muhrla.\n\n".
            "Контакт поддержки пока не указан. Возврат возможен только через поддержку, каждое обращение рассматривается индивидуально. Условия — в оферте: :offer\n".
            'Автопродление Stars можно отключить командой /pro.',
    ],

    // Telegram Stars (docs/13 §4.1): the invoice, pre-checkout refusals and the payment / refund replies.
    'stars' => [
        'invoice' => [
            // Telegram limits: title ≤ 32, label ≤ 32, description ≤ 255 characters.
            'title' => 'Muhrla Pro — :period',
            'label' => 'Muhrla Pro, :period',
            'description_month' => 'Подписка Muhrla Pro: новые слова без лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Продлевается автоматически каждые 30 дней; отключить можно командой /pro.',
            'description_year' => 'Muhrla Pro на 1 год (365 дней): новые слова без лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Разовая оплата без автопродления.',
            // The same two with the practice and battles of Pro, chosen by TelegramStarsProvider only while practice is open to everybody
            // (ProPromises::publicPractice()); the limit of Telegram is 255 characters, so the tail of the month text is shorter.
            'description_month_practice' => 'Подписка Muhrla Pro: новые слова, практика и баттлы без дневного лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Автопродление каждые 30 дней; отключить: /pro.',
            'description_year_practice' => 'Muhrla Pro на 1 год (365 дней): новые слова, практика и баттлы без дневного лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Разовая оплата без автопродления.',
            // The same with the practice alone, while the battles are switched off (PRACTICE_BATTLE=false): no battles are sold that do not exist.
            'description_month_practice_solo' => 'Подписка Muhrla Pro: новые слова и практика без дневного лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Автопродление каждые 30 дней; отключить: /pro.',
            'description_year_practice_solo' => 'Muhrla Pro на 1 год (365 дней): новые слова и практика без дневного лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Разовая оплата без автопродления.',
            // The one-off quarter (3 months) in the same three variants.
            'description_quarter' => 'Muhrla Pro на 3 месяца (90 дней): новые слова без лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Разовая оплата без автопродления.',
            'description_quarter_practice' => 'Muhrla Pro на 3 месяца (90 дней): новые слова, практика и баттлы без дневного лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Разовая оплата без автопродления.',
            'description_quarter_practice_solo' => 'Muhrla Pro на 3 месяца (90 дней): новые слова и практика без дневного лимита, упражнения «Написание слова» и «Пропуск в примере», свои колоды до 20 по 2000 слов, заморозки серии, пробные тесты IELTS и SAT. Разовая оплата без автопродления.',
        ],
        'precheckout' => [
            'order_not_found' => 'Счёт не найден. Откройте /pro и создайте новый.',
            'foreign' => 'Этот счёт выставлен другому пользователю. Откройте /pro и создайте свой.',
            'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку: /paysupport.',
            'order_closed' => 'Этот счёт уже оплачен или закрыт. Откройте /pro.',
            'order_expired' => 'Срок счёта истёк. Откройте /pro и создайте новый.',
            'amount_mismatch' => 'Сумма счёта не совпадает с заказом. Откройте /pro и создайте новый.',
            'provider_disabled' => 'Оплата Telegram Stars сейчас недоступна. Выберите другой способ в /pro.',
            'already_subscribed' => 'У вас уже включено автопродление Telegram Stars — второй счёт не нужен. Откройте /pro: там его можно отключить.',
            'unavailable' => 'Сервис временно недоступен. Попробуйте через минуту.',
        ],
        'paid' => 'Оплата получена! Muhrla Pro активен до :date.',
        'renewed' => 'Подписка продлена: Muhrla Pro активен до :date.',
        'paid_forever' => 'Оплата получена! У вас Muhrla Pro без ограничения по сроку.',
        'paid_unresolved' => 'Оплата получена, но автоматически зачесть её не удалось. Мы разберёмся и включим Pro или вернём Stars. Чтобы ускорить — напишите в поддержку: /paysupport.',
        'refunded' => 'Возврат :amount выполнен. Если этим платежом был оплачен текущий период Pro, он завершён.',
    ],

    'period' => [
        'month' => '1 месяц',
        'quarter' => '3 месяца',
        'year' => '1 год',
    ],

    'provider' => [
        'telegram_stars' => '⭐ Stars',
        'payme' => 'Payme',
        'click' => 'Click',
    ],

    'currency' => [
        'XTR' => ':amount ⭐',
        'UZS' => ':amount сум',
    ],

    // Renewal reminders (docs/13 §4.4): DM to a learner / an organization's owner, e-mail to owners.
    'renewal' => [
        'dm' => '{1} Muhrla Pro заканчивается завтра (:date).|[2,*] Muhrla Pro заканчивается через :count дн. (:date).',
        'org_dm' => '{1} Подписка центра «:organization» заканчивается завтра (:date).|[2,*] Подписка центра «:organization» заканчивается через :count дн. (:date).',
        'hint' => 'Продлите заранее, чтобы новые слова не ограничивались дневным лимитом.',
        'hint_canceled' => 'Автопродление отключено, поэтому Pro не продлится само. Продлите заранее, чтобы не потерять безлимит.',
        // The welcome trial (docs/13 §7.1 "2C.9"): not a renewal — the first purchase.
        'trial_dm' => '{1} Пробный Pro заканчивается завтра (:date).|[2,*] Пробный Pro заканчивается через :count дн. (:date).',
        'hint_trial' => 'Чтобы не потерять Pro: оформите подписку (/pro) или пригласите друзей (/invite).',
        'org_hint' => 'После окончания у участников центра снова будет тариф Free.',
        'button' => 'Продлить Pro',
        'mail' => [
            'subject' => '{1} Подписка центра «:organization» заканчивается завтра|[2,*] Подписка центра «:organization» заканчивается через :count дн.',
            'greeting' => 'Здравствуйте!',
            'body' => '{1} Подписка Muhrla Pro центра «:organization» заканчивается завтра (:date).|[2,*] Подписка Muhrla Pro центра «:organization» заканчивается через :count дн. (:date).',
            'keep' => 'Чтобы ученики центра не потеряли Pro, продлите подписку заранее. После окончания у них снова будет тариф Free.',
            'action' => 'Открыть кабинет центра',
            'contact' => 'Вопросы по оплате: :contact',
        ],
    ],

    // Payme (docs/13 §4.2): the fiscal receipt line of CheckPerformTransaction and the return page `/billing/return`.
    'payme' => [
        'receipt_item' => 'Muhrla Pro — :period',
    ],

    'return' => [
        'title' => 'Статус оплаты',
        'open_bot' => 'Вернуться в Muhrla',
        'note' => 'Эту страницу можно закрыть: статус оплаты виден в боте и в приложении.',
        'support' => 'Вопросы по оплате:',
        'paid' => [
            'heading' => 'Оплата получена',
            'text' => 'Muhrla Pro активирован. Вернитесь в бот или откройте приложение — доступ уже работает.',
        ],
        'pending' => [
            'heading' => 'Ждём подтверждение оплаты',
            'text' => 'Платёж обрабатывается. Обычно это занимает меньше минуты; Pro включится автоматически, как только платёжная система подтвердит оплату.',
        ],
        'expired' => [
            'heading' => 'Срок счёта истёк',
            'text' => 'Этот счёт больше не действует. Откройте /pro в боте и создайте новый — деньги по истёкшему счёту не списываются.',
        ],
        'canceled' => [
            'heading' => 'Счёт отменён',
            'text' => 'Этот счёт отменён. Если нужно, создайте новый командой /pro в боте.',
        ],
        'unknown' => [
            'heading' => 'Статус оплаты неизвестен',
            'text' => 'Мы не нашли такой счёт. Проверьте статус в боте командой /pro или напишите в поддержку.',
        ],
    ],

    // Admin dashboard revenue widget: cash flow by event date, cohort net kept apart.
    'report' => [
        'heading' => 'Выручка за 30 дней',
        'net_cash' => 'Чистое движение денег :currency',
        'description' => 'Платежи: :charges, брутто :gross · возвраты (по дате возврата): :refund_count, :refunds · когортный нетто (платежи периода за вычетом их возвратов): :cohort',
    ],

];
