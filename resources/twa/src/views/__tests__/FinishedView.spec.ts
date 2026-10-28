import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import FinishedView from '../FinishedView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { ApiClientError } from '@/api/client'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import type { SessionSummary } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: { me: { get: vi.fn() }, promo: { redeem: vi.fn() }, today: { get: vi.fn() }, training: { open: vi.fn() } },
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: vi.fn(),
    notificationHaptic: vi.fn(),
  }),
}))

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'landing', component: { template: '<div />' } },
    { path: '/today', name: 'today', component: { template: '<div />' } },
    { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
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

describe('FinishedView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

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
    expect(w.get('[data-testid=close-btn]').text()).toBe('Yopish')
    expect(render(3, 0).get('[data-testid=next-label]').text()).toBe('Bugun')
  })

  it.each([
    ['lesson_complete', 'Все слова урока пройдены.'],
    ['nothing_due', 'На сегодня повторять нечего.'],
    ['new_quota_exhausted', 'Лимит новых слов на сегодня исчерпан. Новые слова откроются завтра.'],
    ['session_cap', 'Это была длинная сессия — сделайте перерыв.'],
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

  it('renders the reason in Uzbek', () => {
    setLocale('uz')
    expect(render(3, 1, 'new_quota_exhausted').get('[data-testid=reason]').text())
      .toBe("Bugungi yangi so'zlar limiti tugadi. Yangi so'zlar ertaga ochiladi.")
  })

  describe('paywall', () => {
    beforeEach(() => {
      vi.mocked(api.me.get).mockResolvedValue({
        learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
        groups: [],
        stats: { words_learned: 0, words_due_today: 0 },
        entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
        paywall: { mode: 'coming_soon', contact: '@lexiflow_help' },
        settings: settingsFixture(),
      })
    })

    it('opens when the new words of the day ran out, with the contact, and can be dismissed', async () => {
      const w = render(3, 1, 'new_quota_exhausted')
      await flushPromises()

      expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro скоро')
      expect(w.get('[data-testid=paywall-lead]').text()).toContain('Лимит новых слов на сегодня исчерпан')
      expect(w.get('[data-testid=paywall-contact-link]').attributes('href')).toBe('https://t.me/lexiflow_help')

      await w.get('[data-testid=paywall-close]').trigger('click')
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)

      // The learner can bring it back; the promo field lives inside the paywall then.
      await w.get('[data-testid=paywall-open]').trigger('click')
      expect(w.find('[data-testid=paywall]').exists()).toBe(true)
    })

    it('"Repeat words" starts a daily review from the paywall when cards are due', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 55, mode: 'daily_review', lesson: null, total_words: 4 })
      vi.mocked(api.today.get).mockResolvedValue({} as never)
      useTodayStore().data = { due_count: 4 } as never
      const w = render(3, 1, 'new_quota_exhausted')
      await flushPromises()

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
      expect(w.find('[data-testid=promo]').exists()).toBe(true)
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
    })

    it('celebrates a met goal and drops the risk line', () => {
      useTrainingStore().summary = summary({
        goal: { target: 20, done: 21, met: true },
        streak: { current: 1, best: 1, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false },
      })
      const w = render(5, 1)

      expect(w.get('[data-testid=goal-text]').text()).toBe('Цель дня выполнена!')
      expect(w.get('[data-testid=streak]').text()).toContain('1 день')
      expect(w.find('[data-testid=streak-at-risk]').exists()).toBe(false)
    })

    it('has no streak line while the series is zero', () => {
      useTrainingStore().summary = summary({ streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } })

      expect(render(5, 1).find('[data-testid=streak]').exists()).toBe(false)
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
      expect(w.get('[data-testid=home-btn]').text()).toBe('Bosh sahifaga')
    })
  })

  it('leads home to the Today screen', async () => {
    const w = render(5, 1)
    const home = w.get('[data-testid=home-btn]')

    expect(home.text()).toBe('На главную')
    expect(home.attributes('href')).toBe('/today')
    await home.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')
  })
})
