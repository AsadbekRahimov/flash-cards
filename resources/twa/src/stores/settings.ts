import { defineStore } from 'pinia'
import { api } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { errorMessage } from '@/i18n/errors'
import { getLocale, setLocale, SUPPORTED_LOCALES, t, type Locale } from '@/i18n'
import { formatDate } from '@/composables/format'
import { ApiClientError } from '@/api/client'
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
  }),

  getters: {
    /**
     * "The time zone can be changed once a day, next change at …" while `timezone_change_available_at`
     * is in the future (the date is shown in the learner's current time zone); null = the zone may be changed.
     */
    timezoneLockMessage: (state): string | null => {
      const iso = state.data?.timezone_change_available_at
      if (!iso) return null

      const at = new Date(iso)
      if (Number.isNaN(at.getTime()) || at.getTime() <= Date.now()) return null

      return t('settings.timezone.locked', { when: formatDate(iso, 'dayMonthTime', getLocale(), state.data?.timezone) })
    },
  },

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
        const me = await api.me.get()
        this.apply(me.settings)
        // The same `/me` tells the plan: the Pro exercises of the screen are locked for Free without a second request.
        useBillingStore().apply(me)
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
      try {
        this.apply((await api.me.updateSettings(patch)).settings)
        return true
      } catch (err) {
        if (err instanceof ApiClientError && err.code === 'timezone_change_too_soon') {
          // Nothing of the request was applied. The refusal carries no machine-readable date, so take
          // the server's settings again (this also drops whatever the screen had locally).
          await this.reloadQuietly()
          this.errorMessage = this.timezoneLockMessage ?? errorMessage(err, 'settings')
        } else {
          this.errorMessage = errorMessage(err, 'settings')
        }
        return false
      } finally {
        this.saving = false
      }
    },

    /**
     * Takes back the last change of the time zone (within 10 minutes of it). On a refusal
     * (`timezone_revert_unavailable`: too late / already used) the server's settings are taken again, so the button goes away.
     *
     * @returns whether the zone was put back
     */
    async revertTimezone(): Promise<boolean> {
      if (this.saving) return false

      this.saving = true
      this.errorMessage = ''
      try {
        this.apply((await api.me.revertTimezone()).settings)
        return true
      } catch (err) {
        if (err instanceof ApiClientError && err.code === 'timezone_revert_unavailable') await this.reloadQuietly()
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

    async reloadQuietly(): Promise<void> {
      try {
        this.apply((await api.me.get()).settings)
      } catch {
        // keep what the screen has; the message falls back to the one without a date
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
