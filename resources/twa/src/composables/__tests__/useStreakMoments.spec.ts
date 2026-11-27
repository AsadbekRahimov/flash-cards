import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { dayBefore, streakMoment, streakWeek, watchStreakMoments } from '../useStreakMoments'
import { hasSeen, markSeen, resetSeenSession } from '../seenFlag'
import { trialEndedKey } from '../trialEnded'
import { useAuthStore } from '@/stores/auth'
import { useTodayStore } from '@/stores/today'
import type { Streak, StreakWeekDay, TodayResponse } from '@/api/types'

/*
 * F4 (docs/40 §2.5): the screen of the streak is opened by itself once per event, from the `/today` "Today" has loaded - never by a
 * request of its own, never over the onboarding, a deep link, a lesson or an open session, never by an older server without `week`.
 */

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, me: { get: vi.fn() }, auth: { authenticate: vi.fn() } } }))

/** Monday 23 … Sunday 29 November 2026, today the 27th (Friday). */
function week(held: Array<StreakWeekDay['held_by']> = ['goal', 'goal', 'game', 'freeze', null, null, null], todayIndex = 4): StreakWeekDay[] {
  return held.map((heldBy, index) => ({ date: `2026-11-${23 + index}`, held_by: heldBy, is_today: index === todayIndex }))
}

function streak(overrides: Partial<Streak> = {}): Streak {
  return { current: 12, best: 12, met_today: false, at_risk: true, freezes: 1, frozen_yesterday: true, held_by: null, week: week(), lost: null, ...overrides }
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

const screen = (name: string) => defineComponent({ render: () => h('section', { 'data-testid': name }) })


async function setup(path = '/today', start: string | null = null): Promise<Router> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: screen('today'), meta: { tab: 'today' } },
      { path: '/streak/saved', name: 'streak-saved', component: screen('streak') },
      { path: '/training/:id', name: 'training', component: screen('training'), meta: { immersive: true } },
      { path: '/onboarding/:step?', name: 'onboarding', component: screen('onboarding') },
    ],
  })
  await router.push(path)
  watchStreakMoments(router, { readStart: () => start })
  return router
}

/** As "Today" does: the store gets the answer of `/today`. */
async function load(data: TodayResponse): Promise<void> {
  useTodayStore().data = data
  await flushPromises()
}

describe('streak moments (F4)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    window.localStorage.clear()
    resetSeenSession()
    vi.clearAllMocks()
  })
  afterEach(() => window.localStorage.clear())

  describe('which moment', () => {
    it('counts the day before in the calendar, across a month and a year', () => {
      expect(dayBefore('2026-11-27')).toBe('2026-11-26')
      expect(dayBefore('2026-12-01')).toBe('2026-11-30')
      expect(dayBefore('2027-01-01')).toBe('2026-12-31')
      expect(dayBefore('2028-03-01')).toBe('2028-02-29')
      expect(dayBefore('yesterday')).toBeNull()
    })

    it('is "saved" by `frozen_yesterday`, keyed by yesterday - also on a Monday, whose yesterday is last week', () => {
      expect(streakMoment(streak())).toEqual({ kind: 'saved', key: 'streak_saved:2026-11-26' })
      expect(streakMoment(streak({ week: week(['freeze', null, null, null, null, null, null], 0) }))).toEqual({ kind: 'saved', key: 'streak_saved:2026-11-22' })
    })

    it('is "lost" by `lost`, keyed by `lost.on`; a saved streak goes first', () => {
      const lost = { days: 9, on: '2026-11-27' }
      expect(streakMoment(streak({ frozen_yesterday: false, lost }))).toEqual({ kind: 'lost', key: 'streak_lost:2026-11-27' })
      expect(streakMoment(streak({ lost }))?.kind).toBe('saved')

      markSeen('streak_saved:2026-11-26')
      expect(streakMoment(streak({ lost }))).toEqual({ kind: 'lost', key: 'streak_lost:2026-11-27' })
      markSeen('streak_lost:2026-11-27')
      expect(streakMoment(streak({ lost }))).toBeNull()
    })

    it('is nothing by an older server (no `week`), a broken week, no event or a loss of 0 days', () => {
      const { week: _week, lost: _lost, ...older } = streak()
      expect(streakMoment(older)).toBeNull()
      expect(streakMoment(streak({ week: week().slice(0, 6) }))).toBeNull()
      expect(streakMoment(streak({ week: week().map((day) => ({ ...day, is_today: false })) }))).toBeNull()
      expect(streakWeek(older)).toBeNull()
      expect(streakMoment(streak({ frozen_yesterday: false }))).toBeNull()
      expect(streakMoment(streak({ frozen_yesterday: false, lost: { days: 0, on: '2026-11-27' } }))).toBeNull()
      expect(streakMoment(null)).toBeNull()
    })
  })

  describe('the watch of App', () => {
    it('opens "saved" once after /today has come, and marks it shown', async () => {
      const router = await setup()
      await load(todayData())

      expect(router.currentRoute.value.fullPath).toBe('/streak/saved')
      expect(hasSeen('streak_saved:2026-11-26')).toBe(true)

      // back on "Today", which loads /today again: the same event is not shown twice
      await router.push('/today')
      await load(todayData())
      expect(router.currentRoute.value.fullPath).toBe('/today')
    })

    it('starts with the answer already in the store (App loads it lazily, after "Today" may have loaded /today)', async () => {
      const router = createRouter({ history: createMemoryHistory(), routes: [
        { path: '/today', name: 'today', component: screen('today') },
        { path: '/streak/saved', name: 'streak-saved', component: screen('streak') },
      ] })
      await router.push('/today')
      useTodayStore().data = todayData()

      watchStreakMoments(router, { readStart: () => null })
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe('/streak/saved')
    })

    it('opens "lost" with `?kind=lost` and marks `streak_lost:<on>`', async () => {
      const router = await setup()
      await load(todayData({ streak: streak({ frozen_yesterday: false, current: 0, lost: { days: 9, on: '2026-11-27' } }) }))

      expect(router.currentRoute.value.fullPath).toBe('/streak/saved?kind=lost')
      expect(hasSeen('streak_lost:2026-11-27')).toBe(true)
    })

    it('asks the server for nothing: it only reads the store', async () => {
      await setup()
      await load(todayData())

      expect(api.today.get).not.toHaveBeenCalled()
      expect(api.me.get).not.toHaveBeenCalled()
      expect(api.auth.authenticate).not.toHaveBeenCalled()
    })

    it('shows nothing by an older server without `week`', async () => {
      const router = await setup()
      const { week: _week, lost: _lost, ...older } = streak()
      await load(todayData({ streak: older }))

      expect(router.currentRoute.value.fullPath).toBe('/today')
    })

    it.each([
      ['a lesson', '/training/5'],
      ['the onboarding', '/onboarding/name'],
      ['"Today" opened by a reminder', '/today?from=reminder'],
      ['"Today" after the onboarding', '/today?from=onboarding'],
    ])('does not open over %s', async (_name, path) => {
      const router = await setup(path)
      await load(todayData())

      expect(router.currentRoute.value.fullPath).toBe(path)
      expect(hasSeen('streak_saved:2026-11-26')).toBe(false)
    })

    it('does not open for the whole open by a deep link', async () => {
      const router = await setup('/today', 'L2JhdHRsZS83')
      await load(todayData())

      expect(router.currentRoute.value.fullPath).toBe('/today')
      expect(hasSeen('streak_saved:2026-11-26')).toBe(false)
    })

    it('does not open while a session is open', async () => {
      const router = await setup()
      await load(todayData({ open_session_id: 41 }))

      expect(router.currentRoute.value.fullPath).toBe('/today')
    })

    it('does not open to a learner the onboarding of the Mini App is still to take', async () => {
      useAuthStore().learner = { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: false, onboarding: { completed: false, step: 'age' } }
      const router = await setup()
      await load(todayData())

      expect(router.currentRoute.value.fullPath).toBe('/today')
    })

    it('leaves the open to the sheet "the trial is over" and shows itself at the next /today', async () => {
      const lapsed = { provider: 'trial', ended_at: '2026-11-26T19:00:00+00:00' } as const
      const data = todayData({
        entitlement: { plan: 'free', source: 'free', until: null, lapsed },
        streak: streak({ frozen_yesterday: false, current: 0, freezes: 0, lost: { days: 9, on: '2026-11-27' } }),
      })
      const router = await setup()
      await load(data)
      expect(router.currentRoute.value.fullPath).toBe('/today')
      expect(hasSeen('streak_lost:2026-11-27')).toBe(false)

      // "Today" has shown the sheet (`takeTrialEnded`); the next answer opens the screen of the streak
      markSeen(trialEndedKey(lapsed.ended_at))
      await load({ ...data })
      expect(router.currentRoute.value.fullPath).toBe('/streak/saved?kind=lost')
    })
  })
})
