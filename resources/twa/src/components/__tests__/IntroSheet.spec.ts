import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import IntroSheet from '../IntroSheet.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import { getLocale, setLocale } from '@/i18n'
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

async function mountIntro() {
  const w = mount(IntroSheet, { attachTo: document.body })
  await flushPromises()
  return w
}

describe('IntroSheet', () => {
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
  })

  async function toStep(w: ReturnType<typeof mount>, n: number): Promise<void> {
    for (let i = 1; i < n; i++) await w.get('[data-testid=intro-next]').trigger('click')
  }

  it('has four dots and tells about the ladder of exercises on the third step', async () => {
    const w = await mountIntro()

    expect(w.get('[data-testid=intro-dots]').findAll('span')).toHaveLength(4)
    await toStep(w, 3)
    expect(w.get('[data-testid=intro-title]').text()).toBe('Задания становятся сложнее')
    expect(w.get('[data-testid=intro-text]').text()).toContain('потом пишете и вставляете в предложение')
    w.unmount()
  })

  it('shows "Start" only on the last step and closes from it', async () => {
    const w = await mountIntro()

    await toStep(w, 3)
    expect(w.get('[data-testid=intro-next]').text()).toBe('Дальше')
    await w.get('[data-testid=intro-next]').trigger('click')
    expect(w.get('[data-testid=intro-title]').text()).toBe('Хватит 10 минут в день')
    expect(w.get('[data-testid=intro-next]').text()).toBe('Начать')
    await w.get('[data-testid=intro-next]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
    w.unmount()
  })

  it('speaks Uzbek too', async () => {
    setLocale('uz')
    vi.mocked(api.me.get).mockResolvedValue(me(settingsFixture({ interface_locale: 'uz' })))
    const w = await mountIntro()

    await toStep(w, 3)
    expect(w.get('[data-testid=intro-text]').text()).toContain("gapga qo'yasiz")
    w.unmount()
  })

  describe('language switch', () => {
    it('sits on the first step only, with the languages of the settings and the current one selected', async () => {
      const w = await mountIntro()

      const box = w.get('[data-testid=intro-locale]')
      expect(box.get('[role=radiogroup]').attributes('aria-label')).toBe('Til · Язык')
      expect(box.findAll('[role=radio]').map((b) => b.text())).toEqual(['🇷🇺 Русский', "🇺🇿 O'zbekcha"])
      expect(box.get('[data-testid=intro-locale-ru]').attributes('aria-checked')).toBe('true')

      await w.get('[data-testid=intro-next]').trigger('click')
      expect(w.find('[data-testid=intro-locale]').exists()).toBe(false)
      w.unmount()
    })

    it('saves the language like the settings do and redraws the slides at once', async () => {
      vi.mocked(api.me.updateSettings).mockResolvedValue(me(settingsFixture({ interface_locale: 'uz' })))
      const w = await mountIntro()
      expect(w.get('[data-testid=intro-title]').text()).toBe('Вы видите слово и вспоминаете перевод')

      await w.get('[data-testid=intro-locale-uz]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.me.updateSettings).mock.calls).toEqual([[{ interface_locale: 'uz' }]])
      expect(getLocale()).toBe('uz')
      expect(w.get('[data-testid=intro-title]').text()).toBe("Siz so'zni ko'rasiz va tarjimasini eslaysiz")
      expect(w.get('[data-testid=intro-next]').text()).toBe('Keyingi')
      expect(w.get('[data-testid=intro-locale-uz]').attributes('aria-checked')).toBe('true')
      expect(useToast().toast.value?.text).toBe('Saqlandi')
      expect(w.emitted('close')).toBeUndefined()
      w.unmount()
    })

    it('keeps the language and tells the reason when the save is refused', async () => {
      vi.mocked(api.me.updateSettings).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'offline'))
      const w = await mountIntro()

      await w.get('[data-testid=intro-locale-uz]').trigger('click')
      await flushPromises()

      expect(getLocale()).toBe('ru')
      expect(w.get('[data-testid=intro-locale-ru]').attributes('aria-checked')).toBe('true')
      expect(useToast().toast.value?.text).toContain('Нет связи с сервером')
      w.unmount()
    })

    it('is left out when the settings cannot be loaded: the intro still works', async () => {
      vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'offline'))
      const w = await mountIntro()

      expect(w.find('[data-testid=intro-locale]').exists()).toBe(false)
      expect(w.get('[data-testid=intro-title]').text()).toBe('Вы видите слово и вспоминаете перевод')
      w.unmount()
    })
  })

  describe('"Пропустить"', () => {
    it('closes the intro on any step but the last, which has "Начать" instead', async () => {
      const w = await mountIntro()
      expect(w.get('[data-testid=intro-skip]').text()).toBe('Пропустить')

      await toStep(w, 2)
      await w.get('[data-testid=intro-skip]').trigger('click')
      expect(w.emitted('close')).toHaveLength(1)

      await toStep(w, 4)
      expect(w.find('[data-testid=intro-skip]').exists()).toBe(false)
      w.unmount()
    })

    it('says it in Uzbek', async () => {
      setLocale('uz')
      vi.mocked(api.me.get).mockResolvedValue(me(settingsFixture({ interface_locale: 'uz' })))
      const w = await mountIntro()

      expect(w.get('[data-testid=intro-skip]').text()).toBe("O'tkazib yuborish")
      w.unmount()
    })
  })
})
