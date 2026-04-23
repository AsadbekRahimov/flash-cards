import { defineStore } from 'pinia'
import { api } from '@/api'
import type { Student } from '@/api/types'
import { useTelegram } from '@/composables/useTelegram'

interface AuthState {
  token: string
  expiresAt: number         // epoch ms; refresh before reaching this
  student: Student | null
  loading: boolean
  error: string | null
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    token: '',
    expiresAt: 0,
    student: null,
    loading: false,
    error: null,
  }),

  getters: {
    isAuthenticated: (s) => s.token !== '' && Date.now() < s.expiresAt,
  },

  actions: {
    async login(): Promise<void> {
      const { initData } = useTelegram()
      const payload = initData()
      if (!payload) {
        this.error = 'opened_outside_telegram'
        throw new Error('opened_outside_telegram')
      }

      this.loading = true
      this.error = null
      try {
        const res = await api.auth.authenticate(payload)
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

    async ensure(): Promise<void> {
      // Refresh if missing or closer than 60s to expiry.
      if (!this.token || Date.now() > this.expiresAt - 60_000) {
        await this.login()
      }
    },

    logout(): void {
      this.token = ''
      this.expiresAt = 0
      this.student = null
      this.error = null
    },
  },
})
