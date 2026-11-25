import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
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
  /** "Delete account" is in flight */
  deleting: boolean
  errorMessage: string
  /**
   * The last change was refused with 403 `daily_goal_pro_required` (docs/05 §8c-1: the plan ended between `/me` and the tap): the screen
   * opens the paywall instead of a toast. Reset by every `update`.
   */
  goalProRequired: boolean
}

function isLocale(value: string): value is Locale {
  return (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

export const useSettingsStore = defineStore('settings', {
  state: (): SettingsState => ({
    data: null,
    status: 'idle',
    saving: false,
    deleting: false,
    errorMessage: '',
    goalProRequired: false,
  }),

  actions: {
    /** The screen has told the failure of an action (a toast): forget it, so that the same failure again is news. */
    clearError(): void {
      this.errorMessage = ''
    },

    /** Always refetches; a screen that already has data stays visible while reloading. */
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
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
      this.errorMessage = ''
      this.goalProRequired = false
      try {
        this.apply((await api.me.updateSettings(patch)).settings)
        return true
      } catch (err) {
        if (err instanceof ApiClientError && err.code === 'daily_goal_pro_required') {
          // Not an error to tell: the goal is behind Pro. The limit of the answer locks the goals above it from now on.
          this.goalProRequired = true
          const max = err.details?.max
          if (this.data && typeof max === 'number') this.data.options.daily_goal_max = max
          return false
        }
        this.errorMessage = errorMessage(err, 'settings')
        return false
      } finally {
        this.saving = false
      }
    },

    /**
     * "Delete account": the server soft-deletes it and the token stops working at once, so the session is dropped here
     * too and nothing may ask the server again (a request with a stale token would sign the learner in anew and
     * bring the account back). The refusal is returned, not put in `errorMessage` (that one is the screen's toast):
     * the sheet that asked shows it.
     *
     * @returns `until` - the moment up to which `/start` in the bot brings the account back; or `error` - why it was not deleted
     */
    async deleteAccount(): Promise<{ until: string } | { error: string }> {
      if (this.deleting) return { error: '' }

      this.deleting = true
      try {
        const res = await api.me.deleteAccount()
        useAuthStore().logout()
        this.data = null
        this.status = 'idle'
        return { until: res.restorable_until }
      } catch (err) {
        return { error: errorMessage(err, 'settings') }
      } finally {
        this.deleting = false
      }
    },

    /**
     * Takes the settings that came with another reading of `/me` (the billing store's): the language row of the Profile and the
     * language sheet then need no request of their own. Unlike `apply` it leaves the interface language alone - that one is
     * the business of `load` and `update`.
     */
    adopt(settings: LearnerSettings): void {
      this.data = settings
      if (this.status === 'idle') this.status = 'ready'
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
