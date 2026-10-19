import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '../SettingsView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { getLocale, setLocale } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import type { LearnerSettings, MeResponse, SettingsPatch } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn(), back: undefined as undefined | (() => void), hideBack: vi.fn() }))

vi.mock('@/api', () => ({ api: { me: { get: vi.fn(), updateSettings: vi.fn() } } }))

vi.mock('@/stores/auth', () => {
  const auth = { ensure: state.ensure, learner: { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: true } }
  return { useAuthStore: () => auth }
})

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    showBackButton: (handler: () => void) => {
      state.back = handler
      return state.hideBack
    },
  }),
}))

const me = (settings: LearnerSettings = settingsFixture()): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: settings.interface_locale, onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'coming_soon', contact: null },
  settings,
})

const uzSettings = (): LearnerSettings =>
  settingsFixture({
    interface_locale: 'uz',
    options: {
      ...settingsFixture().options,
      timezones: [
        { id: 'Asia/Tashkent', label: 'Toshkent (UTC+5)' },
        { id: 'Europe/Moscow', label: 'Moskva (UTC+3)' },
      ],
    },
  })

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: SettingsView },
    ],
  })

async function render(settings: LearnerSettings = settingsFixture()) {
  vi.mocked(api.me.get).mockResolvedValue(me(settings))
  const router = makeRouter()
  await router.push('/settings')
  const wrapper = mount(SettingsView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

type Wrapper = Awaited<ReturnType<typeof render>>

const select = (w: Wrapper, id: string) => w.get(`[data-testid=${id}]`)
const value = (w: Wrapper, id: string): string => (select(w, id).element as HTMLSelectElement).value
const labels = (w: Wrapper, id: string): string[] => select(w, id).findAll('option').map((o) => o.text())

async function choose(w: Wrapper, id: string, newValue: string): Promise<void> {
  await select(w, id).setValue(newValue)
  await flushPromises()
}

describe('SettingsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
  })
  afterEach(() => setLocale('ru'))

  it('shows the current settings with the choices of the server', async () => {
    const w = await render(settingsFixture({ timezone: 'Europe/Moscow', reminder_time: '21:00', daily_goal: 40 }))

    expect(value(w, 'locale-select')).toBe('ru')
    expect(labels(w, 'locale-select')).toEqual(['🇷🇺 Русский', "🇺🇿 O'zbekcha"])
    expect(value(w, 'timezone-select')).toBe('Europe/Moscow')
    expect(labels(w, 'timezone-select')).toEqual(['Ташкент (UTC+5)', 'Москва (UTC+3)'])
    expect(value(w, 'reminder-select')).toBe('21:00')
    expect(labels(w, 'reminder-select')).toEqual(['08:00', '13:00', '19:00', '21:00', 'Без напоминаний'])
    expect(value(w, 'goal-select')).toBe('40')
    expect(labels(w, 'goal-select')).toEqual(['10 карточек', '20 карточек', '40 карточек'])
    expect(w.find('[data-testid=saved]').exists()).toBe(false)
  })

  it('shows reminders as off when there is no reminder time', async () => {
    const w = await render(settingsFixture({ reminder_time: null }))

    expect(value(w, 'reminder-select')).toBe('')
  })

  it('shows the time zone the learner already has even when the picker does not list it', async () => {
    const base = settingsFixture()
    const w = await render(
      settingsFixture({
        timezone: 'Asia/Kolkata',
        options: { ...base.options, timezones: [...base.options.timezones, { id: 'Asia/Kolkata', label: 'Asia/Kolkata (UTC+5:30)' }] },
      }),
    )

    expect(value(w, 'timezone-select')).toBe('Asia/Kolkata')
    expect(labels(w, 'timezone-select')).toContain('Asia/Kolkata (UTC+5:30)')
  })

  it('shows the active course and links to the catalog, also without a course', async () => {
    const withCourse = await render(settingsFixture({ active_course: { slug: 'english-a1', title: 'English A1' } }))
    expect(withCourse.get('[data-testid=course-title]').text()).toBe('English A1')
    expect(withCourse.get('[data-testid=catalog-link]').attributes('href')).toBe('/courses')

    const without = await render()
    expect(without.get('[data-testid=course-title]').text()).toBe('Курс не выбран')
    expect(without.get('[data-testid=catalog-link]').text()).toBe('Каталог курсов')
  })

  it.each([
    ['timezone-select', 'Europe/Moscow', { timezone: 'Europe/Moscow' }],
    ['reminder-select', '13:00', { reminder_time: '13:00' }],
    ['reminder-select', '', { reminder_time: null }],
    ['goal-select', '40', { daily_goal: 40 }],
  ] satisfies [string, string, SettingsPatch][])('saves a change of %s to %s', async (id, newValue, patch) => {
    const w = await render()
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture(patch)))

    await choose(w, id, newValue)

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[patch]])
    expect(value(w, id)).toBe(newValue)
    expect(w.get('[data-testid=saved]').text()).toContain('Сохранено')
  })

  it('switches the whole interface to the new language as soon as the change is saved', async () => {
    const w = await render()
    expect(w.get('h1').text()).toBe('Настройки')
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(uzSettings()))

    await choose(w, 'locale-select', 'uz')

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ interface_locale: 'uz' }]])
    expect(getLocale()).toBe('uz')
    expect(document.documentElement.lang).toBe('uz')
    expect(w.get('h1').text()).toBe('Sozlamalar')
    expect(labels(w, 'timezone-select')).toEqual(['Toshkent (UTC+5)', 'Moskva (UTC+3)'])
    expect(labels(w, 'reminder-select').at(-1)).toBe('Eslatmalarsiz')
    expect(labels(w, 'goal-select')[0]).toBe('10 ta kartochka')
    expect(w.get('[data-testid=saved]').text()).toContain('Saqlandi')
    // The learner kept in the auth store follows, so the next screens do not fall back to the old language.
    expect(useAuthStore().learner?.locale).toBe('uz')
  })

  it('keeps the language when a change is refused, shows the reason and puts the select back', async () => {
    const w = await render()
    vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('validation', 422, 'unknown', 'The timezone field is invalid.'))

    await choose(w, 'timezone-select', 'Europe/Moscow')

    expect(w.get('[data-testid=settings-error]').text()).toBe('Что-то пошло не так.')
    expect(value(w, 'timezone-select')).toBe('Asia/Tashkent')
    expect(w.find('[data-testid=saved]').exists()).toBe(false)
    expect(getLocale()).toBe('ru')
  })

  it('explains a network failure and clears the message on the next successful change', async () => {
    const w = await render()
    vi.mocked(api.me.updateSettings).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

    await choose(w, 'goal-select', '10')
    expect(w.get('[data-testid=settings-error]').text()).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(value(w, 'goal-select')).toBe('20')

    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture({ daily_goal: 10 })))
    await choose(w, 'goal-select', '10')
    expect(w.find('[data-testid=settings-error]').exists()).toBe(false)
    expect(value(w, 'goal-select')).toBe('10')
  })

  it('disables the controls while a change is being saved', async () => {
    const w = await render()
    let finish!: (m: MeResponse) => void
    vi.mocked(api.me.updateSettings).mockReturnValue(new Promise((resolve) => { finish = resolve }))

    await select(w, 'goal-select').setValue('40')
    expect((select(w, 'goal-select').element as HTMLSelectElement).disabled).toBe(true)
    expect((select(w, 'locale-select').element as HTMLSelectElement).disabled).toBe(true)

    finish(me(settingsFixture({ daily_goal: 40 })))
    await flushPromises()
    expect((select(w, 'goal-select').element as HTMLSelectElement).disabled).toBe(false)
  })

  it('offers a retry when the settings cannot be loaded', async () => {
    vi.mocked(api.me.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'internal', 'boom'))
    vi.mocked(api.me.get).mockResolvedValue(me())
    const router = makeRouter()
    const w = mount(SettingsView, { global: { plugins: [router] } })
    await flushPromises()

    expect(w.get('[data-testid=error]').text()).toContain('Сервер временно недоступен. Попробуйте позже.')

    await w.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid=error]').exists()).toBe(false)
    expect(value(w as unknown as Wrapper, 'goal-select')).toBe('20')
  })

  it('shows an auth failure as an error with a retry, without calling the API', async () => {
    state.ensure.mockRejectedValue(new Error('opened_outside_telegram'))
    const router = makeRouter()
    const w = mount(SettingsView, { global: { plugins: [router] } })
    await flushPromises()

    expect(w.get('[data-testid=error]').text()).toContain('Откройте приложение через Telegram.')
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('takes the Telegram back button to the Today screen and hides it on leave', async () => {
    const w = await render()
    expect(state.back).toBeTypeOf('function')

    state.back?.()
    await flushPromises()
    expect(w.router.currentRoute.value.name).toBe('today')

    w.unmount()
    expect(state.hideBack).toHaveBeenCalledOnce()
  })
})
