import { defineStore } from 'pinia'
import { api } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { errorMessage } from '@/i18n/errors'
import { setLocale, SUPPORTED_LOCALES, type Locale } from '@/i18n'
import type { LearnerSettings, SettingsPatch } from '@/api/types'

export type SettingsStatus = 'idle' | 'loading' | 'ready' | 'error'

interface SettingsState {
  data: LearnerSettings | null
  status: SettingsStatus
  /** a PATCH is in flight: the screen disables its controls, changes are applied one at a time */
  saving: boolean
  /** the last change was saved (shown until the next change or reload) */
  saved: boolean
  errorMessage: string
}

function isLocale(value: string): value is Locale {
  return (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

export const useSettingsStore = defineStore('settings', {
  state: (): SettingsState => ({
    data: null,
    status: 'idle',
    saving: false,
    saved: false,
    errorMessage: '',
  }),

  actions: {
    /** Always refetches; a screen that already has data stays visible while reloading. */
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      this.saved = false
      try {
        this.apply((await api.me.get()).settings)
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },

    /**
     * Saves one change. On success the server's answer replaces the data — its labels are already in
     * the new language — and a changed interface language switches the whole TWA at once. On failure
     * the data stays as it was and `errorMessage` says why.
     *
     * @returns whether the change was saved
     */
    async update(patch: SettingsPatch): Promise<boolean> {
      if (this.saving) return false

      this.saving = true
      this.saved = false
      this.errorMessage = ''
      try {
        this.apply((await api.me.updateSettings(patch)).settings)
        this.saved = true
        return true
      } catch (err) {
        this.errorMessage = errorMessage(err, 'settings')
        return false
      } finally {
        this.saving = false
      }
    },

    apply(settings: LearnerSettings): void {
      this.data = settings

      const locale = settings.interface_locale
      if (isLocale(locale)) {
        setLocale(locale)
        const auth = useAuthStore()
        if (auth.learner) auth.learner = { ...auth.learner, locale }
      }
    },
  },
})
