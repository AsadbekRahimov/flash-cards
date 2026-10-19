import { defineStore } from 'pinia'
import { api } from '@/api'
import type { AuthContext, Learner } from '@/api/types'
import { useTelegram } from '@/composables/useTelegram'
import { setLocale, SUPPORTED_LOCALES, type Locale } from '@/i18n'

interface AuthState {
  token: string
  expiresAt: number         // epoch ms; refresh before reaching this
  learner: Learner | null
  context: AuthContext | null
  loading: boolean
  error: string | null
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    token: '',
    expiresAt: 0,
    learner: null,
    context: null,
    loading: false,
    error: null,
  }),

  getters: {
    isAuthenticated: (s) => s.token !== '' && Date.now() < s.expiresAt,
  },

  actions: {
    async login(context?: AuthContext | null): Promise<void> {
      const { initData } = useTelegram()
      const payload = initData()
      if (!payload) {
        this.error = 'opened_outside_telegram'
        throw new Error('opened_outside_telegram')
      }

      this.loading = true
      this.error = null
      const resolvedContext = context === undefined ? this.context : context
      this.context = resolvedContext
      try {
        const res = await api.auth.authenticate(payload, resolvedContext)
        this.token = res.token
        this.expiresAt = Date.now() + res.expires_in * 1000
        this.learner = res.learner
        // The learner's own choice (onboarding / settings) beats Telegram's language_code picked at startup.
        if ((SUPPORTED_LOCALES as readonly string[]).includes(res.learner.locale)) setLocale(res.learner.locale as Locale)
      } catch (err: unknown) {
        this.error = (err as { code?: string })?.code ?? 'auth_failed'
        throw err
      } finally {
        this.loading = false
      }
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

    logout(): void {
      this.token = ''
      this.expiresAt = 0
      this.learner = null
      this.context = null
      this.error = null
    },
  },
})
