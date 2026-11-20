import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import FinishedView from '../FinishedView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { ApiClientError } from '@/api/client'
import { ICONS } from '@/components/ui/icons'
import { useTodayStore } from '@/stores/today'
import { useAchievementsStore } from '@/stores/achievements'
import { useTrainingStore } from '@/stores/training'
import type { MonthlyRatingMeResponse, SessionSummary } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: { achievements: { list: vi.fn() }, league: { monthMe: vi.fn() }, me: { get: vi.fn() }, promo: { redeem: vi.fn() }, today: { get: vi.fn() }, training: { open: vi.fn(), finish: vi.fn() } },
}))

const telegram = vi.hoisted(() => ({ close: vi.fn(), notificationHaptic: vi.fn() }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => telegram }))

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'landing', component: { template: '<div />' } },
    { path: '/today', name: 'today', component: { template: '<div />' } },
    { path: '/league/month', name: 'league-month', component: { template: '<div />' } },
    { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
    { path: '/achievements', name: 'achievements', component: { template: '<div />' } },
    { path: '/courses', name: 'courses', component: { template: '<div />' } },
    { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
  ],
})

function render(reviewed: number, nextInDays: number, reason: string | null = null) {
  return mount(FinishedView, { props: { reviewed, nextInDays, reason }, global: { plugins: [router] } })
}

const summary = (overrides: Partial<SessionSummary> = {}): SessionSummary => ({
  reviewed: 5,
  correct: 4,
  new_words: 1,
  goal: { target: 20, done: 12, met: false },
  streak: { current: 3, best: 7, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  ...overrides,
})

/** The device's "reduce motion" switch; the finish screen without it counts up and throws confetti. */
function reduceMotion(on: boolean): void {
  vi.stubGlobal('matchMedia', (query: string) => ({ matches: on && query.includes('reduce'), media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
}

/** `/me` of a learner on the given plan: the finish screen asks it once when the new words ran out. */
function mockPlan(plan: 'free' | 'pro', extra: Record<string, unknown> = {}): void {
  vi.mocked(api.me.get).mockResolvedValue({
    learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
    groups: [],
    stats: { words_learned: 0, words_due_today: 0 },
    entitlement: { plan, source: plan === 'pro' ? 'subscription' : 'free', until: null, new_words_per_day: plan === 'pro' ? null : 20, ...extra },
    paywall: { mode: 'coming_soon', contact: '@lexiflow_help' },
    settings: settingsFixture(),
  } as never)
}

describe('FinishedView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    // Most of the checks below read the numbers and the title at once: no count-up, no confetti (their own checks switch it on).
    reduceMotion(true)
    vi.mocked(api.achievements.list).mockRejectedValue(new Error('offline'))
  })
  afterEach(() => {
    setLocale('ru')
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it.each([
    [1, 'Вы повторили 1 слово.'],
    [2, 'Вы повторили 2 слова.'],
    [5, 'Вы повторили 5 слов.'],
    [21, 'Вы повторили 21 слово.'],
  ])('uses the correct Russian form for %i reviewed words', (reviewed, text) => {
    expect(render(reviewed, 0).get('[data-testid=reviewed]').text()).toBe(text)
  })

  it.each([
    [0, 'Сегодня'],
    [1, 'Через 1 день'],
    [3, 'Через 3 дня'],
    [5, 'Через 5 дней'],
    [21, 'Через 21 день'],
  ])('labels the next review in %i days in Russian', (days, label) => {
    expect(render(3, days).get('[data-testid=next-label]').text()).toBe(label)
  })

  it('renders Uzbek without noun inflection', () => {
    setLocale('uz')
    const w = render(5, 3)
    expect(w.get('[data-testid=reviewed]').text()).toBe("Siz 5 ta so'zni takrorladingiz.")
    expect(w.get('[data-testid=next-label]').text()).toBe('3 kundan keyin')
    expect(w.get('[data-testid=close-btn]').text()).toBe('Telegramga qaytish')
    expect(render(3, 0).get('[data-testid=next-label]').text()).toBe('Bugun')
  })

  it.each([
    ['lesson_complete', 'Все слова урока пройдены.'],
    ['nothing_due', 'На сегодня повторять нечего.'],
    ['new_quota_exhausted', 'Лимит новых слов на сегодня исчерпан. Новые слова откроются завтра.'],
    ['session_cap', 'Это было длинное занятие — сделайте перерыв.'],
  ])('explains done_reason %s', (reason, text) => {
    expect(render(3, 1, reason).get('[data-testid=reason]').text()).toBe(text)
  })

  it('shows no reason line for an unknown or missing reason', () => {
    expect(render(3, 1, 'brand_new_reason').find('[data-testid=reason]').exists()).toBe(false)
    expect(render(3, 1).find('[data-testid=reason]').exists()).toBe(false)
  })

  it('hides the zero-words line when a reason explains the empty session', () => {
    const w = render(0, 0, 'nothing_due')
    expect(w.find('[data-testid=reviewed]').exists()).toBe(false)
    expect(w.find('[data-testid=reason]').exists()).toBe(true)
    expect(render(0, 0).find('[data-testid=reviewed]').exists()).toBe(true)
  })

  describe('an empty session (nothing was reviewed)', () => {
    it('says "That is all for today" with its reason, not "Great!", and has no "next review" card', () => {
      const w = render(0, 0, 'nothing_due')

      expect(w.get('h1').text()).toBe('На сегодня всё')
      expect(w.get('[data-testid=reason]').text()).toBe('На сегодня повторять нечего.')
      expect(w.find('[data-testid=next]').exists()).toBe(false)
      expect(w.find('[data-testid=next-label]').exists()).toBe(false)
    })

    it('takes the numbers of the server summary when there are some, and a session with cards keeps "Great!" and the next review', () => {
      useTrainingStore().summary = summary({ reviewed: 0, correct: 0, new_words: 0 })
      expect(render(7, 0).get('h1').text()).toBe('На сегодня всё')

      useTrainingStore().summary = summary({ reviewed: 5 })
      const w = render(0, 2)
      expect(w.get('h1').text()).toBe('Отлично!')
      expect(w.get('[data-testid=next-label]').text()).toBe('Через 2 дня')
    })

    it('does not open the paywall by itself when the quota is used up, but keeps the way to it in a calm card', async () => {
      mockPlan('free')
      const w = render(0, 0, 'new_quota_exhausted')
      await flushPromises()

      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
      expect(w.get('[data-testid=paywall-open]').text()).toBe('Что даёт Pro')
    })

    it('offers to choose a course only for an empty nothing_due session of a personal training', () => {
      const w = render(0, 0, 'nothing_due')
      const btn = w.get('[data-testid=courses-btn]')
      expect(btn.text()).toBe('Выбрать курс')
      expect(btn.attributes('href')).toBe('/courses')
      expect(btn.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
      expect(w.find('[data-testid=home-btn]').exists()).toBe(true)

      for (const reason of ['new_quota_exhausted', 'lesson_complete', 'session_cap', null]) {
        expect(render(0, 0, reason).find('[data-testid=courses-btn]').exists()).toBe(false)
      }
      expect(render(3, 1, 'nothing_due').find('[data-testid=courses-btn]').exists()).toBe(false)
      useTrainingStore().summary = summary({ reviewed: 5 })
      expect(render(0, 1, 'nothing_due').find('[data-testid=courses-btn]').exists()).toBe(false)
    })

    it('never offers it after a group session', () => {
      useTrainingStore().mode = 'group_lesson'

      expect(render(0, 0, 'nothing_due').find('[data-testid=courses-btn]').exists()).toBe(false)
    })

    it('says the course button in Uzbek', () => {
      setLocale('uz')

      expect(render(0, 0, 'nothing_due').get('[data-testid=courses-btn]').text()).toBe('Kursni tanlash')
    })

    it('says it in Uzbek', () => {
      setLocale('uz')

      expect(render(0, 0, 'nothing_due').get('h1').text()).toBe('Bugun uchun hammasi tayyor')
    })
  })

  it('renders the reason in Uzbek', () => {
    setLocale('uz')
    expect(render(3, 1, 'new_quota_exhausted').get('[data-testid=reason]').text())
      .toBe("Bugungi yangi so'zlar limiti tugadi. Yangi so'zlar ertaga ochiladi.")
  })

  describe('look and buttons', () => {
    it('celebrates with a success icon and a title, and is an immersive screen of its own', () => {
      const w = render(5, 1)

      expect(w.get('h1').text()).toBe('Отлично!')
      const icon = w.get('[data-testid=finished-icon]')
      expect(icon.classes()).toEqual(expect.arrayContaining(['bg-success-soft', 'text-success']))
      expect(icon.get('path').attributes('d')).toBe(ICONS['check-circle'].solid[0]?.d)
      expect(w.find('[data-testid=tab-bar]').exists()).toBe(false)
    })

    it('buzzes the success haptic when it opens', () => {
      render(5, 1)

      expect(telegram.notificationHaptic).toHaveBeenCalledWith('success')
    })

    it('leads home with the one primary button and closes the app with a quiet one', async () => {
      const w = render(5, 1)
      const home = w.get('[data-testid=home-btn]')

      expect(home.text()).toBe('К экрану «Сегодня»')
      expect(home.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      expect(w.findAll('.btn-primary')).toHaveLength(1)

      const closeButton = w.get('[data-testid=close-btn]')
      expect(closeButton.text()).toBe('Выйти в Telegram')
      expect(closeButton.classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain']))
      await closeButton.trigger('click')
      expect(telegram.close).toHaveBeenCalledTimes(1)
    })

    it('has no promo code field: it lives in the subscription screen and the paywall', () => {
      for (const reason of [null, 'lesson_complete', 'nothing_due', 'session_cap']) {
        const w = render(3, 1, reason)
        expect(w.find('[data-testid=promo]').exists()).toBe(false)
        expect(w.find('[data-testid=promo-toggle]').exists()).toBe(false)
      }
    })

    it('shows a failed "Repeat words" as an alert with an icon above the buttons', () => {
      useTrainingStore().openError = 'Слишком много запросов. Подождите немного и повторите.'
      const w = render(3, 1)

      const alert = w.get('[data-testid=open-error]')
      expect(alert.text()).toBe('Слишком много запросов. Подождите немного и повторите.')
      expect(alert.attributes('role')).toBe('alert')
      expect(alert.classes()).toContain('text-danger-ink')
      expect(alert.find('svg').exists()).toBe(true)
    })

    it('shows no error when there is none', () => {
      expect(render(3, 1).find('[data-testid=open-error]').exists()).toBe(false)
    })

    it('forgets the failed "Repeat words" when the learner leaves, so Today does not show it', () => {
      const training = useTrainingStore()
      training.openError = 'Слишком много запросов. Подождите немного и повторите.'
      const w = render(3, 1)
      expect(w.find('[data-testid=open-error]').exists()).toBe(true)

      w.unmount()

      expect(training.openError).toBe('')
    })
  })

  describe('the entry to the practice (docs/16 §11.1)', () => {
    const entry = (lessonId: number | null) => ({ course: 'english-a1', lesson_id: lessonId })

    it('offers "Поиграть: закрепить слова" after a lesson, as a tinted secondary action that leads to the hub of that lesson', () => {
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      const w = render(5, 1)

      const practice = w.get('[data-testid=practice-btn]')
      expect(practice.text()).toBe('Поиграть: закрепить слова')
      expect(practice.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
      expect(practice.classes()).not.toContain('btn-primary')
      expect(practice.attributes('href')).toBe('/practice/english-a1?lesson=41&from=finished')
      expect(practice.get('path').attributes('d')).toBe(ICONS['puzzle-piece'].outline[0]?.d)
    })

    it('says the same after a daily review, with the hub of the course and no lesson in the address', () => {
      useTrainingStore().summary = summary({ practice_entry: entry(null) })
      const w = render(5, 1)

      expect(w.get('[data-testid=practice-btn]').text()).toBe('Поиграть: закрепить слова')
      expect(w.get('[data-testid=practice-btn]').attributes('href')).toBe('/practice/english-a1?from=finished')
    })

    it('opens the hub with the course, the lesson and the mark of the finish screen', async () => {
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      const w = render(5, 1)

      await w.get('[data-testid=practice-btn]').trigger('click')
      await flushPromises()

      const route = router.currentRoute.value
      expect(route.name).toBe('practice-hub')
      expect(route.params.slug).toBe('english-a1')
      expect(route.query).toEqual({ lesson: '41', from: 'finished' })
    })

    it('does not compete with the main action: "На главную" is the only primary button without another round, and comes after the play button', () => {
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      const w = render(5, 1)

      expect(w.findAll('.btn-primary')).toHaveLength(1)
      const order = w.findAll('[data-testid=home-btn], [data-testid=practice-btn], [data-testid=close-btn]').map((b) => b.attributes('data-testid'))
      expect(order).toEqual(['practice-btn', 'home-btn', 'close-btn'])
      expect(w.get('[data-testid=home-btn]').classes()).toContain('btn-primary')
    })

    it('is not offered after an empty round: there is nothing to practise', () => {
      useTrainingStore().summary = summary({ reviewed: 0, correct: 0, new_words: 0, practice_entry: entry(41) })

      expect(render(0, 0, 'nothing_due').find('[data-testid=practice-btn]').exists()).toBe(false)
    })

    it('keeps the buttons of the screen in order when the new words ran out: the card about it is above them, the learner can still practise today', async () => {
      mockPlan('free')
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      const w = render(3, 1, 'new_quota_exhausted')
      await flushPromises()

      const order = w.findAll('[data-testid=quota-card], [data-testid=home-btn], [data-testid=practice-btn], [data-testid=close-btn]').map((b) => b.attributes('data-testid'))
      expect(order).toEqual(['quota-card', 'practice-btn', 'home-btn', 'close-btn'])
      expect(w.findAll('.btn-primary')).toHaveLength(1)
    })

    it.each([
      ['the server has none (a daily review without a course, no access)', { practice_entry: null }],
      ['an older server says nothing of it', {}],
    ])('has no button when %s', (_name, overrides) => {
      useTrainingStore().summary = summary(overrides)

      expect(render(5, 1).find('[data-testid=practice-btn]').exists()).toBe(false)
    })

    it('has no button without a summary (a group session, a failed finish)', () => {
      expect(render(5, 1).find('[data-testid=practice-btn]').exists()).toBe(false)
    })

    it('speaks Uzbek', () => {
      setLocale('uz')
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      expect(render(5, 1).get('[data-testid=practice-btn]').text()).toBe("O'ynab mustahkamlash")
    })
  })

  describe('one more round (docs/06 §13.2)', () => {
    /** The store as the training screen leaves it after the last card of a round: a daily review that ended with `sprint_complete`. */
    function afterRound(canContinue = true, summaryValue: SessionSummary | null = summary()): ReturnType<typeof useTrainingStore> {
      const training = useTrainingStore()
      training.sessionId = 77
      training.mode = 'daily_review'
      training.canContinue = canContinue
      training.summary = summaryValue
      return training
    }

    it('says the round is passed, and makes "Ещё раунд" the main button, above the play button and "На главную"', () => {
      afterRound()
      useTrainingStore().summary = summary({ practice_entry: { course: 'english-a1', lesson_id: null } })
      const w = render(12, 1, 'sprint_complete')

      expect(w.get('[data-testid=reason]').text()).toBe('Раунд пройден')
      const next = w.get('[data-testid=next-round-btn]')
      expect(next.text()).toBe('Ещё раунд')
      expect(next.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      const order = w.findAll('[data-testid=next-round-btn], [data-testid=practice-btn], [data-testid=home-btn], [data-testid=close-btn]').map((b) => b.attributes('data-testid'))
      expect(order).toEqual(['next-round-btn', 'practice-btn', 'home-btn', 'close-btn'])
      expect(w.findAll('.btn-primary')).toHaveLength(1)
      expect(w.get('[data-testid=home-btn]').classes()).not.toContain('btn-primary')
    })

    it('has no such button when the server did not say the learner can go on, or the reason is another one', () => {
      afterRound(false)
      expect(render(12, 1, 'sprint_complete').find('[data-testid=next-round-btn]').exists()).toBe(false)

      afterRound(true)
      expect(render(12, 1, 'nothing_due').find('[data-testid=next-round-btn]').exists()).toBe(false)
      expect(render(12, 1).find('[data-testid=next-round-btn]').exists()).toBe(false)
      // "На главную" is the main button again
      expect(render(12, 1, 'nothing_due').get('[data-testid=home-btn]').classes()).toContain('btn-primary')
    })

    it('opens a new daily review and goes into it; the session is already closed (the finish screen did it)', async () => {
      afterRound()
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 78, mode: 'daily_review', lesson: null, total_words: 12 })
      const w = render(12, 1, 'sprint_complete')

      await w.get('[data-testid=next-round-btn]').trigger('click')
      await flushPromises()

      expect(api.training.finish).not.toHaveBeenCalled()
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
      expect(router.currentRoute.value.name).toBe('training')
      expect(router.currentRoute.value.params.sessionId).toBe('78')
    })

    it('closes the session first when its finish had failed, and only then opens the new one', async () => {
      afterRound(true, null)
      vi.mocked(api.training.finish).mockResolvedValue(summary())
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 79, mode: 'daily_review', lesson: null, total_words: 12 })
      const w = render(12, 1, 'sprint_complete')

      await w.get('[data-testid=next-round-btn]').trigger('click')
      await flushPromises()

      expect(api.training.finish).toHaveBeenCalledWith(77)
      expect(vi.mocked(api.training.finish).mock.invocationCallOrder[0]).toBeLessThan(vi.mocked(api.training.open).mock.invocationCallOrder[0]!)
      expect(router.currentRoute.value.params.sessionId).toBe('79')
    })

    it('shows a refusal on this screen and leaves the button free to press again', async () => {
      const training = afterRound()
      vi.mocked(api.training.open).mockRejectedValueOnce(new ApiClientError('server', 500, 'unknown', 'Boom'))
      await router.push('/today')
      const w = render(12, 1, 'sprint_complete')

      await w.get('[data-testid=next-round-btn]').trigger('click')
      await flushPromises()

      expect(w.get('[data-testid=open-error]').text()).not.toBe('')
      expect(router.currentRoute.value.name).toBe('today')
      expect(w.get('[data-testid=next-round-btn]').attributes('disabled')).toBeUndefined()
      expect(training.opening).toBe(false)

      vi.mocked(api.training.open).mockResolvedValue({ session_id: 80, mode: 'daily_review', lesson: null, total_words: 12 })
      await w.get('[data-testid=next-round-btn]').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.params.sessionId).toBe('80')
    })

    it('shows a failed finish the same way and does not open a session that the server would answer with the same finished one', async () => {
      afterRound(true, null)
      vi.mocked(api.training.finish).mockRejectedValue(new ApiClientError('server', 500, 'unknown', 'Boom'))
      const w = render(12, 1, 'sprint_complete')

      await w.get('[data-testid=next-round-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).not.toHaveBeenCalled()
      expect(w.find('[data-testid=open-error]').exists()).toBe(true)
    })

    it('speaks Uzbek', () => {
      setLocale('uz')
      afterRound()
      const w = render(12, 1, 'sprint_complete')

      expect(w.get('[data-testid=reason]').text()).toBe('Raund tugadi')
      expect(w.get('[data-testid=next-round-btn]').text()).toBe('Yana bir raund')
    })

    describe('what grew (stages)', () => {
      it('lists the new words, the words that moved up and the learned ones, right under the three tiles and above the buttons', () => {
        afterRound(true, summary({ stages: { graduated: 6, promoted: 2, mastered: 1 } }))
        const w = render(12, 1, 'sprint_complete')

        expect(w.get('[data-testid=stages]').text()).toBe('Новых слов: 6 · Поднялись: 2 · Выучено: 1')
        const order = w.findAll('[data-testid=stats], [data-testid=stages], [data-testid=next-round-btn], [data-testid=home-btn]').map((b) => b.attributes('data-testid'))
        expect(order).toEqual(['stats', 'stages', 'next-round-btn', 'home-btn'])
      })

      it('is not there when only words graduated: the tile of the new words says the same', () => {
        afterRound(true, summary({ new_words: 6, stages: { graduated: 6, promoted: 0, mastered: 0 } }))
        const w = render(12, 1, 'sprint_complete')

        expect(w.get('[data-testid=stat-new]').text()).toBe('6')
        expect(w.find('[data-testid=stages]').exists()).toBe(false)
      })

      it('is there when a word is learned or moved up, and keeps the zeros out', () => {
        afterRound(true, summary({ stages: { graduated: 0, promoted: 0, mastered: 2 } }))

        expect(render(12, 1, 'sprint_complete').get('[data-testid=stages]').text()).toBe('Выучено: 2')
      })

      it('leaves the zeros out', () => {
        afterRound(true, summary({ stages: { graduated: 0, promoted: 3, mastered: 0 } }))

        expect(render(12, 1, 'sprint_complete').get('[data-testid=stages]').text()).toBe('Поднялись: 3')
      })

      it('is not there when everything is zero, when the server sent none, or without a summary', () => {
        afterRound(true, summary({ stages: { graduated: 0, promoted: 0, mastered: 0 } }))
        expect(render(12, 1).find('[data-testid=stages]').exists()).toBe(false)

        afterRound(true, summary())
        expect(render(12, 1).find('[data-testid=stages]').exists()).toBe(false)

        setActivePinia(createPinia())
        expect(render(12, 1).find('[data-testid=stages]').exists()).toBe(false)
      })

      it('speaks Uzbek', () => {
        setLocale('uz')
        afterRound(true, summary({ stages: { graduated: 6, promoted: 2, mastered: 1 } }))

        expect(render(12, 1).get('[data-testid=stages]').text()).toBe("Yangi so'zlar: 6 · Yuksaldi: 2 · O'rganildi: 1")
      })
    })
  })

  describe('numbers of the session', () => {
    it('shows the cards, the share of right answers and the new words as three tiles when the server sent a summary', () => {
      useTrainingStore().summary = summary({ reviewed: 20, correct: 17, new_words: 6 })
      const w = render(20, 1)

      const stats = w.get('[data-testid=stats]')
      expect(stats.findAll('.card')).toHaveLength(3)
      expect(w.get('[data-testid=stat-reviewed]').text()).toBe('20')
      expect(w.get('[data-testid=stat-correct]').text()).toBe('85%')
      expect(w.get('[data-testid=stat-new]').text()).toBe('6')
      expect(stats.text()).toContain('Карточек')
      expect(stats.text()).toContain('Верно')
      expect(stats.text()).toContain('Новых слов')
      // The tiles replace the sentence.
      expect(w.find('[data-testid=reviewed]').exists()).toBe(false)
    })

    it.each([
      [5, 4, '80%'],
      [3, 1, '33%'],
      [3, 2, '67%'],
      [7, 7, '100%'],
      [4, 0, '0%'],
    ])('rounds the share of right answers: %i cards, %i right -> %s', (reviewed, correct, percent) => {
      useTrainingStore().summary = summary({ reviewed, correct })

      expect(render(reviewed, 1).get('[data-testid=stat-correct]').text()).toBe(percent)
    })

    it('uses a dash for the share while nothing was answered', () => {
      useTrainingStore().summary = summary({ reviewed: 0, correct: 0, new_words: 0 })

      const w = render(0, 1)
      expect(w.get('[data-testid=stat-correct]').text()).toBe('—')
      expect(w.get('[data-testid=stat-reviewed]').text()).toBe('0')
    })

    it('hides the numbers of an empty session that a reason explains, but keeps the day', () => {
      useTrainingStore().summary = summary({ reviewed: 0, correct: 0, new_words: 0 })

      const w = render(0, 1, 'nothing_due')
      expect(w.find('[data-testid=stats]').exists()).toBe(false)
      expect(w.find('[data-testid=reviewed]').exists()).toBe(false)
      expect(w.find('[data-testid=reason]').exists()).toBe(true)
      expect(w.find('[data-testid=goal]').exists()).toBe(true)
    })

    it('has no tiles without a summary (group lesson, failed finish): only how many cards were reviewed', () => {
      const w = render(5, 1)

      expect(w.find('[data-testid=stats]').exists()).toBe(false)
      expect(w.find('[data-testid=stat-reviewed]').exists()).toBe(false)
      expect(w.get('[data-testid=reviewed]').text()).toBe('Вы повторили 5 слов.')
    })

    it('keeps the tiles when the summary has no goal and streak', () => {
      useTrainingStore().summary = summary({ goal: undefined, streak: undefined })

      const w = render(5, 1)
      expect(w.find('[data-testid=stats]').exists()).toBe(true)
      expect(w.find('[data-testid=goal]').exists()).toBe(false)
    })

    it('renders the tiles in Uzbek', () => {
      setLocale('uz')
      useTrainingStore().summary = summary()
      const stats = render(5, 1).get('[data-testid=stats]')

      expect(stats.text()).toContain('Kartochka')
      expect(stats.text()).toContain("To'g'ri")
      expect(stats.text()).toContain("Yangi so'z")
    })
  })

  describe('paywall', () => {
    beforeEach(() => mockPlan('free'))

    it('opens only when the learner asks for it after the new words ran out, shows the contact, and can be dismissed', async () => {
      const w = render(3, 1, 'new_quota_exhausted')
      await flushPromises()

      // Nothing pops up by itself: the calm card is what the screen shows.
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
      await w.get('[data-testid=paywall-open]').trigger('click')
      expect(w.get('[data-testid=paywall-title]').text()).toBe('Что будет в Pro')
      expect(w.get('[data-testid=paywall-lead]').text()).toContain('Лимит новых слов на сегодня исчерпан')
      expect(w.get('[data-testid=paywall-contact-link]').attributes('href')).toBe('https://t.me/lexiflow_help')

      await w.get('[data-testid=paywall-close]').trigger('click')
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
      // The promo field is not on the screen itself, only inside the paywall.
      expect(w.find('[data-testid=promo]').exists()).toBe(false)

      // The learner can bring it back with the same button.
      const open = w.get('[data-testid=paywall-open]')
      expect(open.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
      expect(open.text()).toBe('Что даёт Pro')
      await open.trigger('click')
      expect(w.find('[data-testid=paywall]').exists()).toBe(true)
      // ... and it is still the only primary button of the screen.
      expect(w.findAll('.btn-primary')).toHaveLength(1)
    })

    it('"Repeat words" starts a daily review from the paywall when cards are due', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 55, mode: 'daily_review', lesson: null, total_words: 4 })
      vi.mocked(api.today.get).mockResolvedValue({} as never)
      useTodayStore().data = { due_count: 4 } as never
      const w = render(3, 1, 'new_quota_exhausted')
      await flushPromises()

      await w.get('[data-testid=paywall-open]').trigger('click')
      await w.get('[data-testid=paywall-review]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
      expect(router.currentRoute.value.name).toBe('training')
      expect(router.currentRoute.value.params.sessionId).toBe('55')
    })

    it('shows the error and stays here when the review cannot be opened', async () => {
      vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('rate_limited', 429, 'unknown', 'Too Many Attempts.'))
      useTodayStore().data = { due_count: 4 } as never
      await router.push('/')
      const w = render(3, 1, 'new_quota_exhausted')
      await flushPromises()

      await w.get('[data-testid=paywall-open]').trigger('click')
      await w.get('[data-testid=paywall-review]').trigger('click')
      await flushPromises()

      expect(w.find('[data-testid=open-error]').exists()).toBe(true)
      expect(router.currentRoute.value.name).toBe('landing')
    })

    it.each(['lesson_complete', 'nothing_due', 'session_cap', null])('does not open for done_reason %s', async (reason) => {
      const w = render(3, 1, reason)
      await flushPromises()

      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
      expect(w.find('[data-testid=paywall-open]').exists()).toBe(false)
      expect(w.find('[data-testid=promo]').exists()).toBe(false)
      expect(api.me.get).not.toHaveBeenCalled()
    })
  })

  describe('goal and streak of the day', () => {
    it('shows the progress to the goal, the streak and the risk to it', () => {
      useTrainingStore().summary = summary()
      const w = render(5, 1)

      expect(w.get('[data-testid=goal-text]').text()).toBe('Цель дня: 12 из 20')
      expect(w.get('[data-testid=streak]').text()).toContain('3 дня')
      expect(w.get('[data-testid=streak-at-risk]').text()).toBe('Выполните цель до полуночи, чтобы не потерять серию')
      expect(w.get('[data-testid=streak-at-risk]').classes()).toContain('text-warning-ink')

      const bar = w.get('[data-testid=goal] [role=progressbar]')
      expect(bar.attributes('aria-valuenow')).toBe('60')
      expect(bar.get('.progress-fill').attributes('style')).toContain('width: 60%')
      expect(bar.classes()).not.toContain('progress-success')
    })

    it('celebrates a met goal and drops the risk line', () => {
      useTrainingStore().summary = summary({
        goal: { target: 20, done: 21, met: true },
        streak: { current: 1, best: 1, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false },
      })
      const w = render(5, 1)

      expect(w.get('[data-testid=goal-text]').text()).toBe('Цель дня выполнена!')
      expect(w.get('[data-testid=goal-text]').classes()).toContain('text-success-ink')
      expect(w.get('[data-testid=streak]').text()).toContain('1 день')
      expect(w.find('[data-testid=streak-at-risk]').exists()).toBe(false)

      // A met goal is a full green bar, also when the learner did more than the target.
      const bar = w.get('[data-testid=goal] [role=progressbar]')
      expect(bar.attributes('aria-valuenow')).toBe('100')
      expect(bar.classes()).toContain('progress-success')
    })

    it('says "the streak is held by a run" only when the day was held by a run of Karvon (docs/05 §5b.4)', () => {
      const held = (by: 'goal' | 'game' | 'freeze' | null) => ({ current: 4, best: 4, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false, held_by: by })

      useTrainingStore().summary = summary({ streak: held('game') })
      expect(render(5, 1).get('[data-testid=streak-held-by-game]').text()).toBe('Серия удержана забегом')

      for (const by of ['goal', 'freeze', null] as const) {
        useTrainingStore().summary = summary({ streak: held(by) })
        expect(render(5, 1).find('[data-testid=streak-held-by-game]').exists(), String(by)).toBe(false)
      }
    })

    it('has no streak line while the series is zero', () => {
      useTrainingStore().summary = summary({ streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } })

      const w = render(5, 1)
      expect(w.find('[data-testid=streak]').exists()).toBe(false)
      expect(w.find('[data-testid=goal]').exists()).toBe(true)
    })

    it('shows nothing about the day for a session without summary (group lesson, failed finish)', () => {
      const w = render(5, 1)

      expect(w.find('[data-testid=goal]').exists()).toBe(false)
      expect(w.get('[data-testid=reviewed]').text()).toBe('Вы повторили 5 слов.')
    })

    it('renders in Uzbek', () => {
      setLocale('uz')
      useTrainingStore().summary = summary()
      const w = render(5, 1)

      expect(w.get('[data-testid=goal-text]').text()).toBe('Kunlik maqsad: 20 tadan 12 tasi')
      expect(w.get('[data-testid=streak]').text()).toBe('3 kun ketma-ket')
      expect(w.get('[data-testid=home-btn]').text()).toBe('«Bugun» ekraniga')
    })
  })

  it('shows when the next review comes, with a clock', () => {
    const w = render(5, 3)

    expect(w.get('[data-testid=next-label]').text()).toBe('Через 3 дня')
    const tile = w.get('[data-testid=next-label]').element.closest('section')!.querySelector('.icon-tile path')
    expect(tile?.getAttribute('d')).toBe(ICONS.clock.outline[0]?.d)
  })

  it('leads home to the Today screen', async () => {
    const w = render(5, 1)
    const home = w.get('[data-testid=home-btn]')

    expect(home.text()).toBe('К экрану «Сегодня»')
    expect(home.attributes('href')).toBe('/today')
    await home.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')
  })

  describe('league points and the month line (docs/13 §7.6)', () => {
    const mine = (me: MonthlyRatingMeResponse['me'], enabled = true): MonthlyRatingMeResponse => ({
      enabled,
      month: { starts_on: '2026-11-01', ends_on: '2026-11-30', days_left: 14 },
      me,
    })
    const placed = { rank: 12, points: 340, active_days: 5, participant: true, eligible: false, missing: { active_days: 2, account_days: 0 } }

    function renderWith(summaryValue: SessionSummary | null) {
      useTrainingStore().summary = summaryValue
      return render(summaryValue?.reviewed ?? 3, 1)
    }

    it('shows "+35 баллов" next to the stats, then the month line with a link to the month screen', async () => {
      vi.mocked(api.league.monthMe).mockResolvedValue(mine(placed))
      const w = renderWith(summary({ league_points: 35 }))
      await flushPromises()

      expect(w.get('[data-testid=points-earned]').text()).toBe('+35 баллов')
      expect(w.get('[data-testid=month-line]').text()).toBe('В рейтинге месяца: 12 место · 340 баллов')
      expect(w.get('[data-testid=month-line]').attributes('href')).toBe('/league/month')
    })

    it('says "+35 ball" in Uzbek', async () => {
      setLocale('uz')
      vi.mocked(api.league.monthMe).mockResolvedValue(mine(placed))
      const w = renderWith(summary({ league_points: 35 }))
      await flushPromises()

      expect(w.get('[data-testid=points-earned]').text()).toBe('+35 ball')
      expect(w.get('[data-testid=month-line]').text()).toBe("Oylik reytingda: 12-o'rin · 340 ball")
    })

    it('has no points line for 0 points or an older server, but still shows the month line', async () => {
      vi.mocked(api.league.monthMe).mockResolvedValue(mine(placed))
      expect(renderWith(summary({ league_points: 0 })).find('[data-testid=points-earned]').exists()).toBe(false)
      expect(renderWith(summary()).find('[data-testid=points-earned]').exists()).toBe(false)
    })

    it('shows no month line when the request fails, and the screen stays intact', async () => {
      vi.mocked(api.league.monthMe).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const w = renderWith(summary({ league_points: 35 }))
      await flushPromises()

      expect(w.find('[data-testid=month-line]').exists()).toBe(false)
      expect(w.get('[data-testid=points-earned]').text()).toBe('+35 баллов')
    })

    it.each([
      ['me is null', mine(null)],
      ['rank is null', mine({ ...placed, rank: null })],
      ['the rating is off', mine(placed, false)],
    ])('shows no month line when %s', async (_name, response) => {
      vi.mocked(api.league.monthMe).mockResolvedValue(response)
      const w = renderWith(summary({ league_points: 10 }))
      await flushPromises()

      expect(w.find('[data-testid=month-line]').exists()).toBe(false)
    })

    it('asks for nothing in a group lesson (no summary) or after an empty session', async () => {
      const group = renderWith(null)
      const empty = renderWith(summary({ reviewed: 0, correct: 0, new_words: 0 }))
      await flushPromises()

      expect(api.league.monthMe).not.toHaveBeenCalled()
      expect(group.find('[data-testid=points-earned]').exists()).toBe(false)
      expect(empty.find('[data-testid=month-line]').exists()).toBe(false)
    })
  })

  describe('a livelier finish (docs/14 §6.3)', () => {
    const achievement = (key: string, title: string, unlockedAt: string | null) => ({
      key, title, description: '', unlocked_at: unlockedAt, shared_at: null, progress: null, share_text: null,
    })

    describe('confetti', () => {
      it('bursts once for a session that reviewed something, and goes away by itself', () => {
        reduceMotion(false)
        vi.useFakeTimers()
        useTrainingStore().summary = summary()
        const w = render(5, 1)

        const confetti = w.get('[data-testid=confetti]')
        expect(confetti.attributes('aria-hidden')).toBe('true')
        expect(confetti.findAll('.confetti-piece').length).toBeGreaterThanOrEqual(15)
        expect(confetti.findAll('.confetti-piece').length).toBeLessThanOrEqual(25)

        vi.advanceTimersByTime(2000)
        return w.vm.$nextTick().then(() => expect(w.find('[data-testid=confetti]').exists()).toBe(false))
      })

      it('is not thrown for an empty session', () => {
        reduceMotion(false)
        useTrainingStore().summary = summary({ reviewed: 0, correct: 0, new_words: 0 })

        expect(render(0, 0, 'nothing_due').find('[data-testid=confetti]').exists()).toBe(false)
      })

      it('is not thrown with reduced motion', () => {
        reduceMotion(true)

        expect(render(5, 1).find('[data-testid=confetti]').exists()).toBe(false)
      })
    })

    describe('the title follows the result', () => {
      it.each([
        [10, 9, 0, 'Отлично!'],
        [10, 8, 0, 'Отлично!'],
        [10, 7, 0, 'Хорошая работа!'],
        [10, 6, 0, 'Хорошая работа!'],
        [10, 5, 0, 'Так держать!'],
        [10, 4, 0, 'Так держать!'],
        [10, 2, 0, 'Главное — вы позанимались'],
        [10, 2, 12, 'Отлично!'],
      ])('%i cards, %i right, best run %i: "%s"', (reviewed, correct, best, title) => {
        useTrainingStore().summary = summary({ reviewed, correct })
        useTrainingStore().bestCombo = best

        expect(render(reviewed, 1).get('h1').text()).toBe(title)
      })

      it('says it in Uzbek', () => {
        setLocale('uz')
        useTrainingStore().summary = summary({ reviewed: 10, correct: 7 })

        expect(render(10, 1).get('h1').text()).toBe('Yaxshi ish!')
      })
    })

    describe('the numbers count up', () => {
      it('count from 0 to the value in about 600 ms, then stay', async () => {
        reduceMotion(false)
        let now = 0
        const frames: Array<() => void> = []
        vi.spyOn(performance, 'now').mockImplementation(() => now)
        vi.stubGlobal('requestAnimationFrame', (cb: () => void) => frames.push(cb))
        vi.stubGlobal('cancelAnimationFrame', vi.fn())
        useTrainingStore().summary = summary({ reviewed: 20, correct: 17, new_words: 6 })
        const w = render(20, 1)
        await w.vm.$nextTick()

        expect(w.get('[data-testid=stat-reviewed]').text()).toBe('0')
        now = 300
        frames.splice(0).forEach((frame) => frame())
        await w.vm.$nextTick()
        const middle = Number(w.get('[data-testid=stat-reviewed]').text())
        expect(middle).toBeGreaterThan(0)
        expect(middle).toBeLessThan(20)
        now = 700
        frames.splice(0).forEach((frame) => frame())
        await w.vm.$nextTick()
        expect(w.get('[data-testid=stat-reviewed]').text()).toBe('20')
        expect(w.get('[data-testid=stat-correct]').text()).toBe('85%')
        expect(w.get('[data-testid=stat-new]').text()).toBe('6')
        expect(frames).toHaveLength(0)
        vi.restoreAllMocks()
      })

      it('show the final numbers at once with reduced motion', async () => {
        reduceMotion(true)
        useTrainingStore().summary = summary({ reviewed: 20, correct: 17, new_words: 6 })
        const w = render(20, 1)
        await flushPromises()

        expect(w.get('[data-testid=stat-reviewed]').text()).toBe('20')
      })
    })

    describe('the best run', () => {
      it('is a line of the finish screen from the run that the chip showed', () => {
        useTrainingStore().summary = summary()
        useTrainingStore().bestCombo = 12

        expect(render(5, 1).get('[data-testid=best-combo]').text()).toBe('Лучшая серия: 12')
        setLocale('uz')
        expect(render(5, 1).get('[data-testid=best-combo]').text()).toBe('Eng uzun ketma-ketlik: 12')
      })

      it.each([0, 2])('has no line for a run of %i', (best) => {
        useTrainingStore().summary = summary()
        useTrainingStore().bestCombo = best

        expect(render(5, 1).find('[data-testid=best-combo]').exists()).toBe(false)
      })
    })

    describe('the new achievement', () => {
      const startedAt = Date.parse('2026-11-17T10:00:00Z')

      beforeEach(() => {
        useTrainingStore().summary = summary()
        useTrainingStore().startedAt = startedAt
      })

      it('is a card with a link to the achievements when one came during the session', async () => {
        vi.mocked(api.achievements.list).mockResolvedValue({
          unlocked_count: 2, total: 9, share_link: null,
          items: [
            achievement('first_session', 'Первое занятие', '2026-11-16T09:00:00Z'),
            achievement('words_10', '10 слов', '2026-11-17T10:12:00Z'),
            achievement('streak_7', 'Неделя подряд', null),
          ],
        })
        const w = render(5, 1)
        // The screen is there before the answer is.
        expect(w.find('[data-testid=new-achievement]').exists()).toBe(false)
        await flushPromises()

        const card = w.get('[data-testid=new-achievement]')
        expect(card.text()).toBe('Новое достижение: 10 слов')
        expect(card.attributes('href')).toBe('/achievements')
      })

      it('measures the session by the SERVER start of the summary, not by the clock of the device', async () => {
        // The device clock is an hour fast: by it the unlock of 10:12 would be before the start, by the server it is after it.
        useTrainingStore().startedAt = Date.parse('2026-11-17T11:00:00Z')
        useTrainingStore().summary = summary({ started_at: '2026-11-17T10:00:00+00:00' })
        vi.mocked(api.achievements.list).mockResolvedValue({
          unlocked_count: 1, total: 9, share_link: null,
          items: [achievement('words_10', '10 слов', '2026-11-17T10:12:00Z')],
        })
        const w = render(5, 1)
        await flushPromises()

        expect(w.get('[data-testid=new-achievement]').text()).toBe('Новое достижение: 10 слов')
      })

      it('does not take an unlock from before the server start, however late the device clock is', async () => {
        useTrainingStore().startedAt = Date.parse('2026-11-17T09:00:00Z')
        useTrainingStore().summary = summary({ started_at: '2026-11-17T10:00:00+00:00' })
        vi.mocked(api.achievements.list).mockResolvedValue({
          unlocked_count: 1, total: 9, share_link: null,
          items: [achievement('words_10', '10 слов', '2026-11-17T09:30:00Z')],
        })
        const w = render(5, 1)
        await flushPromises()

        expect(w.find('[data-testid=new-achievement]').exists()).toBe(false)
      })

      it('shows the latest one when several came', async () => {
        vi.mocked(api.achievements.list).mockResolvedValue({
          unlocked_count: 2, total: 9, share_link: null,
          items: [
            achievement('a', 'Раньше', '2026-11-17T10:05:00Z'),
            achievement('b', 'Позже', '2026-11-17T10:20:00Z'),
          ],
        })
        const w = render(5, 1)
        await flushPromises()

        expect(w.get('[data-testid=new-achievement]').text()).toBe('Новое достижение: Позже')
      })

      it('is not there when everything was unlocked before the session, or nothing is', async () => {
        vi.mocked(api.achievements.list).mockResolvedValue({
          unlocked_count: 1, total: 9, share_link: null,
          items: [achievement('first_session', 'Первое занятие', '2026-11-16T09:00:00Z'), achievement('streak_7', 'Неделя', null)],
        })
        const w = render(5, 1)
        await flushPromises()

        expect(w.find('[data-testid=new-achievement]').exists()).toBe(false)
      })

      it('ignores a failed request: no card, no error on the screen', async () => {
        vi.mocked(api.achievements.list).mockRejectedValue(new Error('offline'))
        const w = render(5, 1)
        await flushPromises()

        expect(w.find('[data-testid=new-achievement]').exists()).toBe(false)
        expect(w.find('[data-testid=open-error]').exists()).toBe(false)
        expect(w.find('[data-testid=finished-view]').exists()).toBe(true)
      })

      it('is not asked for an empty session', async () => {
        useTrainingStore().summary = summary({ reviewed: 0, correct: 0, new_words: 0 })
        render(0, 0, 'nothing_due')
        await flushPromises()

        expect(api.achievements.list).not.toHaveBeenCalled()
        expect(useAchievementsStore().data).toBeNull()
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')
        vi.mocked(api.achievements.list).mockResolvedValue({
          unlocked_count: 1, total: 9, share_link: null,
          items: [achievement('words_10', "10 so'z", '2026-11-17T10:12:00Z')],
        })
        const w = render(5, 1)
        await flushPromises()

        expect(w.get('[data-testid=new-achievement]').text()).toBe("Yangi yutuq: 10 so'z")
      })
    })

    describe('the end of the new words of the day', () => {
      it('is a calm card on Free with two ways on, and nothing opens by itself', async () => {
        mockPlan('free')
        const w = render(3, 1, 'new_quota_exhausted')
        await flushPromises()

        const card = w.get('[data-testid=quota-card]')
        expect(card.text()).toContain('На сегодня новые слова закончились. Завтра будут новые')
        expect(card.get('[data-testid=paywall-open]').text()).toBe('Что даёт Pro')
        expect(card.get('[data-testid=quota-invite]').text()).toBe('Пригласить друзей — Pro бесплатно')
        expect(w.find('[data-testid=paywall]').exists()).toBe(false)
        // The reason line says the same: it is not repeated.
        expect(w.find('[data-testid=reason]').exists()).toBe(false)
      })

      it('"What Pro gives" opens the sheet', async () => {
        mockPlan('free')
        const w = render(3, 1, 'new_quota_exhausted')
        await flushPromises()

        await w.get('[data-testid=paywall-open]').trigger('click')

        expect(w.find('[data-testid=paywall]').exists()).toBe(true)
      })

      it('"Invite friends" leads to the referrals', async () => {
        mockPlan('free')
        const w = render(3, 1, 'new_quota_exhausted')
        await flushPromises()

        expect(w.get('[data-testid=quota-invite]').attributes('href')).toBe('/referrals')
        await w.get('[data-testid=quota-invite]').trigger('click')
        await flushPromises()
        expect(router.currentRoute.value.name).toBe('referrals')
      })

      it('is not there for a Pro (the trial and the paid one alike), which keeps the reason line', async () => {
        for (const extra of [{}, { renewal: { provider: 'trial', ends_at: '2026-12-01T00:00:00Z' } }]) {
          mockPlan('pro', extra)
          const w = render(3, 1, 'new_quota_exhausted')
          await flushPromises()

          expect(w.find('[data-testid=quota-card]').exists()).toBe(false)
          expect(w.find('[data-testid=paywall-open]').exists()).toBe(false)
          expect(w.find('[data-testid=paywall]').exists()).toBe(false)
          expect(w.find('[data-testid=reason]').exists()).toBe(true)
          useTrainingStore().$reset()
          setActivePinia(createPinia())
        }
      })

      it('is not there while the plan is not known', () => {
        mockPlan('free')

        expect(render(3, 1, 'new_quota_exhausted').find('[data-testid=quota-card]').exists()).toBe(false)
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')
        mockPlan('free')
        const w = render(3, 1, 'new_quota_exhausted')
        await flushPromises()

        expect(w.get('[data-testid=quota-card]').text()).toContain("Bugun yangi so'zlar tugadi. Ertaga yangilari bo'ladi")
        expect(w.get('[data-testid=paywall-open]').text()).toBe('Pro nima beradi')
        expect(w.get('[data-testid=quota-invite]').text()).toBe("Do'stlarni taklif qiling — Pro bepul")
      })
    })
  })
})
