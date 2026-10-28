import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '../SettingsView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
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
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
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

const toggle = (w: Wrapper, key: string) => w.get(`[data-testid=exercise-${key}]`)
const isOn = (w: Wrapper, key: string): boolean => toggle(w, key).attributes('aria-checked') === 'true'

describe('SettingsView — exercises', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.ensure.mockResolvedValue(undefined)
    useToast().hide()
  })
  afterEach(() => {
    setLocale('ru')
    useToast().hide()
  })

  it('shows one switch per exercise type with the server values', async () => {
    const w = await render(settingsFixture({ exercises: { reverse: true, audio: false, spelling: true, gap_fill: false } }))

    expect(isOn(w, 'reverse')).toBe(true)
    expect(isOn(w, 'audio')).toBe(false)
    expect(isOn(w, 'spelling')).toBe(true)
    expect(isOn(w, 'gap_fill')).toBe(false)
    for (const key of ['reverse', 'audio', 'spelling', 'gap_fill']) {
      expect(toggle(w, key).attributes('role'), key).toBe('switch')
    }
    expect(w.get('[data-testid=exercises-card]').text()).toContain('Написание слова')
  })

  it('names them plainly, in display order, and marks the two that need Pro with a chip', async () => {
    const w = await render()

    const card = w.get('[data-testid=exercises-card]')
    // The chip is a span of its own, so its text follows the name without a space.
    expect(card.findAll('[data-testid^=exercise-row-]').map((row) => row.text().replace(/Pro$/, ''))).toEqual([
      'Перевод → слово',
      'На слух',
      'Написание слова',
      'Пропуск в примере',
    ])
    for (const key of ['reverse', 'audio']) {
      expect(card.get(`[data-testid=exercise-row-${key}]`).find('[data-testid=exercise-pro]').exists(), key).toBe(false)
    }
    for (const key of ['spelling', 'gap_fill']) {
      const chip = card.get(`[data-testid=exercise-row-${key}]`).get('[data-testid=exercise-pro]')
      expect(chip.classes(), key).toEqual(expect.arrayContaining(['chip', 'chip-accent']))
      expect(chip.text()).toBe('Pro')
    }
  })

  it('says under the group that the «word → translation» cards are always on', async () => {
    const w = await render()

    expect(w.get('[data-testid=exercises-card]').text()).toContain('Карточки «слово → перевод» включены всегда')
    expect(w.get('[data-testid=exercises-card] h2').text()).toBe('Упражнения')
  })

  it('names each switch for a screen reader', async () => {
    const w = await render()

    expect(toggle(w, 'audio').attributes('aria-label')).toBe('На слух')
    expect(toggle(w, 'gap_fill').attributes('aria-label')).toBe('Пропуск в примере')
  })

  it('saves a switch as a partial exercises patch, takes the server answer and says «Saved»', async () => {
    const w = await render()
    vi.mocked(api.me.updateSettings).mockResolvedValue(
      me(settingsFixture({ exercises: { reverse: true, audio: false, spelling: true, gap_fill: true } })),
    )

    await toggle(w, 'audio').trigger('click')
    await flushPromises()

    expect(api.me.updateSettings).toHaveBeenCalledWith({ exercises: { audio: false } })
    expect(isOn(w, 'audio')).toBe(false)
    expect(useToast().toast.value?.text).toBe('Сохранено')
  })

  it('switches an exercise on with the opposite value', async () => {
    const w = await render(settingsFixture({ exercises: { reverse: true, audio: true, spelling: false, gap_fill: true } }))
    vi.mocked(api.me.updateSettings).mockResolvedValue(
      me(settingsFixture({ exercises: { reverse: true, audio: true, spelling: true, gap_fill: true } })),
    )

    await toggle(w, 'spelling').trigger('click')
    await flushPromises()

    expect(api.me.updateSettings).toHaveBeenCalledWith({ exercises: { spelling: true } })
    expect(isOn(w, 'spelling')).toBe(true)
  })

  it('leaves the switch where the server has it when the save is refused, with the reason on top', async () => {
    const w = await render()
    vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))

    await toggle(w, 'spelling').trigger('click')
    await flushPromises()

    expect(isOn(w, 'spelling')).toBe(true)
    expect(w.get('[data-testid=settings-error]').text()).not.toBe('')
    expect(useToast().toast.value).toBeNull()
  })

  it('ignores a tap while a save is in flight', async () => {
    const w = await render()
    let finish!: (m: MeResponse) => void
    vi.mocked(api.me.updateSettings).mockReturnValue(new Promise((resolve) => { finish = resolve }))

    await toggle(w, 'audio').trigger('click')
    expect(toggle(w, 'reverse').attributes('disabled')).toBeDefined()
    await toggle(w, 'reverse').trigger('click')
    finish(me(settingsFixture({ exercises: { reverse: true, audio: false, spelling: true, gap_fill: true } })))
    await flushPromises()

    expect(api.me.updateSettings).toHaveBeenCalledTimes(1)
    expect(toggle(w, 'reverse').attributes('disabled')).toBeUndefined()
  })

  it('renders in Uzbek', async () => {
    const w = await render(settingsFixture({ interface_locale: 'uz' }))

    expect(w.get('[data-testid=exercises-card]').text()).toContain('Mashqlar')
    expect(w.get('[data-testid=exercises-card]').text()).toContain("So'zni yozish")
    expect(w.get('[data-testid=exercises-card]').text()).toContain("«So'z → tarjima» kartochkalari doim yoqilgan")
    expect(w.get('[data-testid=exercise-row-reverse]').text()).toBe("Tarjima → so'z")
    expect(w.get('[data-testid=exercise-row-audio]').text()).toBe('Eshitib')
  })
})
