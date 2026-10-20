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
import type { LearnerSettings, MeResponse, OrganizationMembership, SettingsPatch } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn(), back: undefined as undefined | (() => void), hideBack: vi.fn() }))

vi.mock('@/api', () => ({
  api: { me: { get: vi.fn(), updateSettings: vi.fn(), revertTimezone: vi.fn() }, organizations: { list: vi.fn(), leave: vi.fn() } },
}))

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
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: SettingsView },
    ],
  })

const membership = (overrides: Partial<OrganizationMembership> = {}): OrganizationMembership => ({
  id: 17,
  name: 'Bright Center',
  seat_state: 'seated',
  joined_via: 'invite',
  joined_at: '2026-10-19T12:00:00+00:00',
  can_leave: true,
  ...overrides,
})

async function render(settings: LearnerSettings = settingsFixture(), memberships: OrganizationMembership[] = []) {
  vi.mocked(api.me.get).mockResolvedValue(me(settings))
  vi.mocked(api.organizations.list).mockResolvedValue({ organizations: memberships })
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

  it('links to the subscription screen', async () => {
    const w = await render()

    expect(w.get('[data-testid=billing-link]').attributes('href')).toBe('/billing')
    expect(w.get('[data-testid=billing-link]').text()).toBe('Подписка Pro')
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

  describe('time zone change limit (once in 24 h)', () => {
    afterEach(() => vi.useRealTimers())

    // 2026-10-20T10:00:00Z is 15:00 in Tashkent (UTC+5).
    const locked = (timezone = 'Asia/Tashkent') => settingsFixture({ timezone, timezone_change_available_at: '2026-10-20T10:00:00+00:00' })

    it('locks the time zone select with a hint while the next change is in the future, in the learner zone', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:00:00Z') })
      const w = await render(locked())

      expect((select(w, 'timezone-select').element as HTMLSelectElement).disabled).toBe(true)
      expect((select(w, 'goal-select').element as HTMLSelectElement).disabled).toBe(false)
      expect(w.get('[data-testid=timezone-locked]').text()).toMatch(
        /^Часовой пояс можно менять не чаще раза в сутки\. Следующая смена — 20 октября(?: в)?,? 15:00\.$/,
      )
    })

    it('says it in Uzbek and in the zone of the learner, not of the device', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:00:00Z') })
      const w = await render({ ...locked('Europe/Moscow'), interface_locale: 'uz' })

      expect(w.get('[data-testid=timezone-locked]').text()).toContain("Vaqt mintaqasini kuniga bir martadan ko'p o'zgartirib bo'lmaydi. Keyingi o'zgartirish — ")
      expect(w.get('[data-testid=timezone-locked]').text()).toContain('13:00')
    })

    it.each([[null], ['2026-10-19T11:59:59+00:00']])('does not lock the select when the available time is %s', async (at) => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:00:00Z') })
      const w = await render(settingsFixture({ timezone_change_available_at: at }))

      expect((select(w, 'timezone-select').element as HTMLSelectElement).disabled).toBe(false)
      expect(w.find('[data-testid=timezone-locked]').exists()).toBe(false)
    })

    it('on 422 timezone_change_too_soon puts the select back, takes the server settings and shows the same text', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:00:00Z') })
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValue(
        new ApiClientError('validation', 422, 'timezone_change_too_soon', 'The time zone can be changed once in 24 hours; next change at 2026-10-20T10:00:00+00:00.'),
      )
      vi.mocked(api.me.get).mockResolvedValue(me(locked()))

      await choose(w, 'timezone-select', 'Europe/Moscow')

      expect(value(w, 'timezone-select')).toBe('Asia/Tashkent')
      expect(w.get('[data-testid=settings-error]').text()).toBe(w.get('[data-testid=timezone-locked]').text())
      expect(w.get('[data-testid=settings-error]').text()).toContain('Следующая смена — 20 октября')
      expect((select(w, 'timezone-select').element as HTMLSelectElement).disabled).toBe(true)
      expect(w.find('[data-testid=saved]').exists()).toBe(false)
    })

    it('on 422 timezone_change_too_soon falls back to the text without a date when the settings cannot be reloaded', async () => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('validation', 422, 'timezone_change_too_soon', 'x'))
      vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await choose(w, 'timezone-select', 'Europe/Moscow')

      expect(w.get('[data-testid=settings-error]').text()).toBe('Часовой пояс можно менять не чаще раза в сутки.')
      expect(value(w, 'timezone-select')).toBe('Asia/Tashkent')
    })
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

  describe('my centers', () => {
    it('shows nothing for a learner who is in no center', async () => {
      const w = await render()

      expect(w.find('[data-testid=orgs-card]').exists()).toBe(false)
      expect(vi.mocked(api.organizations.list)).toHaveBeenCalledOnce()
    })

    it('lists the centers with the state of the seat', async () => {
      const w = await render(settingsFixture(), [
        membership(),
        membership({ id: 18, name: 'Other Center', seat_state: 'waitlisted' }),
      ])

      const names = w.findAll('[data-testid=org-name]').map((n) => n.text())
      const states = w.findAll('[data-testid=org-state]').map((n) => n.text())
      expect(names).toEqual(['Bright Center', 'Other Center'])
      expect(states).toEqual(['Pro от центра активен', 'Свободных мест нет — вы в очереди'])
    })

    it('explains a center joined through a group instead of offering to leave it', async () => {
      const w = await render(settingsFixture(), [membership({ joined_via: 'group', can_leave: false })])

      expect(w.find('[data-testid=org-leave]').exists()).toBe(false)
      expect(w.get('[data-testid=org-via-group]').text()).toContain('через группу')
    })

    it('asks for confirmation, then leaves and shows the refreshed list', async () => {
      const w = await render(settingsFixture(), [membership(), membership({ id: 18, name: 'Other Center' })])
      vi.mocked(api.organizations.leave).mockResolvedValue({ organizations: [membership({ id: 18, name: 'Other Center' })] })

      await w.get('[data-testid=org-17] [data-testid=org-leave]').trigger('click')
      expect(w.get('[data-testid=org-confirm]').text()).toContain('Покинуть центр «Bright Center»?')
      expect(vi.mocked(api.organizations.leave)).not.toHaveBeenCalled()

      await w.get('[data-testid=org-leave-yes]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.organizations.leave).mock.calls).toEqual([[17]])
      expect(w.findAll('[data-testid=org-name]').map((n) => n.text())).toEqual(['Other Center'])
      expect(w.find('[data-testid=org-confirm]').exists()).toBe(false)
    })

    it('keeps the center when the learner cancels the confirmation', async () => {
      const w = await render(settingsFixture(), [membership()])

      await w.get('[data-testid=org-leave]').trigger('click')
      await w.get('[data-testid=org-leave-no]').trigger('click')

      expect(vi.mocked(api.organizations.leave)).not.toHaveBeenCalled()
      expect(w.find('[data-testid=org-confirm]').exists()).toBe(false)
      expect(w.find('[data-testid=org-leave]').exists()).toBe(true)
    })

    it('shows why leaving was refused and takes the list from the server again', async () => {
      const w = await render(settingsFixture(), [membership()])
      vi.mocked(api.organizations.leave).mockRejectedValue(new ApiClientError('conflict', 409, 'membership_via_group', 'x'))
      vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [membership({ joined_via: 'group', can_leave: false })] })

      await w.get('[data-testid=org-leave]').trigger('click')
      await w.get('[data-testid=org-leave-yes]').trigger('click')
      await flushPromises()

      expect(w.get('[data-testid=orgs-error]').text()).toContain('через группу')
      expect(w.find('[data-testid=org-leave]').exists()).toBe(false)
      expect(w.find('[data-testid=org-via-group]').exists()).toBe(true)
    })

    it('answers in Uzbek when the interface is Uzbek', async () => {
      const w = await render(uzSettings(), [membership()])
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(uzSettings()))
      await choose(w, 'locale-select', 'uz')

      expect(w.get('[data-testid=orgs-card]').text()).toContain('Mening markazlarim')
      expect(w.get('[data-testid=org-state]').text()).toBe('Markaz bergan Pro faol')
    })

    it('does not hide the settings when the list cannot be loaded', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me())
      vi.mocked(api.organizations.list).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const router = makeRouter()
      await router.push('/settings')
      const w = mount(SettingsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(w.find('[data-testid=locale-select]').exists()).toBe(true)
      expect(w.find('[data-testid=orgs-card]').exists()).toBe(false)
    })
  })


  describe('undo of a time zone change (10 minutes)', () => {
    afterEach(() => vi.useRealTimers())

    const undoable = (timezone = 'Europe/Moscow') => settingsFixture({ timezone, timezone_undo_available_until: '2026-10-19T12:10:00+00:00' })

    it('shows no button without an undo mark or after its time', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:10:00Z') })

      expect((await render(settingsFixture())).find('[data-testid=timezone-undo]').exists()).toBe(false)
      expect((await render(undoable())).find('[data-testid=timezone-undo]').exists()).toBe(false)
    })

    it('shows a button with the time left, counting down', async () => {
      vi.useFakeTimers({ now: new Date('2026-10-19T12:05:30Z') })
      const w = await render(undoable())

      expect(w.get('[data-testid=timezone-undo]').text()).toBe('Отменить смену пояса (4:30)')

      await vi.advanceTimersByTimeAsync(31_000)
      expect(w.get('[data-testid=timezone-undo]').text()).toBe('Отменить смену пояса (3:59)')

      await vi.advanceTimersByTimeAsync(240_000)
      expect(w.find('[data-testid=timezone-undo]').exists()).toBe(false)
    })

    it('is in Uzbek for an Uzbek learner', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:05:00Z') })
      const w = await render({ ...undoable(), interface_locale: 'uz' })

      expect(w.get('[data-testid=timezone-undo]').text()).toBe("Mintaqa o'zgarishini bekor qilish (5:00)")
    })

    it('reverts through the API and takes the server settings (the zone is back, the button gone)', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:05:00Z') })
      const w = await render(undoable())
      vi.mocked(api.me.revertTimezone).mockResolvedValue(me(settingsFixture({ timezone: 'Asia/Tashkent' })))

      await w.get('[data-testid=timezone-undo]').trigger('click')
      await flushPromises()

      expect(api.me.revertTimezone).toHaveBeenCalledTimes(1)
      expect(value(w, 'timezone-select')).toBe('Asia/Tashkent')
      expect(w.find('[data-testid=timezone-undo]').exists()).toBe(false)
      expect(w.find('[data-testid=saved]').exists()).toBe(true)
    })

    it('on 422 timezone_revert_unavailable reloads the settings, drops the button and says why', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:05:00Z') })
      const w = await render(undoable())
      vi.mocked(api.me.revertTimezone).mockRejectedValue(new ApiClientError('validation', 422, 'timezone_revert_unavailable', 'x'))
      vi.mocked(api.me.get).mockResolvedValue(me(settingsFixture({ timezone: 'Europe/Moscow' })))

      await w.get('[data-testid=timezone-undo]').trigger('click')
      await flushPromises()

      expect(w.find('[data-testid=timezone-undo]').exists()).toBe(false)
      expect(w.get('[data-testid=settings-error]').text()).toContain('Отменить смену часового пояса уже нельзя')
      expect(value(w, 'timezone-select')).toBe('Europe/Moscow')
    })
  })
})
