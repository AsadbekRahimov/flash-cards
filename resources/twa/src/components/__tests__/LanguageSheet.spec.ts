import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import LanguageSheet from '../LanguageSheet.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import { getLocale, setLocale } from '@/i18n'
import { useSettingsStore } from '@/stores/settings'
import type { LearnerSettings, MeResponse } from '@/api/types'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn(), updateSettings: vi.fn() } } }))

vi.mock('@/stores/auth', () => {
  const auth = { learner: { id: 1, first_name: 'A', locale: 'ru', onboarding_completed: true } }
  return { useAuthStore: () => auth }
})

const me = (settings: LearnerSettings = settingsFixture()): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: settings.interface_locale, onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'coming_soon', contact: null },
  settings,
})

async function render() {
  const wrapper = mount(LanguageSheet, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('LanguageSheet', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    useToast().hide()
    setLocale('ru')
    vi.mocked(api.me.get).mockResolvedValue(me())
  })
  afterEach(() => {
    useToast().hide()
    setLocale('ru')
    document.body.innerHTML = ''
  })

  it('has a bilingual title that does not depend on the language, and lists the languages of the settings with a check on the current one', async () => {
    const w = await render()

    expect(w.get('[data-testid=language-title]').text()).toBe('Til · Язык')
    expect(w.get('[role=dialog]').attributes('aria-label')).toBe('Til · Язык')
    expect(w.get('[data-testid=language-ru] [data-testid=language-label]').text()).toBe('🇷🇺 Русский')
    expect(w.get('[data-testid=language-uz] [data-testid=language-label]').text()).toBe("🇺🇿 O'zbekcha")
    // F6 of the kit: the reading mascot, the code on a plate, a sample of the translations, the chosen card framed 2 px in border/strong
    expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('reading')
    expect(w.get('[data-testid=language-ru]').text()).toContain('ru')
    expect(w.get('[data-testid=language-ru] [data-testid=language-sample]').text()).toBe('tea — чай')
    expect(w.get('[data-testid=language-uz] [data-testid=language-sample]').text()).toBe('tea — choy')
    expect(w.get('[data-testid=language-ru]').classes()).toContain('shadow-[inset_0_0_0_2px_var(--app-border-strong)]')
    expect(w.get('[data-testid=language-uz]').classes()).toContain('shadow-[inset_0_0_0_1.5px_var(--app-hairline)]')
    expect(w.get('[data-testid=language-ru]').attributes('aria-checked')).toBe('true')
    expect(w.get('[data-testid=language-uz]').attributes('aria-checked')).toBe('false')
    expect(w.findAll('[data-testid=language-check]')).toHaveLength(1)
    expect(w.find('[data-testid=language-ru] svg').exists()).toBe(true)
    w.unmount()

    setLocale('uz')
    expect((await render()).get('[data-testid=language-title]').text()).toBe('Til · Язык')
  })

  it('does not load the settings again when they are already there', async () => {
    useSettingsStore().adopt(settingsFixture())

    const w = await render()

    expect(api.me.get).not.toHaveBeenCalled()
    expect(w.find('[data-testid=language-ru]').exists()).toBe(true)
    w.unmount()
  })

  it('saves another language through the settings, switches the app, says "Saved" in it and closes', async () => {
    vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture({ interface_locale: 'uz' })))
    const w = await render()

    await w.get('[data-testid=language-uz]').trigger('click')
    await flushPromises()

    expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ interface_locale: 'uz' }]])
    expect(getLocale()).toBe('uz')
    expect(document.documentElement.lang).toBe('uz')
    expect(useToast().toast.value?.text).toBe('Saqlandi')
    expect(w.emitted('close')).toHaveLength(1)
    w.unmount()
  })

  it('closes without a request when the current language is tapped', async () => {
    const w = await render()

    await w.get('[data-testid=language-ru]').trigger('click')
    await flushPromises()

    expect(api.me.updateSettings).not.toHaveBeenCalled()
    expect(useToast().toast.value).toBeNull()
    expect(w.emitted('close')).toHaveLength(1)
    w.unmount()
  })

  it('keeps the language and the sheet and tells the reason in a toast when the save is refused', async () => {
    vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'offline'))
    const w = await render()

    await w.get('[data-testid=language-uz]').trigger('click')
    await flushPromises()

    expect(getLocale()).toBe('ru')
    expect(w.emitted('close')).toBeUndefined()
    expect(useToast().toast.value?.text).toContain('Нет связи с сервером')
    expect(useSettingsStore().data?.interface_locale).toBe('ru')
    expect(w.get('[data-testid=language-ru]').attributes('aria-checked')).toBe('true')
    w.unmount()
  })

  it('offers a retry when the settings cannot be loaded', async () => {
    vi.mocked(api.me.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'offline')).mockResolvedValueOnce(me())
    const w = await render()

    expect(w.find('[data-testid=language-list]').exists()).toBe(false)
    await w.get('[data-testid=language-retry]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid=language-list]').exists()).toBe(true)
    w.unmount()
  })

  it('closes from the ✕', async () => {
    const w = await render()

    await w.get('[data-testid=sheet-x]').trigger('click')

    expect(w.emitted('close')).toHaveLength(1)
    w.unmount()
  })
})
