import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import LapsedBanner from '../LapsedBanner.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { ICONS } from '@/components/ui/icons'
import { useBillingStore } from '@/stores/billing'
import { resetOfferViewed } from '@/composables/offerViewed'
import type { Lapsed, MeResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn() }, today: { get: vi.fn() }, billing: { offerViewed: vi.fn() } } }))

const ENDED = '2026-10-18T09:30:00+00:00'

const me = (lapsed: Lapsed | null, plan: 'free' | 'pro' = 'free'): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan, source: plan === 'pro' ? 'subscription' : 'free', until: null, new_words_per_day: plan === 'pro' ? null : 20, renewal: null, lapsed },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
})

const lapsed = (endedAt = ENDED): Lapsed => ({ provider: 'payme', ended_at: endedAt })

async function render(response: MeResponse, props: { preloaded?: boolean; renewable?: boolean } = {}) {
  vi.mocked(api.me.get).mockResolvedValue(response)
  const billing = useBillingStore()
  if (props.preloaded) billing.apply(response)
  const wrapper = mount(LapsedBanner, { props })
  await flushPromises()
  return wrapper
}

describe('LapsedBanner', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
  })
  afterEach(() => setLocale('ru'))

  it('tells a Free learner that Pro ended, with the date', async () => {
    const w = await render(me(lapsed()))

    expect(api.me.get).toHaveBeenCalledTimes(1)
    expect(w.get('[data-testid=lapsed-title]').text()).toBe('Pro закончился')
    expect(w.get('[data-testid=lapsed-text]').text()).toMatch(/^Подписка закончилась 18 октября\./)
    expect(w.get('[data-testid=lapsed-renew]').text()).toBe('Продлить Pro')
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = await render(me(lapsed()))

    expect(w.get('[data-testid=lapsed-title]').text()).toBe('Pro tugadi')
    expect(w.get('[data-testid=lapsed-renew]').text()).toBe("Pro'ni uzaytirish")
    expect(w.get('[data-testid=lapsed-text]').text()).toMatch(/^Obuna 18-oktabr kuni tugadi\./)
  })

  it('is a calm warning card: an amber status dot, a tinted renew button (the screen keeps one primary) and an icon-only dismiss button named "Hide"', async () => {
    const w = await render(me(lapsed()))

    expect(w.get('[data-testid=lapsed-banner]').classes()).toContain('card')
    expect(w.find('.icon-tile').exists()).toBe(false)
    const dot = w.get('[data-testid=lapsed-dot]')
    expect(dot.classes()).toContain('bg-warning')
    expect(dot.attributes('aria-hidden')).toBe('true')

    const renew = w.get('[data-testid=lapsed-renew]')
    expect(renew.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
    expect(renew.classes()).not.toContain('btn-primary')

    const dismiss = w.get('[data-testid=lapsed-dismiss]')
    expect(dismiss.attributes('aria-label')).toBe('Скрыть')
    expect(dismiss.text()).toBe('')
    expect(dismiss.get('path').attributes('d')).toBe(ICONS['x-mark'].outline[0]?.d)
  })

  it('stays hidden when nothing lapsed or Pro is in force', async () => {
    expect((await render(me(null))).find('[data-testid=lapsed-banner]').exists()).toBe(false)
    expect((await render(me(lapsed(), 'pro'))).find('[data-testid=lapsed-banner]').exists()).toBe(false)
  })

  it('hides a stale store until /me has answered', async () => {
    const billing = useBillingStore()
    billing.apply(me(lapsed()))
    billing.invalidate() // the stored answer is old (a fresh one is trusted, see below)
    // The learner has renewed since: /me no longer reports a lapse.
    vi.mocked(api.me.get).mockResolvedValue(me(null, 'pro'))

    const w = mount(LapsedBanner)
    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(false)
    await flushPromises()
    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(false)
  })

  it('shows a lapse from a store that is fresh without asking /me again', async () => {
    const billing = useBillingStore()
    billing.apply(me(lapsed()))

    const w = mount(LapsedBanner)
    await flushPromises()

    expect(api.me.get).not.toHaveBeenCalled()
    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
  })

  it('does not call /me again when the screen has preloaded it', async () => {
    const w = await render(me(lapsed()), { preloaded: true })

    expect(api.me.get).not.toHaveBeenCalled()
    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
  })

  it('has no «Renew» button when the screen says nothing is for sale: a button that does nothing is worse than none', async () => {
    const w = await render(me(lapsed()), { preloaded: true, renewable: false })

    expect(w.get('[data-testid=lapsed-title]').text()).toBe('Pro закончился')
    expect(w.get('[data-testid=lapsed-text]').text()).toMatch(/^Подписка закончилась 18 октября\./)
    expect(w.find('[data-testid=lapsed-renew]').exists()).toBe(false)
    expect(w.find('button.btn').exists()).toBe(false)
    expect(w.find('[data-testid=lapsed-dismiss]').exists()).toBe(true) // the learner can still put it away
  })

  it('offers «Renew» by default, as on the Home screen where the paywall opens either way', async () => {
    const w = await render(me(lapsed()), { renewable: true })

    expect(w.find('[data-testid=lapsed-renew]').exists()).toBe(true)
  })

  it('reports the offer of Pro once it shows with its renew button (docs/13 §5.1), not without the button', async () => {
    resetOfferViewed()
    await render(me(lapsed()), { preloaded: true, renewable: false })
    expect(api.billing.offerViewed).not.toHaveBeenCalled()

    await render(me(lapsed()), { preloaded: true })
    expect(api.billing.offerViewed).toHaveBeenCalledExactlyOnceWith('lapsed_banner')
  })

  describe('on Today (the `lapsed` prop) with `/me` not loaded yet', () => {
    beforeEach(() => resetOfferViewed())

    it('asks for /me once in the background and reports the offer when the paywall comes in purchase mode', async () => {
      let answer: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>((resolve) => {
        answer = resolve
      }))

      const w = mount(LapsedBanner, { props: { lapsed: lapsed() } })
      // The banner does not wait for /me: it shows at once.
      expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(api.billing.offerViewed).not.toHaveBeenCalled()

      await w.setProps({ lapsed: lapsed() }) // a new /today with the same lapse: no second request
      expect(api.me.get).toHaveBeenCalledTimes(1)

      answer(me(lapsed()))
      await flushPromises()

      expect(api.billing.offerViewed).toHaveBeenCalledExactlyOnceWith('lapsed_banner')
      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('reports nothing when the server sells nothing (paywall not in purchase mode)', async () => {
      vi.mocked(api.me.get).mockResolvedValue({ ...me(lapsed()), paywall: { mode: 'coming_soon', contact: null } })

      const w = mount(LapsedBanner, { props: { lapsed: lapsed() } })
      await flushPromises()

      expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(api.billing.offerViewed).not.toHaveBeenCalled()
    })

    it('swallows a failed /me: the banner stays, nothing is reported, nothing is asked again', async () => {
      vi.mocked(api.me.get).mockRejectedValue(new Error('offline'))
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

      const w = mount(LapsedBanner, { props: { lapsed: lapsed() } })
      await flushPromises()
      await w.setProps({ lapsed: lapsed() })
      await flushPromises()

      expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(api.billing.offerViewed).not.toHaveBeenCalled()
      warn.mockRestore()
    })

    it('asks nothing while the banner is hidden (no lapse, or dismissed)', async () => {
      mount(LapsedBanner, { props: { lapsed: null } })
      window.localStorage.setItem('lexiflow:lapsed-dismissed', ENDED)
      mount(LapsedBanner, { props: { lapsed: lapsed() } })
      await flushPromises()

      expect(api.me.get).not.toHaveBeenCalled()
    })

    it('uses a /me the store already has: no request, reported at once', async () => {
      useBillingStore().apply(me(lapsed()))

      mount(LapsedBanner, { props: { lapsed: lapsed() } })
      await flushPromises()

      expect(api.me.get).not.toHaveBeenCalled()
      expect(api.billing.offerViewed).toHaveBeenCalledExactlyOnceWith('lapsed_banner')
    })
  })

  it('emits renew from the button', async () => {
    const w = await render(me(lapsed()))

    await w.get('[data-testid=lapsed-renew]').trigger('click')

    expect(w.emitted('renew')).toHaveLength(1)
  })

  it('remembers the dismissal per lapse: gone next time, back after a new lapse', async () => {
    const w = await render(me(lapsed()))
    await w.get('[data-testid=lapsed-dismiss]').trigger('click')

    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(false)
    expect(window.localStorage.getItem('lexiflow:lapsed-dismissed')).toBe(ENDED)

    setActivePinia(createPinia())
    expect((await render(me(lapsed()))).find('[data-testid=lapsed-banner]').exists()).toBe(false)

    setActivePinia(createPinia())
    expect((await render(me(lapsed('2026-11-20T09:30:00+00:00')))).find('[data-testid=lapsed-banner]').exists()).toBe(true)
  })

  it('still works when the storage is unavailable', async () => {
    const get = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied')
    })
    const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied')
    })

    const w = await render(me(lapsed()))
    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
    await w.get('[data-testid=lapsed-dismiss]').trigger('click')
    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(false)

    get.mockRestore()
    set.mockRestore()
  })

  describe('an ended welcome trial', () => {
    const trial = (): Lapsed => ({ provider: 'trial', ended_at: ENDED })

    it('has its own words: trial over, buy Pro or invite friends (no numbers)', async () => {
      const w = await render(me(trial()))

      expect(w.get('[data-testid=lapsed-title]').text()).toBe('Пробный Pro закончился')
      expect(w.get('[data-testid=lapsed-text]').text()).toBe('Оформите Pro или пригласите друзей и получите Pro бесплатно.')
      expect(w.get('[data-testid=lapsed-renew]').text()).toBe('Оформить Pro')
      expect(w.get('[data-testid=lapsed-invite]').text()).toBe('Пригласить друзей')
      // the second action is a text button under the tinted one (docs/14 §4.5)
      expect(w.get('[data-testid=lapsed-invite]').classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain']))
    })

    it('emits renew and invite from its two buttons', async () => {
      const w = await render(me(trial()))

      await w.get('[data-testid=lapsed-renew]').trigger('click')
      await w.get('[data-testid=lapsed-invite]').trigger('click')

      expect(w.emitted('renew')).toHaveLength(1)
      expect(w.emitted('invite')).toHaveLength(1)
    })

    it('keeps the invite button where nothing is for sale (not renewable)', async () => {
      const w = await render(me(trial()), { preloaded: true, renewable: false })

      expect(w.find('[data-testid=lapsed-renew]').exists()).toBe(false)
      expect(w.find('[data-testid=lapsed-invite]').exists()).toBe(true)
    })

    it('speaks Uzbek', async () => {
      setLocale('uz')
      const w = await render(me(trial()))

      expect(w.get('[data-testid=lapsed-title]').text()).toBe('Pro sinov muddati tugadi')
      expect(w.get('[data-testid=lapsed-invite]').text()).toBe("Do'stlarni taklif qilish")
      expect(w.get('[data-testid=lapsed-renew]').text()).toBe("Pro'ni rasmiylashtirish")
    })

    it('is not the trial banner for a paid subscription: no invite button', async () => {
      const w = await render(me(lapsed()))

      expect(w.find('[data-testid=lapsed-invite]').exists()).toBe(false)
    })
  })
})
