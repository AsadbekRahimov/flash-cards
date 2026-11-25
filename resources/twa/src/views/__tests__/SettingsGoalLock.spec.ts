import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '../SettingsView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import type { LearnerSettings, MeResponse } from '@/api/types'

/*
 * The daily goal behind Pro (docs/05 §8c-1, docs/34 §2в P4): with `daily_goal_max = 10` the goals 20 and 40 carry a lock, a tap on one
 * sends no PATCH and opens the paywall `daily_goal`; a 403 `daily_goal_pro_required` opens it too, without a toast; no limit, no locks.
 */

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn(), updateSettings: vi.fn(), revertTimezone: vi.fn() },
    organizations: { list: vi.fn(), leave: vi.fn() },
    billing: { catalog: vi.fn(), offerViewed: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => {
  const auth = { ensure: state.ensure, learner: { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: true } }
  return { useAuthStore: () => auth }
})

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {} }),
}))

const free = (max: number | null, goal = 10): LearnerSettings =>
  settingsFixture({ daily_goal: goal, options: { ...settingsFixture().options, daily_goal_max: max } })

const me = (settings: LearnerSettings): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 10 },
  paywall: { mode: 'coming_soon', contact: null },
  settings,
})

async function render(settings: LearnerSettings) {
  vi.mocked(api.me.get).mockResolvedValue(me(settings))
  vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [] })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: SettingsView },
    ],
  })
  await router.push('/settings')
  const wrapper = mount(SettingsView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof render>>

const goal = (w: Wrapper, value: number) => w.get(`[data-testid=goal-${value}]`)

describe('SettingsView: the daily goal behind Pro', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
    useToast().hide()
  })

  it('locks the goals above daily_goal_max, and only them', async () => {
    const w = await render(free(10))

    expect(goal(w, 10).attributes('aria-disabled')).toBeUndefined()
    expect(goal(w, 10).find('[data-testid=segment-lock]').exists()).toBe(false)
    for (const value of [20, 40]) {
      expect(goal(w, value).attributes('aria-disabled')).toBe('true')
      // Not `disabled`: the tap must reach the screen.
      expect(goal(w, value).attributes('disabled')).toBeUndefined()
      expect(goal(w, value).find('[data-testid=segment-lock]').exists()).toBe(true)
    }
    expect(w.text()).toContain('карточек в день')
  })

  it('opens the paywall on a locked goal and sends no PATCH; the control stays at the current goal', async () => {
    const w = await render(free(10))

    await goal(w, 40).trigger('click')
    await flushPromises()

    expect(api.me.updateSettings).not.toHaveBeenCalled()
    const sheet = w.get('[data-testid=paywall]')
    expect(sheet.attributes('aria-label')).toBe('Цель больше 10 — в Pro')
    expect(sheet.text()).toContain('На бесплатном тарифе цель дня — до 10 карточек.')
    expect(goal(w, 10).attributes('aria-checked')).toBe('true')
    expect(useToast().toast.value).toBeNull()

    await w.get('[data-testid=paywall-x]').trigger('click')
    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('saves a goal that is not locked as before', async () => {
    const w = await render(free(20))
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(free(20, 20)))

    await goal(w, 20).trigger('click')
    await flushPromises()

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ daily_goal: 20 }]])
    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('opens the paywall on 403 daily_goal_pro_required without an error toast, and locks the goals from then on', async () => {
    // An old /me: no limit known yet; the trial ended meanwhile.
    const w = await render(free(null))
    vi.mocked(api.me.updateSettings).mockRejectedValue(
      new ApiClientError('forbidden', 403, 'daily_goal_pro_required', 'Pro required', undefined, { max: 10 }),
    )

    await goal(w, 40).trigger('click')
    await flushPromises()

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ daily_goal: 40 }]])
    expect(w.get('[data-testid=paywall]').attributes('aria-label')).toBe('Цель больше 10 — в Pro')
    expect(useToast().toast.value).toBeNull()
    expect(goal(w, 10).attributes('aria-checked')).toBe('true')
    expect(goal(w, 40).attributes('aria-disabled')).toBe('true')
  })

  it('shows no locks without a limit (Pro)', async () => {
    const w = await render(free(null, 40))

    for (const value of [10, 20, 40]) {
      expect(goal(w, value).attributes('aria-disabled')).toBeUndefined()
      expect(goal(w, value).find('[data-testid=segment-lock]').exists()).toBe(false)
    }
  })

  it('says "in Pro" in the text of a locked goal when the goals do not fit the segments', async () => {
    const many = settingsFixture({ daily_goal: 10, options: { ...settingsFixture().options, daily_goals: [5, 10, 20, 40, 60], daily_goal_max: 10 } })
    const w = await render(many)

    const options = w.get('[data-testid=goal-select] select').findAll('option').map((o) => o.text())
    expect(options).toEqual(['5', '10', '20 — в Pro', '40 — в Pro', '60 — в Pro'])

    await w.get('[data-testid=goal-select] select').setValue('40')
    await flushPromises()

    expect(api.me.updateSettings).not.toHaveBeenCalled()
    expect(w.find('[data-testid=paywall]').exists()).toBe(true)
    expect((w.get('[data-testid=goal-select] select').element as HTMLSelectElement).value).toBe('10')
  })
})
