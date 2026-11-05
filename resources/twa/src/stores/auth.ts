import { defineStore } from 'pinia'
import { api } from '@/api'
import type { AccountRestored, AuthContext, Learner } from '@/api/types'
import { useTelegram } from '@/composables/useTelegram'
import { setLocale, SUPPORTED_LOCALES, type Locale } from '@/i18n'

interface AuthState {
  token: string
  expiresAt: number         // epoch ms; refresh before reaching this
  learner: Learner | null
  context: AuthContext | null
  loading: boolean
  error: string | null
  /** `account_restored` of the last `/auth` that has not been shown yet (R11b); the shell's sheet takes it. */
  accountRestored: AccountRestored | null
  /** Shown (or being shown) in this session: a token refresh must not show it again, even when `seen` failed. */
  restoredShown: boolean
}

const pendingLogins = new WeakMap<object, Promise<void>>()

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    token: '',
    expiresAt: 0,
    learner: null,
    context: null,
    loading: false,
    error: null,
    accountRestored: null,
    restoredShown: false,
  }),

  getters: {
    isAuthenticated: (s) => s.token !== '' && Date.now() < s.expiresAt,
  },

  actions: {
    async login(context?: AuthContext | null): Promise<void> {
      if (context !== undefined) this.context = context
      const pending = pendingLogins.get(this)
      if (pending) return pending

      const { initData } = useTelegram()
      const payload = initData()
      if (!payload) {
        this.error = 'opened_outside_telegram'
        throw new Error('opened_outside_telegram')
      }

      this.loading = true
      this.error = null
      const attempt: Promise<void> = api.auth.authenticate(payload, this.context)
        .then((res) => {
          if (pendingLogins.get(this) !== attempt) throw new Error('auth_cancelled')
          this.token = res.token
          this.expiresAt = Date.now() + res.expires_in * 1000
          this.learner = res.learner
          if (res.account_restored && !this.restoredShown) this.accountRestored = res.account_restored
          // The learner's own choice (onboarding / settings) beats Telegram's language_code picked at startup.
          if ((SUPPORTED_LOCALES as readonly string[]).includes(res.learner.locale)) setLocale(res.learner.locale as Locale)
        })
        .catch((err: unknown) => {
          if (pendingLogins.get(this) === attempt) {
            this.token = ''
            this.expiresAt = 0
            this.error = (err as { code?: string })?.code ?? 'auth_failed'
          }
          throw err
        })
        .finally(() => {
          if (pendingLogins.get(this) === attempt) {
            pendingLogins.delete(this)
            this.loading = false
          }
        })
      pendingLogins.set(this, attempt)
      return attempt
    },

    async ensure(context?: AuthContext): Promise<void> {
      // One learner for every session, so a context change needs no new token:
      // access to a session is checked by the server on that session's routes.
      // Refresh only if the token is missing or closer than 60s to expiry. No context
      // (`undefined`, screens outside a session) keeps the current one, also on a refresh.
      if (!this.token || Date.now() > this.expiresAt - 60_000) {
        await this.login(context)
        return
      }
      if (context) this.context = context
    },

    /**
     * The "account restored" sheet is about to show (R11b): hands over the notice once per session and tells the server so that
     * the bot's `/start` stays silent. A failed call is ignored - the notice may come once more in a later session, which is harmless.
     */
    takeAccountRestored(): AccountRestored | null {
      const notice = this.accountRestored
      if (notice === null || this.restoredShown) return null
      this.restoredShown = true
      this.accountRestored = null
      void api.me.accountRestoredSeen().catch(() => {})
      return notice
    },

    logout(): void {
      pendingLogins.delete(this)
      this.loading = false
      this.token = ''
      this.expiresAt = 0
      this.learner = null
      this.context = null
      this.error = null
    },
  },
})
