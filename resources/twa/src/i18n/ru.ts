/**
 * Russian dictionary — the source of truth for message keys.
 *
 * `uz.ts` is typed against this file (see `Translations`), so a key that is
 * missing or extra in any other locale fails `vue-tsc`.
 *
 * Values are either a plain string or a set of plural forms (CLDR categories
 * from Intl.PluralRules). `{name}` placeholders are substituted by `t()`/`tn()`;
 * `tn()` always provides `{count}`.
 */

export type PluralForms = Partial<Record<Intl.LDMLPluralRule, string>> & { other: string }

export type Message = string | PluralForms

export const ru = {
  // ---- common ----
  'common.close': 'Закрыть',
  'common.loading': 'Загрузка…',
  'common.retry': 'Повторить',
  'common.error_title': 'Ошибка',

  // ---- landing ----
  'landing.outside_telegram':
    'Эта страница работает только внутри Telegram Web App. Попросите учителя прислать ссылку-тренировку в группу.',

  // ---- today ----
  'today.title': 'Сегодня',
  'today.plan.free': 'Free',
  'today.plan.pro': 'Pro',
  'today.goal.title': 'Цель дня',
  'today.goal.progress': '{done} из {target}',
  'today.goal.met': 'Цель выполнена',
  'today.goal.left': {
    one: 'Осталась {count} карточка',
    few: 'Осталось {count} карточки',
    many: 'Осталось {count} карточек',
    other: 'Осталось {count} карточки',
  },
  'today.streak.title': 'Серия',
  'today.streak.days': {
    one: '{count} день',
    few: '{count} дня',
    many: '{count} дней',
    other: '{count} дня',
  },
  'today.streak.none': 'Выполните цель дня, чтобы начать серию',
  'today.streak.at_risk': 'Серия под угрозой: выполните цель до полуночи',
  'today.streak.safe': 'На сегодня серия продлена',
  'today.streak.best': 'Рекорд: {best}',
  'today.review.title': 'Повторение',
  'today.review.due': {
    one: '{count} слово к повторению',
    few: '{count} слова к повторению',
    many: '{count} слов к повторению',
    other: '{count} слова к повторению',
  },
  'today.review.none': 'Повторять пока нечего',
  'today.review.start': 'Повторить',
  'today.review.continue': 'Продолжить',
  'today.new.remaining': 'Новых слов на сегодня осталось: {count}',
  'today.new.exhausted': 'Лимит новых слов на сегодня исчерпан',
  'today.course.title': 'Курс',
  'today.course.next': 'Следующий урок',
  'today.course.completed': 'Все слова курса уже показаны',
  'today.course.none': 'Выберите курс, чтобы учить новые слова',
  'today.onboarding_hint': 'Завершите настройку: отправьте боту /start',

  // ---- flashcard ----
  'card.kind.new': 'Новое слово',
  'card.kind.learning': 'Закрепление',
  'card.kind.due': 'Повторение',
  'card.speak': 'Произнести',
  'card.reveal': 'Показать перевод',
  'card.translation_fallback': 'Перевода на язык занятия пока нет',
  'card.grade_group': 'Оценка',
  'card.grade.hard': 'Трудно',
  'card.grade.medium': 'Средне',
  'card.grade.easy': 'Легко',

  // ---- training ----
  'training.lesson_header': 'Stage {stage} · Lesson {lesson}',
  'training.daily_header': 'Повторение',
  'training.closed_title': '🔒 Тренировка завершена',
  'training.restart': 'Начать заново',

  // ---- exam ----
  'exam.closed_title': '🔒 Экзамен завершён учителем',

  // ---- exam result ----
  'result.title': 'Итоги экзамена',
  'result.your_score': 'Твой результат',
  'result.rank': 'Место {rank} из {total}',
  'result.pending': 'Результаты ещё считаются…',
  'result.top': 'Топ участников',

  // ---- finished (training) ----
  'finished.title': 'Отлично!',
  'finished.reviewed': {
    one: 'Вы повторили {count} слово.',
    few: 'Вы повторили {count} слова.',
    many: 'Вы повторили {count} слов.',
    other: 'Вы повторили {count} слова.',
  },
  'finished.reason.lesson_complete': 'Все слова урока пройдены.',
  'finished.reason.nothing_due': 'На сегодня повторять нечего.',
  'finished.reason.new_quota_exhausted': 'Лимит новых слов на сегодня исчерпан. Новые слова откроются завтра.',
  'finished.reason.session_cap': 'Это была длинная сессия — сделайте перерыв.',
  'finished.home': 'На главную',
  'finished.goal': 'Цель дня: {done} из {target}',
  'finished.goal_met': 'Цель дня выполнена!',
  'finished.next_label': 'Следующее повторение',
  'finished.next_today': 'Сегодня',
  'finished.next_in_days': {
    one: 'Через {count} день',
    few: 'Через {count} дня',
    many: 'Через {count} дней',
    other: 'Через {count} дня',
  },

  // ---- promo code ----
  'promo.toggle': 'Есть промокод?',
  'promo.placeholder': 'Промокод',
  'promo.submit': 'Активировать',
  'promo.success_until': 'Pro активен до {date}.',
  'promo.success_forever': 'Pro активен без ограничения по сроку.',

  // ---- paywall (Free quota, mode coming_soon; docs/12 §5.4) ----
  'paywall.title': 'Pro скоро',
  'paywall.lead_quota': 'Лимит новых слов на сегодня исчерпан — повторения доступны без ограничений.',
  'paywall.soon': 'Pro скоро откроется.',
  'paywall.free': 'Сейчас у вас тариф Free: {count} новых слов в день, повторения — без лимита.',
  'paywall.free_generic': 'Сейчас у вас тариф Free: новые слова ограничены дневным лимитом, повторения — без лимита.',
  'paywall.contact': 'Учитесь в центре? Попросите администратора центра подключить LexiFlow Pro:',
  'paywall.dismiss': 'Понятно',
  'paywall.open': 'Узнать о Pro',
  'paywall.review': 'Повторить слова',
  'paywall.lead_decks': 'Лимит колод тарифа Free: 1 колода до 50 слов. В Pro — до 20 колод по 2000 слов.',

  // ---- purchase (paywall mode purchase, subscription screen; docs/13 §5) ----
  'paywall.title_purchase': 'LexiFlow Pro',
  'paywall.lead_purchase': 'Новые слова без дневного лимита, колоды и подробная статистика.',
  'billing.title': 'Подписка',
  'billing.open': 'Подписка Pro',
  'billing.period.month': 'Месяц',
  'billing.period.year': 'Год',
  'billing.pay_with': 'Оплатить: {provider} · {price}',
  'billing.provider.telegram_stars': '⭐ Stars',
  'billing.provider.payme': 'Payme',
  'billing.provider.click': 'Click',
  'billing.provider.manual': 'выдан вручную',
  'billing.provider.promo': 'промокод',
  'billing.provider.reward': 'награда',
  'billing.offer': 'Скидка {pct}% на первый платёж — до {date}.',
  'billing.starting': 'Создаём счёт…',
  'billing.awaiting': 'Ждём подтверждение оплаты…',
  'billing.timeout': 'Подтверждение ещё не пришло. Если вы уже оплатили, Pro включится в течение нескольких минут.',
  'billing.check_again': 'Проверить ещё раз',
  'billing.payment_failed': 'Платёж не прошёл или счёт закрыт. Попробуйте ещё раз.',
  'billing.paid_until': 'Спасибо! Pro активен до {date}.',
  'billing.paid_forever': 'Спасибо! Pro активен.',
  'billing.auto_renew_running': 'Автопродление Stars включено — оплачивать ничего не нужно. Управлять подпиской можно в «Настройки → Подписка Pro».',
  'billing.plan.free': 'Тариф Free',
  'billing.plan.pro': 'LexiFlow Pro',
  'billing.sponsored': 'Pro подключён вашим центром.',
  'billing.until': 'Действует до {date}.',
  'billing.forever': 'Без ограничения по сроку.',
  'billing.provider_line': 'Оплата: {provider}.',
  'billing.auto_on': 'Автопродление включено: следующий период Telegram спишет сам.',
  'billing.auto_off': 'Автопродление отключено — Pro действует до конца оплаченного периода.',
  'billing.cancel': 'Отключить автопродление',
  'billing.resume': 'Включить автопродление',
  'billing.buy': 'Купить Pro',
  'billing.renew': 'Продлить Pro',
  'billing.unavailable': 'Оплата скоро откроется.',
  'billing.sum': 'сум',

  // ---- course catalog ----
  'catalog.title': 'Курсы',
  'catalog.open': 'Каталог курсов',
  'catalog.empty': 'Курсов пока нет. Загляните позже.',
  'catalog.choose': 'Выбрать',
  'catalog.active': 'Активный',
  'catalog.words': {
    one: '{count} слово',
    few: '{count} слова',
    many: '{count} слов',
    other: '{count} слова',
  },
  'catalog.progress': 'Изучено {done} из {total}',
  'catalog.goal.general': 'Общий английский',
  'catalog.goal.school': 'Школа',
  'catalog.goal.ielts': 'IELTS',
  'catalog.goal.sat': 'SAT',
  'course.back': 'К курсам',
  'course.module': 'Модуль {number}',
  'course.lesson': 'Урок {number}',
  'course.learn': 'Учить',
  'course.no_lessons': 'В курсе пока нет уроков.',

  // ---- learner decks ----
  'decks.title': 'Мои колоды',
  'decks.open': 'Мои колоды',
  'decks.intro': 'Создайте свою колоду и добавьте слова списком или из CSV. Колоду видите только вы.',
  'decks.empty': 'У вас пока нет колод.',
  'decks.limit': 'Колод: {used} из {max}. В одной колоде — до {words} слов.',
  'decks.new.placeholder': 'Название колоды',
  'decks.new.submit': 'Создать колоду',
  'decks.edit.back': 'К колодам',
  'decks.edit.rename': 'Название',
  'decks.edit.save': 'Сохранить',
  'decks.edit.words': 'Слова',
  'decks.edit.no_words': 'В колоде пока нет слов.',
  'decks.edit.remove_word': 'Убрать',
  'decks.edit.import': 'Добавить слова',
  'decks.edit.format.list': 'Списком',
  'decks.edit.format.csv': 'CSV',
  'decks.edit.hint.list': 'По слову в строке: слово — перевод (или слово;перевод).',
  'decks.edit.hint.csv': 'Колонки: word,translation_ru,translation_uz,example.',
  'decks.edit.text.placeholder': 'apple — яблоко',
  'decks.edit.add': 'Добавить',
  'decks.edit.delete': 'Удалить колоду',
  'decks.edit.delete.confirm': 'Удалить колоду «{title}»? Слова уйдут в архив, ваш прогресс сохранится.',
  'decks.edit.delete.yes': 'Да, удалить',
  'decks.edit.delete.no': 'Отмена',
  'decks.report': 'Добавлено: {added} · вернулось: {reactivated} · уже были: {duplicates} · пропущено строк: {rejected}',
  'decks.rejected': 'Строка {line}: {reason}',
  'decks.reason.empty_translation': 'нет перевода',
  'decks.reason.junk': 'не похоже на слово',
  'decks.reason.too_long': 'слишком длинно',
  'decks.reason.duplicate': 'повтор',
  'decks.reason.over_limit': 'сверх лимита строк',
  'decks.error.too_large': 'Текст слишком большой: не больше 256 КБ.',

  // ---- settings ----
  'settings.title': 'Настройки',
  'settings.language': 'Язык интерфейса',
  'settings.timezone': 'Часовой пояс',
  'settings.timezone.locked': 'Часовой пояс можно менять не чаще раза в сутки. Следующая смена — {when}.',
  'settings.reminder': 'Напоминания',
  'settings.reminder.off': 'Без напоминаний',
  'settings.reminder.hint': 'Время — по вашему часовому поясу.',
  'settings.daily_goal': 'Цель на день',
  'settings.daily_goal.option': {
    one: '{count} карточка',
    few: '{count} карточки',
    many: '{count} карточек',
    other: '{count} карточки',
  },
  'settings.course': 'Активный курс',
  'settings.course.none': 'Курс не выбран',
  'settings.saved': 'Сохранено',

  // ---- settings: my centers (docs/13 §6.2) ----
  'orgs.title': 'Мои центры',
  'orgs.seated': 'Pro от центра активен',
  'orgs.waitlisted': 'Свободных мест нет — вы в очереди',
  'orgs.via_group': 'Вы в центре через группу. Чтобы выйти, покиньте группу центра в Telegram.',
  'orgs.leave': 'Покинуть центр',
  'orgs.leave.confirm': 'Покинуть центр «{name}»? Pro от центра отключится, личная подписка (если есть) останется.',
  'orgs.leave.yes': 'Да, покинуть',
  'orgs.leave.no': 'Отмена',

  // ---- errors (by kind / context) ----
  'error.closed.training': 'Эта тренировка уже закрыта: она завершена или время сессии истекло.',
  'error.closed.exam': 'Экзамен был завершён учителем.',
  'error.closed.catalog': 'Курс больше недоступен.',
  'error.closed.today': 'Экран «Сегодня» больше недоступен.',
  'error.closed.settings': 'Настройки больше недоступны.',
  'error.closed.decks': 'Колода больше недоступна.',
  'error.forbidden.training': 'У вас нет доступа к этой тренировке.',
  'error.forbidden.exam': 'У вас нет доступа к этому экзамену.',
  'error.forbidden.catalog': 'У вас нет доступа к каталогу курсов.',
  'error.forbidden.today': 'У вас нет доступа к экрану «Сегодня».',
  'error.forbidden.settings': 'У вас нет доступа к настройкам.',
  'error.forbidden.decks': 'У вас нет доступа к колодам.',
  'error.network': 'Нет связи с сервером. Попробуйте ещё раз.',
  'error.rate_limited': 'Слишком много запросов. Подождите немного и повторите.',
  'error.server': 'Сервер временно недоступен. Попробуйте позже.',
  'error.generic': 'Что-то пошло не так.',
  'error.unexpected': 'Непредвиденная ошибка.',
  'error.outside_telegram': 'Откройте приложение через Telegram.',

  // ---- errors (by API error.code) ----
  'error.code.timezone_change_too_soon': 'Часовой пояс можно менять не чаще раза в сутки.',
  'error.code.price_not_configured': 'Этот вариант оплаты пока недоступен.',
  'error.code.provider_disabled': 'Этот способ оплаты сейчас недоступен. Выберите другой.',
  'error.code.provider_not_supported': 'Этот способ оплаты вам недоступен.',
  'error.code.checkout_conflict': 'У вас включено автопродление Stars. Отключите его в «Подписке», прежде чем платить другим способом.',
  'error.code.too_many_pending': 'У вас уже есть неоплаченные счета. Оплатите один из них или подождите — они закроются сами.',
  'error.code.offer_not_applicable': 'Скидка к этой покупке не применяется.',
  'error.code.invalid_period': 'Такого варианта оплаты нет.',
  'error.code.invalid_tier': 'Такого варианта оплаты нет.',
  'error.code.not_cancelable': 'Автопродление сейчас нечего отключать.',
  'error.code.not_resumable': 'Автопродление сейчас нельзя включить: оно уже включено или оплаченный период закончился.',
  'error.code.provider_capability': 'Это действие недоступно для вашего способа оплаты.',
  'error.code.provider_not_registered': 'Это действие недоступно для вашего способа оплаты.',
  'error.code.provider_unavailable': 'Платёжный сервис не отвечает. Попробуйте через несколько минут.',
  'error.code.order_not_found': 'Счёт не найден.',
  'error.code.membership_not_found': 'Вы уже не состоите в этом центре.',
  'error.code.membership_via_group': 'Вы в центре через группу: покинуть его можно, выйдя из группы центра в Telegram.',
  'error.code.session_closed': 'Сессия уже закрыта.',
  'error.code.session_not_found': 'Сессия не найдена. Попросите учителя запустить её заново.',
  'error.code.learner_not_found': 'Ваш профиль не найден. Откройте приложение заново из Telegram.',
  'error.code.learner_blocked': 'Доступ к аккаунту ограничен. Обратитесь в поддержку.',
  'error.code.not_a_member': 'Вы не состоите в этой группе. Вступите в группу и откройте ссылку снова.',
  'error.code.group_inactive': 'Группа не активирована. Обратитесь к учителю.',
  'error.code.invalid_init_data': 'Не удалось подтвердить Telegram-сессию. Откройте приложение заново.',
  'error.code.invalid_token': 'Сессия устарела. Откройте приложение заново.',
  'error.code.missing_token': 'Вы не авторизованы. Откройте приложение заново из Telegram.',
  'error.code.lesson_not_found': 'Урок не найден.',
  'error.code.lesson_mismatch': 'Этот режим не подходит к выбранному уроку.',
  'error.code.invalid_mode': 'Неизвестный режим тренировки.',
  'error.code.question_not_found': 'Вопрос не найден.',
  'error.code.question_not_served': 'Вопрос ещё не показан. Обновите экран.',
  'error.code.already_answered': 'Вы уже ответили на этот вопрос.',
  'error.code.word_mismatch': 'Ответ не соответствует вопросу. Обновите экран.',
  'error.code.out_of_scope': 'Это слово не относится к этой тренировке.',
  'error.code.card_not_started': 'Эта карточка ещё не показывалась. Обновите экран.',
  'error.code.card_not_due': 'Эта карточка уже повторена, её время ещё не пришло.',
  'error.code.exam_still_open': 'Экзамен ещё идёт, результаты появятся после его завершения.',
  'error.code.tts_unavailable': 'Озвучка сейчас недоступна.',
  'error.code.promo_not_found': 'Промокод не найден. Проверьте, нет ли опечатки.',
  'error.code.promo_expired': 'Срок действия промокода истёк.',
  'error.code.promo_exhausted': 'Этим промокодом уже воспользовалось максимальное число людей.',
  'error.code.promo_already_redeemed': 'Вы уже активировали этот промокод.',
  'error.code.promo_not_applicable': 'У вас уже есть Pro без ограничения по сроку — промокод не нужен.',
  'error.code.plan_unknown': 'Этот промокод сейчас недоступен. Напишите в поддержку.',
  'error.code.course_not_found': 'Курс не найден или недоступен.',
  'error.code.deck_not_found': 'Колода не найдена.',
  'error.code.deck_word_not_found': 'Такого слова в колоде нет.',
  'error.code.deck_limit_reached': 'Достигнут лимит колод вашего тарифа.',
  'error.code.deck_words_limit_reached': 'Достигнут лимит слов в колоде вашего тарифа. Ничего не добавлено.',
  'error.code.deck_import_empty': 'В тексте не нашлось ни одной подходящей строки.',
  'error.code.deck_title_invalid': 'Название колоды должно содержать буквы.',
} satisfies Record<string, Message>

export type Dictionary = typeof ru
export type MessageKey = keyof Dictionary

/** Keys whose value is a set of plural forms (use with `tn`). */
export type PluralKey = { [K in MessageKey]: Dictionary[K] extends string ? never : K }[MessageKey]

/** Keys whose value is a plain string (use with `t`). */
export type StringKey = Exclude<MessageKey, PluralKey>

/** Shape every locale dictionary must have: same keys, same string/plural kind. */
export type Translations = {
  [K in MessageKey]: Dictionary[K] extends string ? string : PluralForms
}
