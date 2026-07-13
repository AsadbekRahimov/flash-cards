import { defineStore } from 'pinia'
import { api } from '@/api'
import type { AuthContext, Student } from '@/api/types'
import { useTelegram } from '@/composables/useTelegram'

interface AuthState {
  token: string
  expiresAt: number         // epoch ms; refresh before reaching this
  student: Student | null
  context: AuthContext | null
  loading: boolean
  error: string | null
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    token: '',
    expiresAt: 0,
    student: null,
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
        this.student = res.student
      } catch (err: unknown) {
        this.error = (err as { code?: string })?.code ?? 'auth_failed'
        throw err
      } finally {
        this.loading = false
      }
    },

    async ensure(context: AuthContext): Promise<void> {
      // Refresh if missing or closer than 60s to expiry.
      const contextChanged = this.context?.type !== context.type
        || this.context?.sessionId !== context.sessionId
      if (!this.token || contextChanged || Date.now() > this.expiresAt - 60_000) {
        await this.login(context)
      }
    },

    logout(): void {
      this.token = ''
      this.expiresAt = 0
      this.student = null
      this.context = null
      this.error = null
    },
  },
})
