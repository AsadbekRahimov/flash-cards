import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RegionPromptCard from '../RegionPromptCard.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { useSettingsStore } from '@/stores/settings'
import { setLocale } from '@/i18n'
import type { LearnerSettings, MeResponse } from '@/api/types'

/*
 * The card "Choose your region" of the Rating tab (docs/35 §2.3, R4): shown only while the server says so (`region_prompt`);
 * "Not now" hides it at once and asks the server to hide it for 30 days; "Choose" opens the region sheet and saves the region with
 * `region_via = prompt`, after which the card is gone.
 */

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn(), updateSettings: vi.fn(), revertRegion: vi.fn(), dismissRegionPrompt: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => {
  const auth = { learner: { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: true } }
  return { useAuthStore: () => auth }
})

const settings = (overrides: Partial<LearnerSettings> = {}): LearnerSettings =>
  settingsFixture({
    region_code: null,
    region_prompt: true,
    ...overrides,
    options: {
      ...settingsFixture().options,
      regions: [
        { code: 'UZ-TK', name: 'город Ташкент' },
        { code: 'UZ-BU', name: 'Бухарская область' },
        { code: 'other', name: 'Другая страна' },
      ],
    },
  })

const me = (s: LearnerSettings): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 10 },
  paywall: { mode: 'coming_soon', contact: null },
  settings: s,
})

function render(s: LearnerSettings | null) {
  if (s !== null) useSettingsStore().adopt(s)
  return mount(RegionPromptCard, { attachTo: document.body })
}

describe('RegionPromptCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    setLocale('ru')
    document.body.innerHTML = ''
  })

  it('shows the card while the server asks for it, in the learner language', async () => {
    const w = render(settings())

    expect(w.get('[data-testid=region-prompt]').text()).toContain('Укажите регион')
    expect(w.text()).toContain('Появится рейтинг вашего региона.')
    expect(w.get('[data-testid=region-prompt-later]').text()).toBe('Не сейчас')

    setLocale('uz')
    await flushPromises()
    expect(w.get('[data-testid=region-prompt]').text()).toContain("Hududingizni ko'rsating")
    expect(w.get('[data-testid=region-prompt-later]').text()).toBe('Hozir emas')
    w.unmount()
  })

  it('shows nothing when the region is given or the card was put off (region_prompt = false)', () => {
    const w = render(settings({ region_code: 'UZ-TK', region_prompt: false }))

    expect(w.find('[data-testid=region-prompt]').exists()).toBe(false)
    w.unmount()
  })

  it('reads /me once when the settings are not there yet, and shows the card after it', async () => {
    vi.mocked(api.me.get).mockResolvedValue(me(settings()))
    const w = render(null)
    expect(w.find('[data-testid=region-prompt]').exists()).toBe(false)

    await flushPromises()

    expect(api.me.get).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid=region-prompt]').exists()).toBe(true)
    w.unmount()
  })

  it('hides the card at once on "Not now" and asks the server to hide it for 30 days', async () => {
    vi.mocked(api.me.dismissRegionPrompt).mockResolvedValue(undefined)
    const w = render(settings())

    await w.get('[data-testid=region-prompt-later]').trigger('click')
    await flushPromises()

    expect(api.me.dismissRegionPrompt).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid=region-prompt]').exists()).toBe(false)
    w.unmount()
  })

  it('keeps the card hidden when the request of "Not now" fails', async () => {
    vi.mocked(api.me.dismissRegionPrompt).mockRejectedValue(new Error('offline'))
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    const w = render(settings())

    await w.get('[data-testid=region-prompt-later]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid=region-prompt]').exists()).toBe(false)
    w.unmount()
  })

  it('opens the region sheet on "Choose" and saves the region with region_via = prompt', async () => {
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settings({ region_code: 'UZ-BU', region_prompt: false })))
    const w = render(settings())

    await w.get('[data-testid=region-prompt-choose]').trigger('click')
    await flushPromises()
    ;(document.querySelector('[data-testid=region-uzbekistan]') as HTMLElement).click()
    await flushPromises()
    ;(document.querySelector('[data-testid=region-UZ-BU]') as HTMLElement).click()
    await flushPromises()

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ region_code: 'UZ-BU', region_via: 'prompt' }]])
    expect(document.querySelector('[data-testid=region-sheet]')).toBeNull()
    expect(w.find('[data-testid=region-prompt]').exists()).toBe(false)
    w.unmount()
  })
})
