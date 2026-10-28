import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import ProfileView from '../ProfileView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import type { Entitlement, MeResponse } from '@/api/types'

const mocks = vi.hoisted(() => ({
  ensure: vi.fn(),
  learner: null as { id: number; first_name: string | null } | null,
  hideBack: vi.fn(),
  showBack: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { me: { get: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ensure: mocks.ensure,
    get learner() {
      return mocks.learner
    },
  }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: mocks.showBack, webApp: { BackButton: { hide: mocks.hideBack } } }),
}))

const me = (entitlement: Partial<Entitlement> = {}, contact: string | null = '@lexiflow_help'): MeResponse => ({
  learner: { id: 1, first_name: 'Асадбек', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, ...entitlement },
  paywall: { mode: 'purchase', contact },
  settings: settingsFixture(),
})

const PRO = { plan: 'pro', source: 'subscription', new_words_per_day: null } as const

async function render() {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['profile', 'billing', 'settings', 'referrals', 'achievements', 'friends'].map((name) => ({
      path: `/${name}`,
      name,
      component: stub,
    })),
  })
  await router.push('/profile')
  const wrapper = mount(ProfileView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('ProfileView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mocks.ensure.mockResolvedValue(undefined)
    mocks.learner = { id: 1, first_name: 'Асадбек' }
    vi.mocked(api.me.get).mockResolvedValue(me())
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(() => {
    setLocale('ru')
    vi.restoreAllMocks()
  })

  describe('Free learner', () => {
    it('shows the name with an initial avatar, the Free chip and the daily limit', async () => {
      const w = await render()

      expect(mocks.ensure).toHaveBeenCalledTimes(1)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(w.get('[data-testid=profile-name]').text()).toBe('Асадбек')
      expect(w.get('[data-testid=profile-avatar]').text()).toBe('А')
      expect(w.get('[data-testid=profile-chip]').text()).toBe('Free')
      expect(w.get('[data-testid=profile-chip]').classes()).toContain('chip-neutral')
      expect(w.get('[data-testid=profile-plan]').text()).toBe('Бесплатный тариф: 20 новых слов в день')
    })

    it('has no spinner and no error once loaded', async () => {
      const w = await render()

      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.find('[data-testid=error]').exists()).toBe(false)
    })

    it('offers Pro: the pitch card with a primary button to the subscription', async () => {
      const w = await render()

      const cta = w.get('[data-testid=profile-pro-cta]')
      expect(w.find('[data-testid=profile-pro-card]').exists()).toBe(true)
      expect(cta.text()).toBe('Подробнее о Pro')
      expect(cta.element.tagName).toBe('A')
      expect(cta.attributes('href')).toBe('/billing')
      expect(cta.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary']))
    })

    it('inflects the daily limit by the number', async () => {
      for (const [count, text] of [
        [1, 'Бесплатный тариф: 1 новое слово в день'],
        [3, 'Бесплатный тариф: 3 новых слова в день'],
        [5, 'Бесплатный тариф: 5 новых слов в день'],
      ] as const) {
        setActivePinia(createPinia())
        vi.mocked(api.me.get).mockResolvedValue(me({ new_words_per_day: count }))

        const w = await render()

        expect(w.get('[data-testid=profile-plan]').text()).toBe(text)
      }
    })

    it('falls back to a plain "free plan" when the server sends no limit', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ new_words_per_day: null }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Бесплатный тариф')
    })
  })

  describe('Pro learner', () => {
    it('shows the end date, the Pro chip and no pitch card', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, until: '2026-12-01T12:00:00+00:00' }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro до 1 декабря 2026 г.')
      expect(w.get('[data-testid=profile-chip]').text()).toBe('Pro')
      expect(w.get('[data-testid=profile-chip]').classes()).toContain('chip-accent')
      expect(w.find('[data-testid=profile-pro-cta]').exists()).toBe(false)
      expect(w.find('[data-testid=profile-pro-card]').exists()).toBe(false)
      expect(w.get('[data-testid=row-billing]').text()).toContain('Pro')
    })

    it('prefers the end of the own subscription over the entitlement end', async () => {
      vi.mocked(api.me.get).mockResolvedValue(
        me({
          ...PRO,
          until: '2026-12-01T12:00:00+00:00',
          renewal: { auto: true, provider: 'telegram_stars', ends_at: '2027-01-15T12:00:00+00:00', reminder_sent: false },
        }),
      )

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro до 15 января 2027 г.')
    })

    it('says "without a term" for a perpetual Pro', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, until: null }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro без срока')
    })

    it('says "from the center" for a Pro the learning center pays for', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, source: 'sponsor:organization:9', until: null }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro от центра')
    })
  })

  describe('rows', () => {
    it('links the five entries to their screens', async () => {
      const w = await render()

      const hrefs: Record<string, string> = {
        'row-achievements': '/achievements',
        'row-referrals': '/referrals',
        'row-friends': '/friends',
        'row-billing': '/billing',
        'row-settings': '/settings',
      }
      for (const [id, href] of Object.entries(hrefs)) {
        const row = w.get(`[data-testid=${id}]`)
        expect(row.element.tagName, id).toBe('A')
        expect(row.attributes('href'), id).toBe(href)
        expect(row.find('.icon-tile').exists(), id).toBe(true)
      }
    })

    it('labels them and explains the invitation reward', async () => {
      const w = await render()

      expect(w.get('[data-testid=row-achievements]').text()).toBe('Достижения')
      expect(w.get('[data-testid=row-referrals]').text()).toContain('Пригласить друга')
      expect(w.get('[data-testid=row-referrals]').text()).toContain('Вам и другу — Pro бесплатно')
      expect(w.get('[data-testid=row-friends]').text()).toBe('Друзья и вызовы')
      expect(w.get('[data-testid=row-billing]').text()).toBe('ПодпискаFree')
      expect(w.get('[data-testid=row-settings]').text()).toBe('Настройки')
    })

    it('colours the tiles as designed (docs/14 §5.2)', async () => {
      const w = await render()

      const tile = (id: string) => w.get(`[data-testid=${id}] .icon-tile`).classes()
      expect(tile('row-achievements')).toContain('tile-yellow')
      expect(tile('row-referrals')).toContain('tile-pink')
      expect(tile('row-friends')).toContain('tile-blue')
      expect(tile('row-billing')).toContain('tile-purple')
      expect(tile('row-settings')).toContain('tile-gray')
    })
  })

  describe('header', () => {
    it('uses the first letter, capitalised, of a trimmed name', async () => {
      mocks.learner = { id: 1, first_name: '  дилноза ' }

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('дилноза')
      expect(w.get('[data-testid=profile-avatar]').text()).toBe('Д')
    })

    it('keeps an emoji initial whole', async () => {
      mocks.learner = { id: 1, first_name: '🦊 Fox' }

      const w = await render()

      expect(w.get('[data-testid=profile-avatar]').text()).toBe('🦊')
    })

    it('without a name shows "Ученик" and the user icon instead of a letter', async () => {
      mocks.learner = { id: 1, first_name: null }

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('Ученик')
      const avatar = w.get('[data-testid=profile-avatar]')
      expect(avatar.element.tagName.toLowerCase()).toBe('svg')
      expect(avatar.text()).toBe('')
    })

    it('treats a blank name as no name', async () => {
      mocks.learner = { id: 1, first_name: '   ' }

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('Ученик')
    })

    it('copes with a missing learner', async () => {
      mocks.learner = null

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('Ученик')
    })
  })

  describe('support contact', () => {
    it('links a Telegram handle', async () => {
      const w = await render()

      const link = w.get('[data-testid=profile-support-link]')
      expect(w.get('[data-testid=profile-support]').text()).toBe('Нужна помощь? Напишите: @lexiflow_help')
      expect(link.attributes('href')).toBe('https://t.me/lexiflow_help')
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener noreferrer')
    })

    it('shows a contact that is not a link as plain text', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({}, 'javascript:alert(1)'))

      const w = await render()

      expect(w.find('[data-testid=profile-support-link]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-support-text]').text()).toBe('javascript:alert(1)')
    })

    it.each([null, '', '   '])('is left out when the contact is %j', async (contact) => {
      vi.mocked(api.me.get).mockResolvedValue(me({}, contact))

      const w = await render()

      expect(w.find('[data-testid=profile-support]').exists()).toBe(false)
    })
  })

  describe('navigation', () => {
    it('is a tab root: the system Back button is hidden, never shown', async () => {
      await render()

      expect(mocks.hideBack).toHaveBeenCalledTimes(1)
      expect(mocks.showBack).not.toHaveBeenCalled()
    })
  })

  describe('loading and errors', () => {
    it('shows a skeleton until the plan has arrived', async () => {
      let resolve: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>((r) => (resolve = r)))

      const w = await render()

      expect(w.find('[data-testid=spinner]').exists()).toBe(true)
      expect(w.find('[data-testid=profile-name]').exists()).toBe(false)

      resolve(me())
      await flushPromises()

      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.find('[data-testid=profile-name]').exists()).toBe(true)
    })

    it('shows the plan it already has without a skeleton, and refreshes it', async () => {
      const billing = useBillingStore()
      billing.apply(me({ ...PRO, until: null }))
      billing.invalidate() // the stored plan is older than 30 s: it is shown, and asked for again
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>(() => {}))

      const w = await render()

      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro без срока')
      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('does not ask /me again while the plan it has is fresh', async () => {
      useBillingStore().apply(me({ ...PRO, until: null }))

      const w = await render()

      expect(api.me.get).not.toHaveBeenCalled()
      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro без срока')
    })

    it('shows an error with a retry when /me fails, then recovers', async () => {
      vi.mocked(api.me.get)
        .mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'Network down'))
        .mockResolvedValueOnce(me())

      const w = await render()

      expect(w.find('[data-testid=profile-name]').exists()).toBe(false)
      expect(w.get('[data-testid=error]').text()).toContain('Что-то пошло не так.')
      expect(w.get('[data-testid=error]').text()).not.toContain('Network down')

      await w.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(api.me.get).toHaveBeenCalledTimes(2)
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-name]').text()).toBe('Асадбек')
    })

    it('shows the localized reason when the authentication fails, and retries it', async () => {
      mocks.ensure.mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'boom'))

      const w = await render()

      expect(w.get('[data-testid=error]').text()).toContain('Нет связи с сервером. Попробуйте ещё раз.')
      expect(api.me.get).not.toHaveBeenCalled()

      await w.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(mocks.ensure).toHaveBeenCalledTimes(2)
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.find('[data-testid=profile-plan]').exists()).toBe(true)
    })

    it('does not call it a failure while another screen request is still in flight', async () => {
      const billing = useBillingStore()
      billing.loading = true // e.g. the Today screen asked for /me a moment ago

      const w = await render()

      expect(api.me.get).not.toHaveBeenCalled()
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.find('[data-testid=spinner]').exists()).toBe(true)

      billing.apply(me())
      billing.loading = false
      await flushPromises()

      expect(w.find('[data-testid=profile-name]').exists()).toBe(true)
    })

    it('turns that in-flight request into an error when it fails', async () => {
      const billing = useBillingStore()
      billing.loading = true

      const w = await render()
      billing.loading = false
      await flushPromises()

      expect(w.find('[data-testid=error]').exists()).toBe(true)
    })
  })

  describe('Uzbek', () => {
    it('speaks Uzbek: plan lines, rows and support', async () => {
      setLocale('uz')

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe("Bepul tarif: kuniga 20 ta yangi so'z")
      expect(w.get('[data-testid=profile-pro-cta]').text()).toBe('Pro haqida batafsil')
      expect(w.get('[data-testid=row-achievements]').text()).toBe('Yutuqlar')
      expect(w.get('[data-testid=row-friends]').text()).toBe("Do'stlar va musobaqalar")
      expect(w.get('[data-testid=row-billing]').text()).toBe('ObunaFree')
      expect(w.get('[data-testid=row-settings]').text()).toBe('Sozlamalar')
      expect(w.get('[data-testid=profile-support]').text()).toBe('Yordam kerakmi? Yozing: @lexiflow_help')
    })

    it('puts the Uzbek date in the Pro line', async () => {
      setLocale('uz')
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, until: '2026-12-01T12:00:00+00:00' }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro 1-dekabr, 2026 gacha')
    })
  })
})
