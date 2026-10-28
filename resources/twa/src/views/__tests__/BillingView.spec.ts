import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import BillingView from '../BillingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useToast } from '@/composables/useToast'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import type { BillingCatalog, MeResponse, Renewal, SubscriptionSummary } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

const state = vi.hoisted(() => ({ ensure: vi.fn(), back: undefined as undefined | (() => void), hideBack: vi.fn() }))

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn(), checkout: vi.fn(), order: vi.fn(), cancel: vi.fn(), resume: vi.fn() },
    training: { open: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    openInvoice: vi.fn(),
    openLink: vi.fn(),
    showBackButton: (handler: () => void) => {
      state.back = handler
      return state.hideBack
    },
  }),
}))

interface Setup {
  plan?: 'free' | 'pro'
  source?: string
  until?: string | null
  renewal?: Renewal | null
  mode?: 'coming_soon' | 'purchase'
  contact?: string | null
  /** New words a day of a Free learner. */
  limit?: number
}

const me = ({ plan = 'free', source, until = null, renewal = null, mode = 'purchase', contact = null, limit = 20 }: Setup = {}): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan, source: source ?? (plan === 'pro' ? 'subscription' : 'free'), until, new_words_per_day: plan === 'pro' ? null : limit, renewal },
  paywall: { mode, contact },
  settings: settingsFixture(),
})

const summary = (overrides: Partial<SubscriptionSummary> = {}): SubscriptionSummary => ({
  status: 'active', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-12-01T00:00:00+00:00', auto_renew: true, can_cancel: true, can_resume: false, perpetual: false, ...overrides,
})

const catalog = (subscription: SubscriptionSummary | null = null, items = true): BillingCatalog => ({
  items: items ? [{ plan: 'pro', tier: null, period: 'month', prices: { XTR: 150 }, list_prices: { XTR: 150 } }] : [],
  providers: ['telegram_stars'],
  offer: null,
  subscription,
})

const offerCatalog = (): BillingCatalog => ({
  items: [{ plan: 'pro', tier: null, period: 'month', prices: { XTR: 105, UZS: 2_030_000 }, list_prices: { XTR: 150, UZS: 2_900_000 } }],
  providers: ['telegram_stars', 'payme'],
  offer: { kind: 'post_org_discount', discount_pct: 30, period: 'month', expires_at: '2026-11-02T12:00:00+00:00' },
  subscription: null,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: BillingView },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })

async function render(response: MeResponse, cat: BillingCatalog) {
  vi.mocked(api.me.get).mockResolvedValue(response)
  vi.mocked(api.billing.catalog).mockResolvedValue(cat)
  const router = makeRouter()
  await router.push('/billing')
  await router.isReady()
  const wrapper = mount(BillingView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

// Noon UTC: the same calendar day in every time zone a developer may run the tests in.
const renewing: Renewal = { auto: true, provider: 'telegram_stars', ends_at: '2026-12-01T12:00:00+00:00', reminder_sent: false }

describe('BillingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.today.get).mockResolvedValue({} as never)
    useToast().hide()
  })
  afterEach(() => {
    useToast().hide()
    setLocale('ru')
  })

  describe('the plan card', () => {
    it('shows a Free learner the plan, the Free chip and what Free gives', async () => {
      const { wrapper } = await render(me(), catalog())

      expect(wrapper.get('h1').text()).toBe('Подписка')
      expect(wrapper.get('[data-testid=plan-name]').text()).toBe('Бесплатный тариф')
      expect(wrapper.get('[data-testid=plan-chip]').text()).toBe('Free')
      expect(wrapper.get('[data-testid=plan-chip]').classes()).toContain('chip-neutral')
      expect(wrapper.get('[data-testid=plan-free]').text()).toContain('20 новых слов в день')
      expect(wrapper.find('[data-testid=plan-until]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
    })

    it.each([
      [1, 'Бесплатный тариф: 1 новое слово в день'],
      [2, 'Бесплатный тариф: 2 новых слова в день'],
      [5, 'Бесплатный тариф: 5 новых слов в день'],
    ])('inflects the word for a limit of %i', async (limit, text) => {
      const { wrapper } = await render(me({ limit }), catalog())

      expect(wrapper.get('[data-testid=plan-free]').text()).toBe(text)
    })

    it('says the limit of Free in Uzbek', async () => {
      setLocale('uz')
      const { wrapper } = await render(me({ limit: 1 }), catalog())

      expect(wrapper.get('[data-testid=plan-free]').text()).toBe("Bepul tarif: kuniga 1 ta yangi so'z")
    })

    it('has a purple tile with the sparkles icon', async () => {
      const { wrapper } = await render(me(), catalog())

      const tile = wrapper.get('[data-testid=plan-card] .icon-tile')
      expect(tile.classes()).toContain('tile-purple')
      expect(tile.find('svg').exists()).toBe(true)
    })

    it('shows Pro with its end, the provider and the running auto-renewal with a cancel button only', async () => {
      const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

      expect(wrapper.get('[data-testid=plan-name]').text()).toBe('LexiFlow Pro')
      expect(wrapper.get('[data-testid=plan-chip]').text()).toBe('Pro')
      expect(wrapper.get('[data-testid=plan-chip]').classes()).toContain('chip-accent')
      expect(wrapper.get('[data-testid=plan-until]').text()).toBe('Действует до 1 декабря 2026 г.')
      expect(wrapper.get('[data-testid=plan-provider]').text()).toBe('Оплата: Telegram Stars.')
      expect(wrapper.find('[data-testid=plan-free]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=renewal-state]').text()).toBe('Автопродление включено: следующий период Telegram спишет сам.')
      expect(wrapper.find('[data-testid=resume-renewal]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=buy]').exists()).toBe(false) // it renews by itself: nothing to pay
    })

    it('says Pro comes from the center when it is sponsored', async () => {
      const { wrapper } = await render(me({ plan: 'pro', source: 'sponsor:organization:3', until: null }), catalog())

      expect(wrapper.get('[data-testid=plan-sponsored]').text()).toBe('Pro подключён вашим центром.')
      expect(wrapper.get('[data-testid=plan-until]').text()).toBe('Без ограничения по сроку.')
    })
  })

  describe('what Pro gives', () => {
    it('lists the five benefits of Pro under «What Pro gives» for a Free learner', async () => {
      const { wrapper } = await render(me(), catalog())

      expect(wrapper.get('[data-testid=benefits-title]').text()).toBe('Что даёт Pro')
      expect(wrapper.findAll('[data-testid=benefit]').map((b) => b.text())).toEqual([
        'Новые слова без дневного лимита',
        'Написание слова и пропуски в примерах',
        'До 20 своих колод по 2000 слов',
        'Заморозки серии: пропуск дня не обрывает серию',
        'Пробные тесты IELTS и SAT',
      ])
    })

    it('calls the same list «Your benefits» for a Pro learner', async () => {
      const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

      expect(wrapper.get('[data-testid=benefits-title]').text()).toBe('Ваши возможности')
      expect(wrapper.findAll('[data-testid=benefit]')).toHaveLength(5)
    })

    it('promises no statistics: there is no such feature', async () => {
      const { wrapper } = await render(me(), catalog())

      expect(wrapper.text().toLowerCase()).not.toContain('статистик')
    })
  })

  describe('buying', () => {
    it('offers a Free learner a big primary «Buy Pro» button', async () => {
      const { wrapper } = await render(me(), catalog())

      const buy = wrapper.get('[data-testid=buy]')
      expect(buy.text()).toBe('Купить Pro')
      expect(buy.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      expect(wrapper.find('[data-testid=unavailable]').exists()).toBe(false)
    })

    it('says payment is not open yet while nothing is for sale', async () => {
      const { wrapper } = await render(me({ mode: 'coming_soon' }), catalog(null, false))

      expect(wrapper.find('[data-testid=buy]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=unavailable]').text()).toBe('Оплата скоро откроется.')
    })

    it('opens the purchase paywall from the buy button', async () => {
      const { wrapper } = await render(me(), catalog())

      await wrapper.get('[data-testid=buy]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=paywall-title]').text()).toBe('LexiFlow Pro')

      await wrapper.get('[data-testid=paywall-close]').trigger('click')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    })

    it('does not ask /me again for the paywall it has just loaded itself', async () => {
      const { wrapper } = await render(me(), catalog())
      expect(api.me.get).toHaveBeenCalledTimes(1)

      await wrapper.get('[data-testid=buy]').trigger('click')
      await flushPromises()

      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('shows no buy button for a perpetual Pro grant', async () => {
      const { wrapper } = await render(
        me({ plan: 'pro', until: null }),
        catalog(summary({ provider: 'manual', ends_at: null, auto_renew: false, can_cancel: false, can_resume: false, perpetual: true })),
      )

      expect(wrapper.find('[data-testid=buy]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
    })

    it('does not offer to cancel a Payme period or a promo grant, only to renew', async () => {
      const { wrapper } = await render(
        me({ plan: 'pro', until: '2026-11-20T00:00:00+00:00', renewal: { auto: false, provider: 'payme', ends_at: '2026-11-20T00:00:00+00:00', reminder_sent: false } }),
        catalog(summary({ provider: 'payme', auto_renew: false, can_cancel: false, can_resume: false })),
      )

      expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=plan-provider]').text()).toBe('Оплата: Payme.')
      expect(wrapper.get('[data-testid=buy]').text()).toBe('Продлить Pro')
    })

    it('renews for a Pro learner without telling them in the sheet that they are on Free', async () => {
      const ends = '2026-11-20T00:00:00+00:00'
      const { wrapper } = await render(
        me({ plan: 'pro', until: ends, renewal: { auto: false, provider: 'payme', ends_at: ends, reminder_sent: false } }),
        catalog(summary({ provider: 'payme', ends_at: ends, auto_renew: false, can_cancel: false, can_resume: false })),
      )
      expect(wrapper.get('[data-testid=buy]').text()).toBe('Продлить Pro')

      await wrapper.get('[data-testid=buy]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=paywall-free]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=paywall]').text()).not.toContain('тариф Free')
      expect(wrapper.find('[data-testid=pay-telegram_stars]').exists()).toBe(true) // the sheet still sells the renewal
    })

    it('shows "Pro ended" to a Free learner whose subscription lapsed: the banner and the button both say renew', async () => {
      const lapsedMe = me()
      lapsedMe.entitlement.lapsed = { provider: 'payme', ended_at: '2026-10-18T09:30:00+00:00' }
      window.localStorage.clear()
      const { wrapper } = await render(lapsedMe, catalog())

      expect(wrapper.get('[data-testid=lapsed-title]').text()).toBe('Pro закончился')
      expect(wrapper.get('[data-testid=buy]').text()).toBe('Продлить Pro')

      await wrapper.get('[data-testid=lapsed-renew]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(true)
    })

    describe('"Повторить слова" in the paywall', () => {
      /** The learner opens the purchase sheet while the Home tab knows of due cards: it offers the review. */
      async function openPaywall() {
        useTodayStore().data = { due_count: 12 } as never
        const context = await render(me(), catalog())
        await context.wrapper.get('[data-testid=buy]').trigger('click')
        await flushPromises()
        return context
      }

      it('starts the daily review: the sheet closes and the training screen opens', async () => {
        vi.mocked(api.training.open).mockResolvedValue({ session_id: 63, mode: 'daily_review', lesson: null, total_words: 5 } as never)
        const { wrapper, router } = await openPaywall()
        expect(wrapper.get('[data-testid=paywall-review]').text()).toBe('Повторить слова')

        await wrapper.get('[data-testid=paywall-review]').trigger('click')
        await flushPromises()

        expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
        expect(router.currentRoute.value.name).toBe('training')
        expect(router.currentRoute.value.params.sessionId).toBe('63')
      })

      it('tells in a toast why the review did not open and stays on the subscription', async () => {
        vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'x'))
        const { wrapper, router } = await openPaywall()

        await wrapper.get('[data-testid=paywall-review]').trigger('click')
        await flushPromises()

        expect(router.currentRoute.value.name).toBe('billing')
        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
        expect(useToast().toast.value?.text).toBe('Нет связи с сервером. Попробуйте ещё раз.')
      })
    })

    it('has no banner without a lapse and none for a Pro learner', async () => {
      window.localStorage.clear()
      const free = await render(me(), catalog())
      expect(free.wrapper.find('[data-testid=lapsed-banner]').exists()).toBe(false)

      setActivePinia(createPinia())
      const proMe = me({ plan: 'pro', until: renewing.ends_at, renewal: renewing })
      proMe.entitlement.lapsed = { provider: 'payme', ended_at: '2026-10-18T09:30:00+00:00' }
      const pro = await render(proMe, catalog(summary()))
      expect(pro.wrapper.find('[data-testid=lapsed-banner]').exists()).toBe(false)
    })
  })

  describe('the auto-renewal', () => {
    it('cancels it with a secondary button, then offers a primary button to resume it and to renew by paying', async () => {
      const canceledRenewal: Renewal = { ...renewing, auto: false }
      vi.mocked(api.billing.cancel).mockResolvedValue(me({ plan: 'pro', until: renewing.ends_at, renewal: canceledRenewal }))
      const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog(summary({ status: 'canceled', auto_renew: false, can_cancel: false, can_resume: true })))
      expect(wrapper.get('[data-testid=cancel-renewal]').classes()).toEqual(expect.arrayContaining(['btn-secondary', 'btn-block']))

      await wrapper.get('[data-testid=cancel-renewal]').trigger('click')
      await flushPromises()

      expect(api.billing.cancel).toHaveBeenCalledTimes(1)
      expect(wrapper.get('[data-testid=renewal-state]').text()).toBe('Автопродление отключено — Pro действует до конца оплаченного периода.')
      expect(wrapper.find('[data-testid=cancel-renewal]').exists()).toBe(false)
      const resume = wrapper.get('[data-testid=resume-renewal]')
      expect(resume.text()).toBe('Включить автопродление')
      expect(resume.classes()).toEqual(expect.arrayContaining(['btn-primary', 'btn-block']))
      expect(wrapper.get('[data-testid=buy]').text()).toBe('Продлить Pro')
    })

    it('resumes the renewal', async () => {
      vi.mocked(api.billing.resume).mockResolvedValue(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }))
      const { wrapper } = await render(
        me({ plan: 'pro', until: renewing.ends_at, renewal: { ...renewing, auto: false } }),
        catalog(summary({ status: 'canceled', auto_renew: false, can_cancel: false, can_resume: true })),
      )
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog(summary()))

      await wrapper.get('[data-testid=resume-renewal]').trigger('click')
      await flushPromises()

      expect(api.billing.resume).toHaveBeenCalledTimes(1)
      expect(wrapper.find('[data-testid=cancel-renewal]').exists()).toBe(true)
    })

    it('shows the reason with an icon when cancel is refused and keeps the button', async () => {
      vi.mocked(api.billing.cancel).mockRejectedValue(new ApiClientError('server', 502, 'provider_unavailable', 'English'))
      const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

      await wrapper.get('[data-testid=cancel-renewal]').trigger('click')
      await flushPromises()

      const error = wrapper.get('[data-testid=renewal-error]')
      expect(error.text()).toBe('Платёжный сервис не отвечает. Попробуйте через несколько минут.')
      expect(error.attributes('role')).toBe('alert')
      expect(error.classes()).toContain('text-danger-ink')
      expect(error.find('svg').exists()).toBe(true)
      expect(wrapper.find('[data-testid=cancel-renewal]').exists()).toBe(true)
    })

    it('counts the answer of cancel as a fresh /me: nothing is asked again behind it', async () => {
      vi.mocked(api.billing.cancel).mockResolvedValue(me({ plan: 'pro', until: renewing.ends_at, renewal: { ...renewing, auto: false } }))
      const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))
      expect(api.me.get).toHaveBeenCalledTimes(1)

      await wrapper.get('[data-testid=cancel-renewal]').trigger('click')
      await flushPromises()
      await useBillingStore().load()

      expect(api.me.get).toHaveBeenCalledTimes(1)
    })
  })

  describe('a Stars renewal in grace (past_due)', () => {
    const ends = '2026-11-03T12:00:00+00:00'
    const graceMe = () => me({ plan: 'pro', until: ends, renewal: { auto: false, provider: 'telegram_stars', ends_at: ends, reminder_sent: true } })
    const graceSubscription = () => summary({ status: 'past_due', ends_at: ends, auto_renew: false, can_cancel: false, can_resume: false })

    it('hides the purchase the server would refuse and says what to do instead, with the date', async () => {
      const { wrapper } = await render(graceMe(), catalog(graceSubscription()))

      expect(wrapper.find('[data-testid=buy]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=unavailable]').exists()).toBe(false)
      const alert = wrapper.get('[data-testid=grace-alert]')
      expect(alert.text()).toBe(
        'Telegram не смог списать Stars за продление. Пополните баланс Stars — Telegram повторит списание. Pro действует до 3 ноября 2026 г.',
      )
      expect(alert.attributes('role')).toBe('status')
      expect(alert.classes()).toContain('text-warning-ink')
    })

    it('keeps the plan card, shows no cancel or resume button, and offers no discount to pay for', async () => {
      const withOffer = { ...offerCatalog(), subscription: graceSubscription() }
      const { wrapper } = await render(graceMe(), withOffer)

      expect(wrapper.get('[data-testid=plan-chip]').text()).toBe('Pro')
      expect(wrapper.get('[data-testid=plan-until]').text()).toBe('Действует до 3 ноября 2026 г.')
      expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=offer-card]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    })

    it('has one primary button at most: none here, the promo field stays', async () => {
      const { wrapper } = await render(graceMe(), catalog(graceSubscription()))

      expect(wrapper.findAll('.btn-primary')).toHaveLength(0)
      expect(wrapper.find('[data-testid=promo-card]').exists()).toBe(true)
    })

    it('says it in Uzbek', async () => {
      setLocale('uz')
      const { wrapper } = await render(graceMe(), catalog(graceSubscription()))

      expect(wrapper.get('[data-testid=grace-alert]').text()).toContain("Telegram yangilash uchun Stars'ni yecha olmadi.")
    })

    it('is not shown for a running renewal, which has its own card', async () => {
      const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

      expect(wrapper.find('[data-testid=grace-alert]').exists()).toBe(false)
    })
  })

  describe('the discount offer', () => {
    it('shows the discount: the chip, the offer text and the new price next to the struck-out list price per provider', async () => {
      const { wrapper } = await render(me(), offerCatalog())

      const chip = wrapper.get('[data-testid=offer-chip]')
      expect(chip.text()).toBe('−30%')
      expect(chip.classes()).toEqual(expect.arrayContaining(['chip', 'chip-warning']))
      expect(wrapper.get('[data-testid=offer-text]').text()).toMatch(/^Скидка 30% на первый платёж — до 2 ноября 2026 г\.$/)
      expect(wrapper.get('[data-testid=offer-price-telegram_stars]').text()).toContain('Telegram Stars')
      expect(wrapper.get('[data-testid=offer-price-telegram_stars]').text()).toContain('105 ⭐')
      expect(wrapper.get('[data-testid=offer-price-telegram_stars] s').text()).toBe('150 ⭐')
      expect(wrapper.get('[data-testid=offer-price-payme] s').text()).toContain('29')
      expect(wrapper.get('[data-testid=offer-price-payme] strong').text()).toContain('20')
    })

    it('shows no discount block without an offer, nor while the renewal runs', async () => {
      const plain = await render(me(), catalog())
      expect(plain.wrapper.find('[data-testid=offer-card]').exists()).toBe(false)

      setActivePinia(createPinia())
      const withSub = offerCatalog()
      withSub.subscription = summary()
      const renewingView = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), withSub)
      expect(renewingView.wrapper.find('[data-testid=offer-card]').exists()).toBe(false)
    })
  })

  describe('the promo code', () => {
    it('has its own card with the title and the collapsed field, after the buy button', async () => {
      const { wrapper } = await render(me(), catalog())

      const card = wrapper.get('[data-testid=promo-card]')
      expect(card.get('h2').text()).toBe('Промокод')
      expect(card.get('[data-testid=promo-toggle]').text()).toBe('Есть промокод?')
      expect(card.find('[data-testid=promo-input]').exists()).toBe(false)

      const html = wrapper.html()
      expect(html.indexOf('data-testid="buy"')).toBeLessThan(html.indexOf('data-testid="promo-card"'))
    })

    it('redeems a code: the plan card turns into Pro at once, and the catalog is asked again', async () => {
      const pro = me({ plan: 'pro', until: '2026-12-31T12:00:00+00:00' })
      vi.mocked(api.promo.redeem).mockResolvedValue(pro)
      const { wrapper } = await render(me(), catalog())
      expect(wrapper.get('[data-testid=plan-name]').text()).toBe('Бесплатный тариф')
      vi.mocked(api.billing.catalog).mockClear()

      await wrapper.get('[data-testid=promo-toggle]').trigger('click')
      await wrapper.get('[data-testid=promo-input]').setValue('PRO30')
      await wrapper.get('[data-testid=promo-card] form').trigger('submit')
      await flushPromises()

      expect(api.promo.redeem).toHaveBeenCalledWith('PRO30')
      expect(wrapper.get('[data-testid=promo-success]').text()).toMatch(/^Pro активен до .*2026/)
      expect(wrapper.get('[data-testid=plan-name]').text()).toBe('LexiFlow Pro')
      expect(wrapper.get('[data-testid=plan-chip]').text()).toBe('Pro')
      expect(wrapper.get('[data-testid=benefits-title]').text()).toBe('Ваши возможности')
      expect(api.billing.catalog).toHaveBeenCalledTimes(1)
      // the answer is a fresh /me: nothing is asked again behind it
      expect(api.me.get).toHaveBeenCalledTimes(1)
      await useBillingStore().load()
      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('keeps the plan and shows the error when the code is refused', async () => {
      vi.mocked(api.promo.redeem).mockRejectedValue(new ApiClientError('not_found', 404, 'promo_not_found', 'x'))
      const { wrapper } = await render(me(), catalog())

      await wrapper.get('[data-testid=promo-toggle]').trigger('click')
      await wrapper.get('[data-testid=promo-input]').setValue('NOPE')
      await wrapper.get('[data-testid=promo-card] form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-testid=promo-error]').text()).toBe('Промокод не найден. Проверьте, нет ли опечатки.')
      expect(wrapper.get('[data-testid=plan-name]').text()).toBe('Бесплатный тариф')
    })

    it('is there also for a learner who already has Pro', async () => {
      const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

      expect(wrapper.find('[data-testid=promo-card]').exists()).toBe(true)
    })
  })

  describe('the support contact', () => {
    it('links a Telegram handle under the cards', async () => {
      const { wrapper } = await render(me({ contact: '@lexiflow_support' }), catalog())

      expect(wrapper.get('[data-testid=billing-support]').text()).toBe('Нужна помощь? Напишите: @lexiflow_support')
      const link = wrapper.get('[data-testid=billing-support-link]')
      expect(link.attributes('href')).toBe('https://t.me/lexiflow_support')
      expect(link.attributes('rel')).toContain('noopener')
      expect(link.classes()).toContain('break-words')
    })

    it('shows a contact that is not a link as plain text', async () => {
      const { wrapper } = await render(me({ contact: '+998 90 123 45 67' }), catalog())

      expect(wrapper.find('[data-testid=billing-support-link]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=billing-support-text]').text()).toBe('+998 90 123 45 67')
    })

    it('is left out when the owner has not set one', async () => {
      const { wrapper } = await render(me({ contact: null }), catalog())

      expect(wrapper.find('[data-testid=billing-support]').exists()).toBe(false)
    })
  })

  describe('loading /me', () => {
    it('always asks /me on open, even when the store was filled a moment ago, and the catalog with it', async () => {
      const billing = useBillingStore()
      billing.apply(me({ plan: 'pro', until: null }))

      const { wrapper } = await render(me(), catalog())

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(api.billing.catalog).toHaveBeenCalledTimes(1)
      expect(wrapper.get('[data-testid=plan-chip]').text()).toBe('Free')
    })

    it('shows the plan it already has at once while /me is being asked again', async () => {
      useBillingStore().apply(me({ plan: 'pro', until: null }))
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>(() => {}))
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog())
      const router = makeRouter()
      await router.push('/billing')
      const wrapper = mount(BillingView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=plan-chip]').text()).toBe('Pro')
    })

    it('shows a skeleton until the plan has arrived', async () => {
      let resolve: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>((r) => (resolve = r)))
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog())
      const router = makeRouter()
      await router.push('/billing')
      const wrapper = mount(BillingView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=plan-card]').exists()).toBe(false)

      resolve(me())
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=plan-card]').exists()).toBe(true)
    })

    it('offers a retry when the learner cannot be authenticated', async () => {
      state.ensure.mockRejectedValueOnce(new ApiClientError('network', 0, 'x', 'x'))
      const { wrapper } = await render(me(), catalog())

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)

      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=plan-card]').exists()).toBe(true)
    })

    it('shows an error with a retry, not a spinner for ever, when /me cannot be loaded; then recovers', async () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      vi.mocked(api.me.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'down')).mockResolvedValue(me())
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog())
      const router = makeRouter()
      await router.push('/billing')
      const wrapper = mount(BillingView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=error]').text()).toContain('Что-то пошло не так.')

      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=plan-card]').exists()).toBe(true)
      vi.restoreAllMocks()
    })
  })

  describe('the Back button', () => {
    it('takes the Telegram back button to the Profile and hides it on leave', async () => {
      const { wrapper, router } = await render(me(), catalog())
      expect(state.back).toBeTypeOf('function')

      state.back?.()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('profile')

      wrapper.unmount()
      expect(state.hideBack).toHaveBeenCalled()
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(me({ contact: '@lexiflow_support' }), catalog())

    expect(wrapper.get('h1').text()).toBe('Obuna')
    expect(wrapper.get('[data-testid=plan-name]').text()).toBe('Bepul tarif')
    expect(wrapper.get('[data-testid=benefits-title]').text()).toBe('Pro nima beradi')
    expect(wrapper.get('[data-testid=buy]').text()).toBe("Pro'ni sotib olish")
    expect(wrapper.get('[data-testid=promo-card] h2').text()).toBe('Promokod')
    expect(wrapper.get('[data-testid=billing-support]').text()).toBe('Yordam kerakmi? Yozing: @lexiflow_support')
  })

  it('speaks Uzbek to a Pro learner too', async () => {
    setLocale('uz')
    const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

    expect(wrapper.get('[data-testid=benefits-title]').text()).toBe('Sizning imkoniyatlaringiz')
    expect(wrapper.get('[data-testid=plan-provider]').text()).toBe('To\'lov: Telegram Stars.')
    expect(wrapper.get('[data-testid=cancel-renewal]').text()).toBe("Avtoyangilashni o'chirish")
  })
})
