import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import App from '@/App.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import type { AccountRestored, AuthResponse } from '@/api/types'

vi.mock('@/api', () => ({ api: { auth: { authenticate: vi.fn() }, me: { accountRestoredSeen: vi.fn() } } }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ initData: () => 'signed', selectionHaptic: vi.fn() }) }))

const learner = { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: true }
const answer = (accountRestored?: AccountRestored | null): AuthResponse => ({ token: 'jwt', expires_in: 900, learner, account_restored: accountRestored })

/** The shell (App.vue) on a route that is NOT Today - a deep link. */
async function openShell() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/deck', name: 'deck', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
    ],
  })
  await router.push('/deck')
  const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

const byId = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid=${id}]`)

describe('AccountRestoredSheet in the app shell', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(api.me.accountRestoredSeen).mockResolvedValue(undefined)
  })
  afterEach(() => {
    setLocale('ru')
    document.body.innerHTML = ''
  })

  it('shows nothing and calls nothing while the server has no notice', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue(answer(null))
    await openShell()
    await useAuthStore().login()
    await flushPromises()

    expect(byId('restored-sheet')).toBeNull()
    expect(api.me.accountRestoredSeen).not.toHaveBeenCalled()
  })

  it('shows the welcome once, on any screen, tells the server once; no Pro line without pro_can_resume', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue(answer({ pro_can_resume: false }))
    await openShell()
    await useAuthStore().login()
    await flushPromises()

    expect(byId('restored-title')?.textContent).toBe('С возвращением!')
    expect(byId('restored-text')?.textContent).toBe('Аккаунт восстановлен — слова и прогресс на месте.')
    expect(byId('restored-pro')).toBeNull()
    expect(byId('restored-billing')).toBeNull()
    expect(api.me.accountRestoredSeen).toHaveBeenCalledTimes(1)

    byId('restored-ok')?.click()
    await flushPromises()
    expect(byId('restored-sheet')).toBeNull()
  })

  it('does not show it again after a token refresh, even when seen failed', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue(answer({ pro_can_resume: false }))
    vi.mocked(api.me.accountRestoredSeen).mockRejectedValue(new Error('offline'))
    await openShell()
    const auth = useAuthStore()
    await auth.login()
    await flushPromises()
    byId('restored-ok')?.click()
    await flushPromises()

    await auth.login() // the refresh after ~15 minutes: the server still carries the notice (seen failed)
    await flushPromises()

    expect(byId('restored-sheet')).toBeNull()
    expect(api.me.accountRestoredSeen).toHaveBeenCalledTimes(1)
  })

  it('adds the auto-renewal line and the button to the subscription when it can be switched on again', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue(answer({ pro_can_resume: true }))
    const w = await openShell()
    await useAuthStore().login()
    await flushPromises()

    expect(byId('restored-pro')?.textContent).toContain('Автопродление Pro было выключено при удалении')
    byId('restored-billing')?.click()
    await flushPromises()

    expect(w.router.currentRoute.value.name).toBe('billing')
    expect(byId('restored-sheet')).toBeNull()
  })

  it('speaks uz', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue({ ...answer({ pro_can_resume: false }), learner: { ...learner, locale: 'uz' } })
    await openShell()
    await useAuthStore().login()
    await flushPromises()

    expect(byId('restored-title')?.textContent).toBe('Yana xush kelibsiz!')
  })
})
