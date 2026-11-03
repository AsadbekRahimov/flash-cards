import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '../SettingsView.vue'
import AccountDeletedView from '../AccountDeletedView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import type { MeResponse } from '@/api/types'

const state = vi.hoisted(() => ({ close: vi.fn() }))

vi.mock('@/api', () => ({
  api: { me: { get: vi.fn(), updateSettings: vi.fn(), revertTimezone: vi.fn(), deleteAccount: vi.fn() }, organizations: { list: vi.fn(), leave: vi.fn() } },
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {}, close: state.close }),
}))

const me = (locale = 'ru'): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'coming_soon', contact: null },
  settings: settingsFixture({ interface_locale: locale }),
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: SettingsView },
      { path: '/account-deleted', name: 'account-deleted', component: AccountDeletedView, props: (r) => ({ until: typeof r.query.until === 'string' ? r.query.until : null }) },
    ],
  })

async function render(locale = 'ru') {
  vi.mocked(api.me.get).mockResolvedValue(me(locale))
  vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [] })
  const router = makeRouter()
  await router.push('/settings')
  const wrapper = mount(SettingsView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

const byId = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid=${id}]`)

describe('Settings: delete account', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    // the stubbed auth store of the other settings specs is a plain object; here the real one is needed (logout)
    useAuthStore().$patch({ token: 'tok', expiresAt: Date.now() + 3_600_000, learner: { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: true } })
    vi.spyOn(useAuthStore(), 'ensure').mockResolvedValue(undefined)
  })
  afterEach(() => {
    document.body.innerHTML = ''
    setLocale('ru')
    vi.useRealTimers()
  })

  it('puts «Delete account» last, in the danger zone, as a row of at least 52 px with no sheet open', async () => {
    const w = await render()

    const groups = w.findAll('section')
    expect(groups.at(-1)?.attributes('data-testid')).toBe('danger-zone')
    expect(w.get('[data-testid=danger-zone]').text()).toContain('Опасная зона')
    expect(w.get('[data-testid=delete-account]').classes()).toContain('list-row')
    expect(w.get('[data-testid=delete-account]').text()).toBe('Удалить аккаунт')
    expect(byId('delete-sheet')).toBeNull()
    expect(api.me.deleteAccount).not.toHaveBeenCalled()
  })

  it('asks first: the sheet tells what happens and a tap does not delete anything', async () => {
    const w = await render()

    await w.get('[data-testid=delete-account]').trigger('click')

    const text = byId('delete-consequences')?.textContent ?? ''
    expect(byId('delete-title')?.textContent).toBe('Удалить аккаунт?')
    expect(text).toContain('30 дней')
    expect(text).toContain('/start')
    expect(text).toContain('Платёжные записи хранятся по закону')
    expect(text).toContain('Подписка Pro прекращается')
    expect(api.me.deleteAccount).not.toHaveBeenCalled()
  })

  it('«Cancel» closes the sheet and deletes nothing', async () => {
    const w = await render()
    await w.get('[data-testid=delete-account]').trigger('click')

    byId('delete-no')?.click()
    await flushPromises()

    expect(byId('delete-sheet')).toBeNull()
    expect(api.me.deleteAccount).not.toHaveBeenCalled()
  })

  it('«Yes, delete» deletes, drops the session and shows «Account deleted» with the date', async () => {
    vi.mocked(api.me.deleteAccount).mockResolvedValue({ deleted: true, restorable_until: '2026-12-03T10:00:00+00:00' })
    const w = await render()
    await w.get('[data-testid=delete-account]').trigger('click')

    byId('delete-yes')?.click()
    await flushPromises()

    expect(api.me.deleteAccount).toHaveBeenCalledTimes(1)
    expect(useAuthStore().token).toBe('')
    expect(useSettingsStore().data).toBeNull()
    expect(w.router.currentRoute.value.name).toBe('account-deleted')
    expect(w.router.currentRoute.value.query.until).toBe('2026-12-03T10:00:00+00:00')
    // nothing asks the server again after the deletion
    expect(api.me.get).toHaveBeenCalledTimes(1)
  })

  it('keeps the sheet open with the reason when the server refuses, and the account stays signed in', async () => {
    vi.mocked(api.me.deleteAccount).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))
    const w = await render()
    await w.get('[data-testid=delete-account]').trigger('click')

    byId('delete-yes')?.click()
    await flushPromises()

    expect(byId('delete-sheet')).not.toBeNull()
    expect(byId('delete-error')?.textContent?.trim()).not.toBe('')
    expect(useAuthStore().token).toBe('tok')
    expect(w.router.currentRoute.value.name).toBe('settings')
    expect((byId('delete-yes') as HTMLButtonElement).disabled).toBe(false)
  })

  it('speaks uz', async () => {
    const w = await render('uz')

    expect(w.get('[data-testid=delete-account]').text()).toBe("Akkauntni o'chirish")
    await w.get('[data-testid=delete-account]').trigger('click')
    expect(byId('delete-title')?.textContent).toBe("Akkauntni o'chirasizmi?")
    expect(byId('delete-yes')?.textContent?.trim()).toBe("Ha, o'chirish")
  })
})

describe('AccountDeletedView', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    state.close.mockClear()
  })
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
  })

  it('says the account is deleted and until when /start brings it back', () => {
    const w = mount(AccountDeletedView, { props: { until: '2026-12-03T10:00:00+00:00' } })

    expect(w.text()).toContain('Аккаунт удалён')
    expect(w.text()).toContain('/start')
    expect(w.text()).toContain('2026')
  })

  it('falls back to «within 30 days» without a date', () => {
    const w = mount(AccountDeletedView, { props: { until: null } })

    expect(w.text()).toContain('в течение 30 дней')
  })

  it('closes the Mini App by the button and by itself after a while, not before', async () => {
    const w = mount(AccountDeletedView, { props: { until: null } })

    vi.advanceTimersByTime(3000)
    expect(state.close).not.toHaveBeenCalled()

    await w.get('[data-testid=account-deleted-close]').trigger('click')
    expect(state.close).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(8000)
    expect(state.close).toHaveBeenCalledTimes(2)
  })

  it('does not close by itself once left', () => {
    const w = mount(AccountDeletedView, { props: { until: null } })

    w.unmount()
    vi.advanceTimersByTime(20000)

    expect(state.close).not.toHaveBeenCalled()
  })
})
