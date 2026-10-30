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
import { useTrainingStore } from '@/stores/training'
import type { SessionSummary } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: { me: { get: vi.fn() }, promo: { redeem: vi.fn() }, today: { get: vi.fn() }, training: { open: vi.fn() } },
}))

const telegram = vi.hoisted(() => ({ close: vi.fn(), notificationHaptic: vi.fn() }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => telegram }))

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'landing', component: { template: '<div />' } },
    { path: '/today', name: 'today', component: { template: '<div />' } },
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

      expect(home.text()).toBe('На главную')
      expect(home.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      expect(w.findAll('.btn-primary')).toHaveLength(1)

      const closeButton = w.get('[data-testid=close-btn]')
      expect(closeButton.text()).toBe('Закрыть')
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

    it('offers "Практика урока" after a lesson, as a tinted secondary action that leads to the hub of that lesson', () => {
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      const w = render(5, 1)

      const practice = w.get('[data-testid=practice-btn]')
      expect(practice.text()).toBe('Практика урока')
      expect(practice.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
      expect(practice.classes()).not.toContain('btn-primary')
      expect(practice.attributes('href')).toBe('/practice/english-a1?lesson=41&from=finished')
      expect(practice.get('path').attributes('d')).toBe(ICONS['puzzle-piece'].outline[0]?.d)
    })

    it('offers "Практика курса" after a daily review, without a lesson in the address', () => {
      useTrainingStore().summary = summary({ practice_entry: entry(null) })
      const w = render(5, 1)

      expect(w.get('[data-testid=practice-btn]').text()).toBe('Практика курса')
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

    it('does not compete with the main action: "На главную" is still the only primary button, and comes first', () => {
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      const w = render(5, 1)

      expect(w.findAll('.btn-primary')).toHaveLength(1)
      const order = w.findAll('[data-testid=home-btn], [data-testid=practice-btn], [data-testid=close-btn]').map((b) => b.attributes('data-testid'))
      expect(order).toEqual(['home-btn', 'practice-btn', 'close-btn'])
    })

    it('stands before "Узнать о Pro" when the new words ran out: practising is what the learner can still do today', async () => {
      vi.mocked(api.me.get).mockResolvedValue({
        learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
        groups: [],
        stats: { words_learned: 0, words_due_today: 0 },
        entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
        paywall: { mode: 'coming_soon', contact: '@lexiflow_help' },
        settings: settingsFixture(),
      })
      useTrainingStore().summary = summary({ practice_entry: entry(41) })
      const w = render(3, 1, 'new_quota_exhausted')
      await flushPromises()

      const order = w.findAll('[data-testid=home-btn], [data-testid=practice-btn], [data-testid=paywall-open], [data-testid=close-btn]').map((b) => b.attributes('data-testid'))
      expect(order).toEqual(['home-btn', 'practice-btn', 'paywall-open', 'close-btn'])
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
      expect(render(5, 1).get('[data-testid=practice-btn]').text()).toBe('Dars amaliyoti')

      useTrainingStore().summary = summary({ practice_entry: entry(null) })
      expect(render(5, 1).get('[data-testid=practice-btn]').text()).toBe('Kurs amaliyoti')
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
      // The promo field is not on the screen itself, only inside the paywall.
      expect(w.find('[data-testid=promo]').exists()).toBe(false)

      // The learner can bring it back with a secondary button.
      const open = w.get('[data-testid=paywall-open]')
      expect(open.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
      expect(open.text()).toBe('Узнать о Pro')
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
      expect(w.get('[data-testid=home-btn]').text()).toBe('Asosiy sahifaga')
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

    expect(home.text()).toBe('На главную')
    expect(home.attributes('href')).toBe('/today')
    await home.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')
  })
})
