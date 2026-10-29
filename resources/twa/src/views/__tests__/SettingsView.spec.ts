import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '../SettingsView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import { getLocale, setLocale } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useSettingsStore } from '@/stores/settings'
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
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
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

const box = (w: Wrapper, id: string) => w.get(`[data-testid=${id}]`)
const select = (w: Wrapper, id: string) => box(w, id).element as HTMLSelectElement
const value = (w: Wrapper, id: string): string => select(w, id).value
const labels = (w: Wrapper, id: string): string[] => box(w, id).findAll('option').map((o) => o.text())
/** The segments of a `SegmentedControl` in the container with the id: their texts and which one is selected. */
const segments = (w: Wrapper, id: string): string[] => box(w, id).findAll('[role=radio]').map((r) => r.text())
const selectedSegment = (w: Wrapper, id: string): string | undefined =>
  box(w, id).findAll('[role=radio]').find((r) => r.attributes('aria-checked') === 'true')?.text()
const isOn = (w: Wrapper, id: string): boolean => box(w, id).attributes('aria-checked') === 'true'
const toastText = (): string | undefined => useToast().toast.value?.text

async function choose(w: Wrapper, id: string, newValue: string): Promise<void> {
  await box(w, id).setValue(newValue)
  await flushPromises()
}

async function tap(w: Wrapper, id: string): Promise<void> {
  await box(w, id).trigger('click')
  await flushPromises()
}

describe('SettingsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
    useToast().hide()
  })
  afterEach(() => {
    setLocale('ru')
    useToast().hide()
  })

  describe('what it shows', () => {
    it('shows the current settings with the choices of the server', async () => {
      const w = await render(settingsFixture({ timezone: 'Europe/Moscow', reminder_time: '21:00', daily_goal: 40 }))

      expect(selectedSegment(w, 'locale-select')).toBe('🇷🇺 Русский')
      expect(segments(w, 'locale-select')).toEqual(['🇷🇺 Русский', "🇺🇿 O'zbekcha"])
      expect(value(w, 'timezone-select')).toBe('Europe/Moscow')
      expect(labels(w, 'timezone-select')).toEqual(['Ташкент (UTC+5)', 'Москва (UTC+3)'])
      expect(value(w, 'reminder-select')).toBe('21:00')
      expect(labels(w, 'reminder-select')).toEqual(['08:00', '13:00', '19:00', '21:00', 'Выключены'])
      expect(selectedSegment(w, 'goal-select')).toBe('40')
      expect(segments(w, 'goal-select')).toEqual(['10', '20', '40'])
      expect(toastText()).toBeUndefined()
    })

    it('has a «Settings» page header and no old «Saved» line', async () => {
      const w = await render()

      expect(w.get('h1').text()).toBe('Настройки')
      expect(w.find('[data-testid=saved]').exists()).toBe(false)
    })

    it('groups the settings as Telegram does: a label above, a footnote under the group', async () => {
      const w = await render(settingsFixture(), [membership()])

      const sections = w.findAll('h2.section-label').map((h) => h.text())
      expect(sections).toEqual(['Обучение', 'Упражнения', 'Напоминания', 'Язык и регион', 'Лиги', 'Мои центры'])
      const text = w.text()
      expect(text).toContain('карточек в день')
      expect(text).toContain('Карточки «слово → перевод» включены всегда')
      expect(text).toContain('Время — по вашему часовому поясу.')
      expect(text).toContain('Выход из лиг действует со следующей недели')
    })

    it('explains the leagues switch in plain words: from next week, the current week stays, and it can be switched on again any time', async () => {
      const w = await render()

      const hint = w.findAll('p').map((p) => p.text()).find((text) => text.startsWith('Выход из лиг'))
      expect(hint).toBe('Выход из лиг действует со следующей недели: текущая неделя остаётся как есть. Включить лиги обратно можно в любой момент.')
      expect(w.text()).not.toContain('размещени') // the word of the code, not of a learner
    })

    it('says the same in Uzbek', async () => {
      const w = await render(settingsFixture({ interface_locale: 'uz' }))

      expect(w.text()).toContain("Ligalardan chiqish keyingi haftadan boshlab amal qiladi: joriy hafta o'zgarishsiz qoladi. Ligalarni istalgan vaqtda qayta yoqish mumkin.")
      expect(w.text()).not.toContain('joylashtirish')
    })

    it('drops the old buttons: the course row and the Profile tab replace «Course catalog» and «Subscription»', async () => {
      const w = await render()

      expect(w.find('[data-testid=catalog-link]').exists()).toBe(false)
      expect(w.find('[data-testid=billing-link]').exists()).toBe(false)
      expect(w.text()).not.toContain('Каталог курсов')
      expect(w.text()).not.toContain('Подписка Pro')
    })

    it('shows the daily goal as a segmented control with the unit under it', async () => {
      const w = await render()

      const goal = box(w, 'goal-row')
      expect(goal.text()).toContain('Цель на день')
      expect(goal.find('[role=radiogroup]').attributes('aria-label')).toBe('Цель на день')
      expect(box(w, 'goal-select').findAll('select')).toHaveLength(0)
      expect(w.get('[data-testid=goal-10]').text()).toBe('10')
    })

    it('puts more than four daily goals into a select', async () => {
      const base = settingsFixture()
      const w = await render(settingsFixture({ daily_goal: 30, options: { ...base.options, daily_goals: [10, 20, 30, 40, 50] } }))

      expect(box(w, 'goal-select').findAll('[role=radio]')).toHaveLength(0)
      const goal = box(w, 'goal-select').get('select')
      expect(goal.classes()).toContain('field')
      expect((goal.element as HTMLSelectElement).value).toBe('30')
      expect(goal.findAll('option').map((o) => o.text())).toEqual(['10', '20', '30', '40', '50'])
      expect(w.text()).toContain('карточек в день')
    })

    it('keeps four daily goals as segments', async () => {
      const base = settingsFixture()
      const w = await render(settingsFixture({ options: { ...base.options, daily_goals: [5, 10, 20, 40] } }))

      expect(segments(w, 'goal-select')).toEqual(['5', '10', '20', '40'])
    })

    it('shows the league participation as a switch that is on unless the learner opted out', async () => {
      const on = await render(settingsFixture({ leagues_opt_out: false }))
      expect(on.get('[data-testid=leagues-toggle]').attributes('role')).toBe('switch')
      expect(isOn(on, 'leagues-toggle')).toBe(true)
      expect(on.text()).toContain('Участвовать в недельных лигах')

      const off = await render(settingsFixture({ leagues_opt_out: true }))
      expect(isOn(off, 'leagues-toggle')).toBe(false)
    })

    it('shows reminders as off when there is no reminder time', async () => {
      const w = await render(settingsFixture({ reminder_time: null }))

      expect(value(w, 'reminder-select')).toBe('')
    })

    it('has the reminder and the time zone as values on the right of their rows', async () => {
      const w = await render()

      for (const id of ['reminder-select', 'timezone-select']) {
        expect(box(w, id).classes(), id).toEqual(expect.arrayContaining(['field', 'field-inline']))
      }
      expect(w.text()).toContain('Время напоминания')
      expect(w.text()).toContain('Часовой пояс')
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

    it('shows the main course in a row that leads to the courses, also without a course', async () => {
      const withCourse = await render(settingsFixture({ active_course: { slug: 'english-a1', title: 'English A1' } }))
      const row = withCourse.get('[data-testid=course-card]')
      expect(row.element.tagName).toBe('A')
      expect(row.attributes('href')).toBe('/courses')
      expect(row.text()).toContain('Основной курс')
      expect(withCourse.get('[data-testid=course-title]').text()).toBe('English A1')

      const without = await render()
      expect(without.get('[data-testid=course-title]').text()).toBe('Не выбран')
      expect(without.get('[data-testid=course-card]').attributes('href')).toBe('/courses')
    })

    it('keeps the row title on its line and gives a long course name two lines at most, cut after that', async () => {
      const w = await render(settingsFixture({ active_course: { slug: 'x', title: 'Английский A2: повседневные темы для путешествий и работы' } }))

      const row = w.get('[data-testid=course-card]')
      expect(row.get('span').classes()).toContain('shrink-0') // «Main course» never wraps
      expect(row.get('span').text()).toBe('Основной курс')
      expect(w.get('[data-testid=course-title]').classes()).toEqual(expect.arrayContaining(['line-clamp-2', 'min-w-0', 'flex-1', 'text-right']))
      expect(w.get('[data-testid=course-title]').text()).toBe('Английский A2: повседневные темы для путешествий и работы')
      expect(row.find('svg').exists()).toBe(true) // the chevron
    })
  })

  describe('saving', () => {
    // [control to touch, how, patch the screen has to send]
    it.each([
      ['timezone-select', 'Europe/Moscow', { timezone: 'Europe/Moscow' }],
      ['reminder-select', '13:00', { reminder_time: '13:00' }],
      ['reminder-select', '', { reminder_time: null }],
    ] satisfies [string, string, SettingsPatch][])('saves a change of the select %s to "%s" and says «Saved»', async (id, newValue, patch) => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture(patch)))

      await choose(w, id, newValue)

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[patch]])
      expect(value(w, id)).toBe(newValue)
      expect(toastText()).toBe('Сохранено')
    })

    it('saves a tap on a daily goal segment', async () => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture({ daily_goal: 40 })))

      await tap(w, 'goal-40')

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ daily_goal: 40 }]])
      expect(selectedSegment(w, 'goal-select')).toBe('40')
      expect(toastText()).toBe('Сохранено')
    })

    it('does not send anything for a tap on the goal that is already chosen', async () => {
      const w = await render()

      await tap(w, 'goal-20')

      expect(api.me.updateSettings).not.toHaveBeenCalled()
    })

    it('saves a change of the goal in the select variant', async () => {
      const base = settingsFixture()
      const options = { ...base.options, daily_goals: [10, 20, 30, 40, 50] }
      const w = await render(settingsFixture({ options }))
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture({ options, daily_goal: 50 })))

      await box(w, 'goal-select').get('select').setValue('50')
      await flushPromises()

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ daily_goal: 50 }]])
      expect((box(w, 'goal-select').get('select').element as HTMLSelectElement).value).toBe('50')
    })

    it('puts the goal select back when the change is refused', async () => {
      const base = settingsFixture()
      const w = await render(settingsFixture({ options: { ...base.options, daily_goals: [10, 20, 30, 40, 50] } }))
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await box(w, 'goal-select').get('select').setValue('50')
      await flushPromises()

      expect((box(w, 'goal-select').get('select').element as HTMLSelectElement).value).toBe('20')
    })

    it('opts out of the leagues with the switch and back in', async () => {
      const w = await render(settingsFixture({ leagues_opt_out: false }))
      vi.mocked(api.me.updateSettings).mockResolvedValueOnce(me(settingsFixture({ leagues_opt_out: true })))

      await tap(w, 'leagues-toggle')
      expect(isOn(w, 'leagues-toggle')).toBe(false)

      vi.mocked(api.me.updateSettings).mockResolvedValueOnce(me(settingsFixture({ leagues_opt_out: false })))
      await tap(w, 'leagues-toggle')

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ leagues_opt_out: true }], [{ leagues_opt_out: false }]])
      expect(isOn(w, 'leagues-toggle')).toBe(true)
    })

    it('lets a learner who opted out take part again', async () => {
      const w = await render(settingsFixture({ leagues_opt_out: true }))
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture({ leagues_opt_out: false })))

      await tap(w, 'leagues-toggle')

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ leagues_opt_out: false }]])
      expect(isOn(w, 'leagues-toggle')).toBe(true)
    })

    it('switches the whole interface to the new language as soon as the change is saved, and says «Saved» in it', async () => {
      const w = await render()
      expect(w.get('h1').text()).toBe('Настройки')
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(uzSettings()))

      await tap(w, 'locale-uz')

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ interface_locale: 'uz' }]])
      expect(getLocale()).toBe('uz')
      expect(document.documentElement.lang).toBe('uz')
      expect(w.get('h1').text()).toBe('Sozlamalar')
      expect(selectedSegment(w, 'locale-select')).toBe("🇺🇿 O'zbekcha")
      expect(labels(w, 'timezone-select')).toEqual(['Toshkent (UTC+5)', 'Moskva (UTC+3)'])
      expect(labels(w, 'reminder-select').at(-1)).toBe("O'chirilgan")
      expect(w.text()).toContain('kuniga nechta kartochka')
      expect(toastText()).toBe('Saqlandi')
      // The learner kept in the auth store follows, so the next screens do not fall back to the old language.
      expect(useAuthStore().learner?.locale).toBe('uz')
    })

    it('keeps the language when a change is refused, tells the reason in a toast and puts the select back', async () => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('validation', 422, 'unknown', 'The timezone field is invalid.'))

      await choose(w, 'timezone-select', 'Europe/Moscow')

      expect(toastText()).toBe('Что-то пошло не так.')
      expect(value(w, 'timezone-select')).toBe('Asia/Tashkent')
      expect(getLocale()).toBe('ru')
    })

    it('puts the reminder select back when the change is refused, and the segments stay where the server has them', async () => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await choose(w, 'reminder-select', '08:00')
      expect(value(w, 'reminder-select')).toBe('19:00')

      await tap(w, 'goal-40')
      expect(selectedSegment(w, 'goal-select')).toBe('20')
      await tap(w, 'locale-uz')
      expect(selectedSegment(w, 'locale-select')).toBe('🇷🇺 Русский')
      expect(getLocale()).toBe('ru')
    })

    it('tells a refusal in a toast, not in an alert on top of the page: the page is long and the alert would be out of sight', async () => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await tap(w, 'goal-40')

      expect(toastText()).toBe('Нет связи с сервером. Попробуйте ещё раз.')
      expect(w.find('[data-testid=settings-error]').exists()).toBe(false)
      expect(w.find('[role=alert]').exists()).toBe(false)
      expect(useSettingsStore().errorMessage).toBe('') // told once: the same failure again is news again
    })

    it('tells the refusal of the leagues switch at the bottom of the page the same way, and puts the switch back', async () => {
      const w = await render(settingsFixture({ leagues_opt_out: false }))
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await tap(w, 'leagues-toggle')

      expect(isOn(w, 'leagues-toggle')).toBe(true)
      expect(toastText()).toBe('Нет связи с сервером. Попробуйте ещё раз.')
      expect(w.find('[data-testid=settings-error]').exists()).toBe(false)
    })

    it('keeps a refusal on screen for 4 seconds, longer than «Saved»', async () => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await tap(w, 'goal-40')
      await vi.advanceTimersByTimeAsync(3999)
      expect(toastText()).toBe('Нет связи с сервером. Попробуйте ещё раз.')

      await vi.advanceTimersByTimeAsync(1)
      expect(toastText()).toBeUndefined()
      vi.useRealTimers()
    })

    it('explains a network failure, and the next successful change says «Saved» instead', async () => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await tap(w, 'goal-10')
      expect(toastText()).toBe('Нет связи с сервером. Попробуйте ещё раз.')
      expect(selectedSegment(w, 'goal-select')).toBe('20')

      vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture({ daily_goal: 10 })))
      await tap(w, 'goal-10')
      expect(toastText()).toBe('Сохранено')
      expect(selectedSegment(w, 'goal-select')).toBe('10')
    })

    it('shows the message of a screen that has no data in its error state, not in a toast', async () => {
      vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))
      vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [] })
      const router = makeRouter()
      await router.push('/settings')
      const w = mount(SettingsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(w.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
      expect(toastText()).toBeUndefined()
      expect(useSettingsStore().errorMessage).not.toBe('') // kept for the retry
    })

    it('disables the controls while a change is being saved', async () => {
      const w = await render()
      let finish!: (m: MeResponse) => void
      vi.mocked(api.me.updateSettings).mockReturnValue(new Promise((resolve) => { finish = resolve }))

      await box(w, 'goal-40').trigger('click')
      for (const id of ['goal-10', 'goal-20', 'goal-40', 'locale-ru', 'locale-uz', 'leagues-toggle', 'exercise-audio']) {
        expect(box(w, id).attributes('disabled'), id).toBeDefined()
      }
      expect(select(w, 'timezone-select').disabled).toBe(true)
      expect(select(w, 'reminder-select').disabled).toBe(true)

      finish(me(settingsFixture({ daily_goal: 40 })))
      await flushPromises()
      expect(box(w, 'goal-10').attributes('disabled')).toBeUndefined()
      expect(select(w, 'timezone-select').disabled).toBe(false)
    })
  })

  describe('loading and failing', () => {
    it('shows a skeleton list while the settings are loading', async () => {
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>(() => {}))
      vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [] })
      const router = makeRouter()
      const w = mount(SettingsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(w.find('[data-testid=spinner]').exists()).toBe(true)
      expect(w.find('[data-testid=goal-select]').exists()).toBe(false)
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
      expect(selectedSegment(w as unknown as Wrapper, 'goal-select')).toBe('20')
    })

    it('shows an auth failure as an error with a retry, without calling the API', async () => {
      state.ensure.mockRejectedValue(new Error('opened_outside_telegram'))
      const router = makeRouter()
      const w = mount(SettingsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(w.get('[data-testid=error]').text()).toContain('Откройте приложение через Telegram.')
      expect(api.me.get).not.toHaveBeenCalled()
    })
  })

  describe('time zone change limit (once in 24 h)', () => {
    afterEach(() => vi.useRealTimers())

    // 2026-10-20T10:00:00Z is 15:00 in Tashkent (UTC+5).
    const locked = (timezone = 'Asia/Tashkent') => settingsFixture({ timezone, timezone_change_available_at: '2026-10-20T10:00:00+00:00' })

    it('locks the time zone select with a hint while the next change is in the future, in the learner zone', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:00:00Z') })
      const w = await render(locked())

      expect(select(w, 'timezone-select').disabled).toBe(true)
      expect(box(w, 'goal-10').attributes('disabled')).toBeUndefined()
      expect(w.get('[data-testid=timezone-locked]').text()).toMatch(
        /^Часовой пояс можно менять не чаще раза в сутки\. Следующая смена — 20 октября(?: в)?,? 15:00\.$/,
      )
    })

    it('says it under the time zone row, as a footnote', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:00:00Z') })
      const w = await render(locked())

      const row = box(w, 'timezone-row')
      expect(row.get('[data-testid=timezone-locked]').classes()).toContain('text-tg-hint')
      expect(row.text()).toContain('Часовой пояс')
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

      expect(select(w, 'timezone-select').disabled).toBe(false)
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
      expect(toastText()).toBe(w.get('[data-testid=timezone-locked]').text())
      expect(toastText()).toContain('Следующая смена — 20 октября')
      expect(select(w, 'timezone-select').disabled).toBe(true)
      expect(w.find('[data-testid=settings-error]').exists()).toBe(false)
    })

    it('on 422 timezone_change_too_soon falls back to the text without a date when the settings cannot be reloaded', async () => {
      const w = await render()
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('validation', 422, 'timezone_change_too_soon', 'x'))
      vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'Failed to fetch'))

      await choose(w, 'timezone-select', 'Europe/Moscow')

      expect(toastText()).toBe('Часовой пояс можно менять не чаще раза в сутки.')
      expect(value(w, 'timezone-select')).toBe('Asia/Tashkent')
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

    it('shows a small tinted button with the time left, counting down', async () => {
      vi.useFakeTimers({ now: new Date('2026-10-19T12:05:30Z') })
      const w = await render(undoable())

      expect(w.get('[data-testid=timezone-undo]').text()).toBe('Отменить смену пояса (4:30)')
      expect(w.get('[data-testid=timezone-undo]').classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-sm']))
      expect(box(w, 'timezone-row').find('[data-testid=timezone-undo]').exists()).toBe(true)

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

    it('stops the countdown when the screen is left', async () => {
      vi.useFakeTimers({ now: new Date('2026-10-19T12:05:00Z') })
      const w = await render(undoable())
      const clear = vi.spyOn(globalThis, 'clearInterval')

      w.unmount()

      expect(clear).toHaveBeenCalled()
      clear.mockRestore()
    })

    it('reverts through the API, takes the server settings (the zone is back, the button gone) and says «Saved»', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:05:00Z') })
      const w = await render(undoable())
      vi.mocked(api.me.revertTimezone).mockResolvedValue(me(settingsFixture({ timezone: 'Asia/Tashkent' })))

      await w.get('[data-testid=timezone-undo]').trigger('click')
      await flushPromises()

      expect(api.me.revertTimezone).toHaveBeenCalledTimes(1)
      expect(value(w, 'timezone-select')).toBe('Asia/Tashkent')
      expect(w.find('[data-testid=timezone-undo]').exists()).toBe(false)
      expect(toastText()).toBe('Сохранено')
    })

    it('on 422 timezone_revert_unavailable reloads the settings, drops the button and says why', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-19T12:05:00Z') })
      const w = await render(undoable())
      vi.mocked(api.me.revertTimezone).mockRejectedValue(new ApiClientError('validation', 422, 'timezone_revert_unavailable', 'x'))
      vi.mocked(api.me.get).mockResolvedValue(me(settingsFixture({ timezone: 'Europe/Moscow' })))

      await w.get('[data-testid=timezone-undo]').trigger('click')
      await flushPromises()

      expect(w.find('[data-testid=timezone-undo]').exists()).toBe(false)
      expect(toastText()).toContain('Отменить смену часового пояса уже нельзя')
      expect(value(w, 'timezone-select')).toBe('Europe/Moscow')
    })
  })

  describe('navigation', () => {
    it('takes the Telegram back button to the Profile and hides it on leave', async () => {
      const w = await render()
      expect(state.back).toBeTypeOf('function')

      state.back?.()
      await flushPromises()
      expect(w.router.currentRoute.value.name).toBe('profile')

      w.unmount()
      expect(state.hideBack).toHaveBeenCalledOnce()
    })
  })

  describe('my centers', () => {
    it('shows nothing for a learner who is in no center', async () => {
      const w = await render()

      expect(w.find('[data-testid=orgs-card]').exists()).toBe(false)
      expect(vi.mocked(api.organizations.list)).toHaveBeenCalledOnce()
    })

    it('lists the centers with the state of the seat as a chip', async () => {
      const w = await render(settingsFixture(), [
        membership(),
        membership({ id: 18, name: 'Other Center', seat_state: 'waitlisted' }),
      ])

      const names = w.findAll('[data-testid=org-name]').map((n) => n.text())
      const states = w.findAll('[data-testid=org-state]')
      expect(names).toEqual(['Bright Center', 'Other Center'])
      expect(states.map((n) => n.text())).toEqual(['Pro от центра', 'В очереди на Pro'])
      expect(states[0]?.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
      expect(states[1]?.classes()).toEqual(expect.arrayContaining(['chip', 'chip-warning']))
      expect(w.get('[data-testid=orgs-card] h2').text()).toBe('Мои центры')
    })

    it('tells a learner in the queue what it means and when Pro comes, and tells nobody else', async () => {
      const w = await render(settingsFixture(), [
        membership(),
        membership({ id: 18, name: 'Other Center', seat_state: 'waitlisted' }),
      ])

      expect(w.find('[data-testid=org-17] [data-testid=org-waitlisted-hint]').exists()).toBe(false)
      const hint = w.get('[data-testid=org-18] [data-testid=org-waitlisted-hint]')
      expect(hint.text()).toBe('В центре пока нет свободных мест. Pro от центра включится, когда место освободится.')
      expect(hint.classes()).toContain('text-tg-hint')
      expect(w.findAll('[data-testid=org-waitlisted-hint]')).toHaveLength(1)
    })

    it('says it in Uzbek', async () => {
      const w = await render(settingsFixture({ interface_locale: 'uz' }), [membership({ seat_state: 'waitlisted' })])

      expect(w.get('[data-testid=org-state]').text()).toBe('Pro uchun navbatda')
      expect(w.get('[data-testid=org-waitlisted-hint]').text()).toBe("Markazda hozircha bo'sh joy yo'q. Joy bo'shaganda markaz bergan Pro yoqiladi.")
    })

    it('explains a center joined through a group instead of offering to leave it', async () => {
      const w = await render(settingsFixture(), [membership({ joined_via: 'group', can_leave: false })])

      expect(w.find('[data-testid=org-leave]').exists()).toBe(false)
      expect(w.get('[data-testid=org-via-group]').text()).toContain('через группу')
    })

    it('offers to leave with a small danger button, asks for confirmation, then leaves and shows the refreshed list', async () => {
      const w = await render(settingsFixture(), [membership(), membership({ id: 18, name: 'Other Center' })])
      vi.mocked(api.organizations.leave).mockResolvedValue({ organizations: [membership({ id: 18, name: 'Other Center' })] })
      const leave = w.get('[data-testid=org-17] [data-testid=org-leave]')
      expect(leave.classes()).toEqual(expect.arrayContaining(['btn', 'btn-danger', 'btn-sm']))

      await leave.trigger('click')
      expect(w.get('[data-testid=org-confirm]').text()).toContain('Покинуть центр «Bright Center»?')
      expect(w.get('[data-testid=org-leave-yes]').classes()).toEqual(expect.arrayContaining(['btn', 'btn-danger']))
      expect(w.get('[data-testid=org-leave-no]').classes()).toEqual(expect.arrayContaining(['btn', 'btn-secondary']))
      expect(vi.mocked(api.organizations.leave)).not.toHaveBeenCalled()

      await w.get('[data-testid=org-leave-yes]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.organizations.leave).mock.calls).toEqual([[17]])
      expect(w.findAll('[data-testid=org-name]').map((n) => n.text())).toEqual(['Other Center'])
      expect(w.find('[data-testid=org-confirm]').exists()).toBe(false)
    })

    it('makes the next /me request go to the server after leaving: the plan may have changed', async () => {
      const w = await render(settingsFixture(), [membership()])
      const billing = useBillingStore()
      billing.apply(me()) // fresh: a plain load() would not ask
      vi.mocked(api.organizations.leave).mockResolvedValue({ organizations: [] })
      vi.mocked(api.me.get).mockClear()

      await tap(w, 'org-leave')
      await tap(w, 'org-leave-yes')
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(1)
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

      const error = w.get('[data-testid=orgs-error]')
      expect(error.text()).toContain('через группу')
      expect(error.attributes('role')).toBe('alert')
      expect(error.find('svg').exists()).toBe(true)
      expect(w.find('[data-testid=org-leave]').exists()).toBe(false)
      expect(w.find('[data-testid=org-via-group]').exists()).toBe(true)
    })

    it('answers in Uzbek when the interface is Uzbek', async () => {
      const w = await render(uzSettings(), [membership()])
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(uzSettings()))
      await tap(w, 'locale-uz')

      expect(w.get('[data-testid=orgs-card]').text()).toContain('Mening markazlarim')
      expect(w.get('[data-testid=org-state]').text()).toBe('Markaz bergan Pro')
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

  describe('Uzbek', () => {
    it('speaks Uzbek in every group', async () => {
      const w = await render(settingsFixture({ interface_locale: 'uz', active_course: null }))

      expect(w.get('h1').text()).toBe('Sozlamalar')
      expect(w.findAll('h2.section-label').map((h) => h.text())).toEqual(["O'rganish", 'Mashqlar', 'Eslatmalar', 'Til va hudud', 'Ligalar'])
      expect(w.text()).toContain('kuniga nechta kartochka')
      expect(w.text()).toContain('Asosiy kurs')
      expect(w.get('[data-testid=course-title]').text()).toBe('Tanlanmagan')
      expect(w.text()).toContain('Eslatma vaqti')
      expect(w.text()).toContain('Haftalik ligalarda qatnashish')
      expect(labels(w, 'reminder-select').at(-1)).toBe("O'chirilgan")
    })
  })
})
