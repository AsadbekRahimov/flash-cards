import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '../SettingsView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import { useSettingsStore } from '@/stores/settings'
import { setLocale } from '@/i18n'
import type { LearnerSettings, MeResponse } from '@/api/types'

/*
 * The region (docs/35 §2.3, R4) and the emoji of Pro (docs/31 §5а, E2) on the settings screen: the region row opens the two-level
 * sheet and saves `region_code` with `region_via = settings`; a 422 `region_change_too_soon` says the date; the emoji picker of a Free
 * learner shows the lock and opens the paywall without a PATCH; Pro saves the code; a 403 `pro_emoji_pro_required` opens the paywall.
 */

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn(), updateSettings: vi.fn(), revertTimezone: vi.fn(), revertRegion: vi.fn(), dismissRegionPrompt: vi.fn() },
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

const REGIONS = [
  { code: 'UZ-TK', name: 'город Ташкент' },
  { code: 'UZ-AN', name: 'Андижанская область' },
  { code: 'UZ-SA', name: 'Самаркандская область' },
  { code: 'other', name: 'Другая страна' },
]

const EMOJIS = [
  { code: 'fire', emoji: '🔥' },
  { code: 'star', emoji: '⭐' },
]

const settings = (overrides: Partial<LearnerSettings> = {}, locked = true): LearnerSettings =>
  settingsFixture({
    region_code: null,
    region_change_available_at: null,
    region_undo_available_until: null,
    region_prompt: true,
    pro_emoji_choice: null,
    ...overrides,
    options: { ...settingsFixture().options, regions: REGIONS, pro_emojis: EMOJIS, pro_emoji_locked: locked },
  })

const me = (s: LearnerSettings): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true, pro_emoji: null },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 10 },
  paywall: { mode: 'coming_soon', contact: null },
  settings: s,
})

async function render(s: LearnerSettings) {
  vi.mocked(api.me.get).mockResolvedValue(me(s))
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
  const wrapper = mount(SettingsView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

const sheet = () => document.querySelector('[data-testid=region-sheet]')
const click = async (testid: string) => {
  ;(document.querySelector(`[data-testid=${testid}]`) as HTMLElement).click()
  await flushPromises()
}

describe('SettingsView: the region', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    setLocale('ru')
    state.ensure.mockResolvedValue(undefined)
    useToast().hide()
    document.body.innerHTML = ''
  })

  it('shows "not given" and opens the two levels of the sheet: Uzbekistan → the regions, Back', async () => {
    const w = await render(settings())

    expect(w.get('[data-testid=region-name]').text()).toBe('Не указан')
    expect(w.get('[data-testid=region-card]').text()).toContain('Сменить область можно раз в 30 дней')

    await w.get('[data-testid=region-row]').trigger('click')
    await flushPromises()
    expect(sheet()?.textContent).toContain('Где вы живёте?')
    expect(document.querySelector('[data-testid=region-none]')?.getAttribute('aria-checked')).toBe('true')
    expect(document.querySelector('[data-testid=region-level-1]')?.getAttribute('role')).toBe('radiogroup')
    expect(document.querySelector('[data-testid=region-uzbekistan]')?.getAttribute('role')).toBe('radio')
    expect(document.querySelector('[data-testid=region-uzbekistan]')?.getAttribute('aria-checked')).toBe('false')

    await click('region-uzbekistan')
    // The 14 regions without `other`, in the server's order.
    const names = [...document.querySelectorAll('[data-testid=region-list] button')].map((b) => b.textContent?.trim())
    expect(names).toEqual(['город Ташкент', 'Андижанская область', 'Самаркандская область'])

    await click('region-back')
    expect(document.querySelector('[data-testid=region-level-1]')).not.toBeNull()
    w.unmount()
  })

  it('saves the chosen region with region_via = settings, closes the sheet and shows the name', async () => {
    const w = await render(settings())
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settings({ region_code: 'UZ-SA', region_prompt: false })))

    await w.get('[data-testid=region-row]').trigger('click')
    await flushPromises()
    await click('region-uzbekistan')
    await click('region-UZ-SA')

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ region_code: 'UZ-SA', region_via: 'settings' }]])
    expect(sheet()).toBeNull()
    expect(w.get('[data-testid=region-name]').text()).toBe('Самаркандская область')
    w.unmount()
  })

  it('saves "Another country" and "Rather not say" at once from the first level', async () => {
    const w = await render(settings({ region_code: 'UZ-TK' }))
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settings({ region_code: null })))

    await w.get('[data-testid=region-row]').trigger('click')
    await flushPromises()
    // A learner with a region of Uzbekistan sees the list first; Back leads to the first level.
    expect(document.querySelector('[data-testid=region-UZ-TK]')?.getAttribute('aria-checked')).toBe('true')
    await click('region-back')
    // ... where "Uzbekistan" is the chosen row
    expect(document.querySelector('[data-testid=region-uzbekistan]')?.getAttribute('aria-checked')).toBe('true')
    expect(document.querySelector('[data-testid=region-uzbekistan-check]')).not.toBeNull()
    expect(document.querySelector('[data-testid=region-none]')?.getAttribute('aria-checked')).toBe('false')
    await click('region-none')

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ region_code: null, region_via: 'settings' }]])
    w.unmount()
  })

  it('says from which date the region can be changed on 422 region_change_too_soon, and keeps the sheet open', async () => {
    const w = await render(settings({ region_code: 'UZ-TK', region_change_available_at: null }))
    vi.mocked(api.me.updateSettings).mockRejectedValue(
      new ApiClientError('validation', 422, 'region_change_too_soon', 'too soon', undefined, { available_at: '2026-12-24T07:00:00+00:00' }),
    )

    await w.get('[data-testid=region-row]').trigger('click')
    await flushPromises()
    await click('region-UZ-AN')

    expect(useToast().toast.value?.text).toMatch(/^Сменить область можно с 24 дек\.? 2026/)
    expect(sheet()).not.toBeNull()
    expect(w.get('[data-testid=region-name]').text()).toBe('город Ташкент')
    w.unmount()
  })

  it('offers the undo while the server allows it and takes the change back', async () => {
    const until = new Date(Date.now() + 5 * 60_000).toISOString()
    const w = await render(settings({ region_code: 'UZ-AN', region_undo_available_until: until }))
    vi.mocked(api.me.revertRegion).mockResolvedValue(me(settings({ region_code: 'UZ-TK' })))

    await w.get('[data-testid=region-undo]').trigger('click')
    await flushPromises()

    expect(api.me.revertRegion).toHaveBeenCalledTimes(1)
    expect(w.get('[data-testid=region-name]').text()).toBe('город Ташкент')
    expect(w.find('[data-testid=region-undo]').exists()).toBe(false)
    w.unmount()
  })

  it('takes the undo away by itself when its time is over, while the screen stays open', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
    try {
      const until = new Date(Date.now() + 60_000).toISOString()
      const w = await render(settings({ region_code: 'UZ-AN', region_undo_available_until: until }))
      expect(w.find('[data-testid=region-undo]').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(59_000)
      expect(w.find('[data-testid=region-undo]').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(2_000)
      expect(w.find('[data-testid=region-undo]').exists()).toBe(false)
      w.unmount()
    } finally {
      vi.useRealTimers()
    }
  })

  it('speaks Uzbek for a learner whose interface is Uzbek', async () => {
    const w = await render(settings({ region_code: null, interface_locale: 'uz' }))

    expect(w.get('[data-testid=region-name]').text()).toBe("Ko'rsatilmagan")
    expect(w.get('[data-testid=region-row]').text()).toContain('Viloyat')
    w.unmount()
  })
})

describe('SettingsView: the emoji of Pro', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    setLocale('ru')
    state.ensure.mockResolvedValue(undefined)
    useToast().hide()
    document.body.innerHTML = ''
  })

  it('shows the set to a Free learner as a plus of Pro: a lock, and a tap opens the paywall without a PATCH', async () => {
    const w = await render(settings({}, true))

    expect(w.get('[data-testid=pro-emoji-card]').text()).toContain('одно из преимуществ Pro')
    expect(w.get('[data-testid=pro-emoji-fire]').attributes('data-locked')).toBe('true')

    await w.get('[data-testid=pro-emoji-fire]').trigger('click')
    await flushPromises()

    expect(api.me.updateSettings).not.toHaveBeenCalled()
    expect(w.find('[data-testid=paywall]').exists()).toBe(true)
    w.unmount()
  })

  it('keeps the choice of a Free learner visible and lets them take it back', async () => {
    const w = await render(settings({ pro_emoji_choice: 'star' }, true))
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settings({ pro_emoji_choice: null }, true)))

    expect(w.get('[data-testid=pro-emoji-star]').attributes('aria-checked')).toBe('true')

    await w.get('[data-testid=pro-emoji-none]').trigger('click')
    await flushPromises()

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ pro_emoji: null }]])
    expect(w.get('[data-testid=pro-emoji-none]').attributes('aria-checked')).toBe('true')
    w.unmount()
  })

  it('saves the code of the emoji for a Pro learner', async () => {
    const w = await render(settings({}, false))
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settings({ pro_emoji_choice: 'fire' }, false)))

    await w.get('[data-testid=pro-emoji-fire]').trigger('click')
    await flushPromises()

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ pro_emoji: 'fire' }]])
    expect(w.get('[data-testid=pro-emoji-fire]').attributes('aria-checked')).toBe('true')
    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    w.unmount()
  })

  it('keeps the emoji of the answer for the Profile: the name shows the new choice without another /me', async () => {
    const w = await render(settings({}, false))
    const answer = me(settings({ pro_emoji_choice: 'fire' }, false))
    vi.mocked(api.me.updateSettings).mockResolvedValue({ ...answer, learner: { ...answer.learner, pro_emoji: '🔥' } })

    await w.get('[data-testid=pro-emoji-fire]').trigger('click')
    await flushPromises()

    expect(useSettingsStore().proEmoji).toBe('🔥')
    w.unmount()
  })

  it('opens the paywall on 403 pro_emoji_pro_required without an error toast and locks the picker', async () => {
    const w = await render(settings({}, false))
    vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('forbidden', 403, 'pro_emoji_pro_required', 'Pro required'))

    await w.get('[data-testid=pro-emoji-star]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid=paywall]').exists()).toBe(true)
    expect(useToast().toast.value).toBeNull()
    expect(w.get('[data-testid=pro-emoji-star]').attributes('data-locked')).toBe('true')
    w.unmount()
  })
})
