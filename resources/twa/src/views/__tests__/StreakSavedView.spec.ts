import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import StreakSavedView from '../StreakSavedView.vue'
import StampMark from '@/components/ui/StampMark.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useTodayStore } from '@/stores/today'
import type { Streak, StreakWeekDay, TodayResponse } from '@/api/types'

/*
 * F4 of the kit (03-new-features/F4-seriya-spasena.png, docs/40 §2.5): «Серия спасена!» with the week, the one stamp of the screen and the
 * freezes left; «Серия прервалась» calm, Free offered Pro only on a tap. Everything from the stored `/today` - no request of its own.
 */

const haptic = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useTelegram', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/composables/useTelegram')>()
  return { ...actual, useTelegram: () => ({ ...actual.useTelegram(), haptic }) }
})

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, me: { get: vi.fn() }, training: { open: vi.fn() } } }))

/** The paywall is its own component with its own tests: here only whether it opens and why. */
vi.mock('@/components/PaywallSheet.vue', () => ({
  default: defineComponent({ props: { reason: String }, setup: (props) => () => h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }) }),
}))

const WEEK: StreakWeekDay[] = [
  { date: '2026-11-23', held_by: 'goal', is_today: false },
  { date: '2026-11-24', held_by: 'goal', is_today: false },
  { date: '2026-11-25', held_by: 'goal', is_today: false },
  { date: '2026-11-26', held_by: 'goal', is_today: false },
  { date: '2026-11-27', held_by: 'freeze', is_today: false },
  { date: '2026-11-28', held_by: null, is_today: true },
  { date: '2026-11-29', held_by: null, is_today: false },
]

function streak(overrides: Partial<Streak> = {}): Streak {
  return { current: 12, best: 12, met_today: false, at_risk: true, freezes: 1, frozen_yesterday: true, held_by: null, week: WEEK, lost: null, ...overrides }
}

function todayData(overrides: Partial<TodayResponse> = {}): TodayResponse {
  return {
    due_count: 0,
    new_words_remaining: null,
    goal: { target: 20, done: 0, met: false },
    streak: streak(),
    active_course: null,
    open_session_id: null,
    entitlement: { plan: 'pro', source: 'subscription', until: null, lapsed: null },
    onboarding_completed: true,
    league: null,
    timezone: 'Asia/Tashkent',
    ...overrides,
  }
}

async function render(kind: 'saved' | 'lost', data: TodayResponse | null = todayData()) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { render: () => h('section', { 'data-testid': 'today' }) } },
      { path: '/streak/saved', name: 'streak-saved', component: StreakSavedView, props: () => ({ kind }) },
    ],
  })
  await router.push('/today')
  await router.push(kind === 'lost' ? '/streak/saved?kind=lost' : '/streak/saved')
  useTodayStore().data = data
  const wrapper = mount(StreakSavedView, { props: { kind }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

const text = (w: ReturnType<typeof mount>, id: string): string => w.get(`[data-testid=${id}]`).text()

describe('StreakSavedView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  describe('«Серия спасена»', () => {
    it('is the screen of the kit: the mascot in the scarf, the title, the week, the stamp «12 ДНЕЙ», the freezes left, «Продолжить серию»', async () => {
      const { wrapper: w } = await render('saved')

      expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('freeze')
      expect(text(w, 'streak-title')).toBe('Серия спасена!')
      expect(text(w, 'streak-lead')).toBe('Вчера вы пропустили день, но заморозка сохранила все 12 дней.')
      expect(w.findAll('[data-testid=week-day]')).toHaveLength(7)
      expect(w.get('[data-testid=stamp]').attributes('aria-label')).toBe('12 дней')
      expect(text(w, 'streak-freezes')).toBe('Осталось заморозок: 1')
      expect(text(w, 'streak-continue')).toBe('Продолжить серию')
      expect(w.find('[data-testid=streak-pro-offer]').exists()).toBe(false)
    })

    it('presses the one stamp of the screen in with the haptic, once', async () => {
      const { wrapper: w } = await render('saved')

      expect(w.findAllComponents(StampMark)).toHaveLength(1)
      expect(w.getComponent(StampMark).props()).toMatchObject({ animate: true, haptic: true })
      expect(haptic.mock.calls).toEqual([['medium']])
    })

    it('says «2 дня» when the freezes held two days in a row', async () => {
      const week = WEEK.map((day) => (day.date === '2026-11-26' ? { ...day, held_by: 'freeze' as const } : day))
      const { wrapper: w } = await render('saved', todayData({ streak: streak({ week, freezes: 0 }) }))

      expect(text(w, 'streak-lead')).toBe('Вы пропустили 2 дня, но заморозки сохранили серию.')
      expect(text(w, 'streak-freezes')).toBe('Осталось заморозок: 0')
    })

    it('is in Uzbek too', async () => {
      setLocale('uz')
      const { wrapper: w } = await render('saved')

      expect(text(w, 'streak-title')).toBe('Ketma-ketlik saqlandi!')
      expect(text(w, 'streak-freezes')).toBe('Qolgan zaxira kunlar: 1')
      expect(text(w, 'streak-continue')).toBe('Davom ettirish')
    })

    it('goes back to "Today" with its button', async () => {
      const { wrapper: w, router } = await render('saved')

      await w.get('[data-testid=streak-continue]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('today')
    })
  })

  describe('«Серия прервалась»', () => {
    const lostData = (plan: 'free' | 'pro') =>
      todayData({
        streak: streak({ current: 0, freezes: 0, frozen_yesterday: false, at_risk: false, lost: { days: 9, on: '2026-11-28' } }),
        entitlement: plan === 'pro' ? { plan: 'pro', source: 'subscription', until: null, lapsed: null } : { plan: 'free', source: 'free', until: null, lapsed: null },
      })

    it('is calm: the thinking mascot, «Было 9 дней подряд», no stamp, no haptic, one Primary «Начать заново»', async () => {
      const { wrapper: w } = await render('lost', lostData('pro'))

      expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('thinking')
      expect(text(w, 'streak-title')).toBe('Серия прервалась')
      expect(text(w, 'streak-lead')).toBe('Было 9 дней подряд.')
      expect(w.find('[data-testid=stamp]').exists()).toBe(false)
      expect(haptic).not.toHaveBeenCalled()
      expect(w.findAll('.btn-primary')).toHaveLength(1)
      expect(text(w, 'streak-continue')).toBe('Начать заново')
    })

    it('tells Pro why its freezes were not enough, and offers nothing', async () => {
      const { wrapper: w } = await render('lost', lostData('pro'))

      expect(text(w, 'streak-pro-note')).toBe('Заморозок не хватило: пропущено дней больше, чем было заморозок.')
      expect(w.find('[data-testid=streak-pro-offer]').exists()).toBe(false)
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    })

    it('offers Free the freezes of Pro in a quiet card: the paywall `streak_freeze` opens only on a tap', async () => {
      const { wrapper: w } = await render('lost', lostData('free'))

      expect(text(w, 'streak-pro-offer')).toContain('Заморозка сохранила бы серию — в Pro')
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)

      await w.get('[data-testid=streak-pro-more]').trigger('click')

      expect(w.get('[data-testid=paywall]').attributes('data-reason')).toBe('streak_freeze')
    })

    it('is in Uzbek too', async () => {
      setLocale('uz')
      const { wrapper: w } = await render('lost', lostData('free'))

      expect(text(w, 'streak-title')).toBe('Ketma-ketlik uzildi')
      expect(text(w, 'streak-lead')).toBe("9 kun ketma-ket shug'ullangan edingiz.")
      expect(text(w, 'streak-continue')).toBe('Qaytadan boshlash')
    })
  })

  describe('without what to show', () => {
    it.each([
      ['no /today in the store (a reload on this address)', 'saved', null],
      ['an older server without `week`', 'saved', todayData({ streak: { current: 3, best: 3, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: true } })],
      ['no loss to tell', 'lost', todayData()],
    ] as const)('goes to "Today" for %s, asking the server for nothing', async (_name, kind, data) => {
      const { wrapper: w, router } = await render(kind, data)

      expect(router.currentRoute.value.name).toBe('today')
      expect(w.find('[data-testid=streak-view]').exists()).toBe(false)
      expect(api.today.get).not.toHaveBeenCalled()
    })
  })

  it('asks the server for nothing', async () => {
    await render('saved')
    await render('lost', todayData({ streak: streak({ lost: { days: 4, on: '2026-11-28' } }) }))

    expect(api.today.get).not.toHaveBeenCalled()
    expect(api.me.get).not.toHaveBeenCalled()
    expect(api.training.open).not.toHaveBeenCalled()
  })
})
