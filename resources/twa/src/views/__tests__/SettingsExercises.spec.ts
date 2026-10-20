import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '../SettingsView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { LearnerSettings, MeResponse } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({
  api: { me: { get: vi.fn(), updateSettings: vi.fn() }, organizations: { list: vi.fn(), leave: vi.fn() } },
}))

vi.mock('@/stores/auth', () => {
  const auth = { ensure: state.ensure, learner: { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: true } }
  return { useAuthStore: () => auth }
})

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {} }),
}))

const me = (settings: LearnerSettings): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: settings.interface_locale, onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'coming_soon', contact: null },
  settings,
})

async function render(settings: LearnerSettings = settingsFixture()) {
  vi.mocked(api.me.get).mockResolvedValue(me(settings))
  vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [] })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: SettingsView },
    ],
  })
  await router.push('/settings')
  const wrapper = mount(SettingsView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

const box = (w: Awaited<ReturnType<typeof render>>, key: string): HTMLInputElement =>
  w.get(`[data-testid=exercise-${key}]`).element as HTMLInputElement

describe('SettingsView — exercises', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('shows one toggle per exercise type with the server values', async () => {
    const w = await render(settingsFixture({ exercises: { reverse: true, audio: false, spelling: true, gap_fill: false } }))

    expect(box(w, 'reverse').checked).toBe(true)
    expect(box(w, 'audio').checked).toBe(false)
    expect(box(w, 'spelling').checked).toBe(true)
    expect(box(w, 'gap_fill').checked).toBe(false)
    expect(w.get('[data-testid=exercises-card]').text()).toContain('Написание слова')
  })

  it('saves a toggle as a partial exercises patch and takes the server answer', async () => {
    const w = await render()
    vi.mocked(api.me.updateSettings).mockResolvedValue(
      me(settingsFixture({ exercises: { reverse: true, audio: false, spelling: true, gap_fill: true } })),
    )

    await w.get('[data-testid=exercise-audio]').setValue(false)
    await flushPromises()

    expect(api.me.updateSettings).toHaveBeenCalledWith({ exercises: { audio: false } })
    expect(box(w, 'audio').checked).toBe(false)
    expect(w.get('[data-testid=saved]').text()).toContain('Сохранено')
  })

  it('puts the box back when the save is refused', async () => {
    const w = await render()
    vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))

    await w.get('[data-testid=exercise-spelling]').setValue(false)
    await flushPromises()

    expect(box(w, 'spelling').checked).toBe(true)
    expect(w.get('[data-testid=settings-error]').text()).not.toBe('')
  })

  it('renders in Uzbek', async () => {
    const w = await render(settingsFixture({ interface_locale: 'uz' }))
    expect(w.get('[data-testid=exercises-card]').text()).toContain('Mashqlar')
    expect(w.get('[data-testid=exercises-card]').text()).toContain("So'zni yozish")
  })
})
