import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import AccountRestoredSheet from '../AccountRestoredSheet.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { AccountRestored, MeResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn(), accountRestoredSeen: vi.fn() } } }))

const me = (accountRestored: AccountRestored | null): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
  account_restored: accountRestored,
})

async function render(response: MeResponse) {
  vi.mocked(api.me.get).mockResolvedValue(response)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'today', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  const wrapper = mount(AccountRestoredSheet, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

const byId = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid=${id}]`)

describe('AccountRestoredSheet', () => {
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
    await render(me(null))

    expect(byId('restored-sheet')).toBeNull()
    expect(api.me.accountRestoredSeen).not.toHaveBeenCalled()
  })

  it('shows the welcome once and tells the server once; no Pro line without pro_can_resume', async () => {
    await render(me({ pro_can_resume: false }))

    expect(byId('restored-title')?.textContent).toBe('С возвращением!')
    expect(byId('restored-text')?.textContent).toBe('Аккаунт восстановлен — слова и прогресс на месте.')
    expect(byId('restored-pro')).toBeNull()
    expect(byId('restored-billing')).toBeNull()
    expect(api.me.accountRestoredSeen).toHaveBeenCalledTimes(1)

    byId('restored-ok')?.click()
    await flushPromises()
    expect(byId('restored-sheet')).toBeNull()
  })

  it('adds the auto-renewal line and the button to the subscription when it can be switched on again', async () => {
    const w = await render(me({ pro_can_resume: true }))

    expect(byId('restored-pro')?.textContent).toContain('Автопродление Pro было выключено при удалении')
    byId('restored-billing')?.click()
    await flushPromises()

    expect(w.router.currentRoute.value.name).toBe('billing')
    expect(byId('restored-sheet')).toBeNull()
    expect(api.me.accountRestoredSeen).toHaveBeenCalledTimes(1)
  })

  it('ignores a failed seen call and speaks uz', async () => {
    setLocale('uz')
    vi.mocked(api.me.accountRestoredSeen).mockRejectedValue(new Error('offline'))
    await render(me({ pro_can_resume: false }))

    expect(byId('restored-title')?.textContent).toBe('Xush kelibsiz!')
  })
})
