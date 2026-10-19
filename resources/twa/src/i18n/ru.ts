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

  // ---- errors (by kind / context) ----
  'error.closed.training': 'Эта тренировка уже закрыта: она завершена или время сессии истекло.',
  'error.closed.exam': 'Экзамен был завершён учителем.',
  'error.closed.catalog': 'Курс больше недоступен.',
  'error.closed.today': 'Экран «Сегодня» больше недоступен.',
  'error.forbidden.training': 'У вас нет доступа к этой тренировке.',
  'error.forbidden.exam': 'У вас нет доступа к этому экзамену.',
  'error.forbidden.catalog': 'У вас нет доступа к каталогу курсов.',
  'error.forbidden.today': 'У вас нет доступа к экрану «Сегодня».',
  'error.network': 'Нет связи с сервером. Попробуйте ещё раз.',
  'error.rate_limited': 'Слишком много запросов. Подождите немного и повторите.',
  'error.server': 'Сервер временно недоступен. Попробуйте позже.',
  'error.generic': 'Что-то пошло не так.',
  'error.unexpected': 'Непредвиденная ошибка.',
  'error.outside_telegram': 'Откройте приложение через Telegram.',

  // ---- errors (by API error.code) ----
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
