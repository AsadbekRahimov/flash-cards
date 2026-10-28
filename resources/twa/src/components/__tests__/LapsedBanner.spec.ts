import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import LapsedBanner from '../LapsedBanner.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { ICONS } from '@/components/ui/icons'
import { useBillingStore } from '@/stores/billing'
import type { Lapsed, MeResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn() }, today: { get: vi.fn() } } }))

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

  it('is a warning card: a clock tile, a tinted renew button (the screen keeps one primary) and an icon-only dismiss button named "Hide"', async () => {
    const w = await render(me(lapsed()))

    const tile = w.get('.icon-tile')
    expect(tile.classes()).toContain('bg-warning')
    expect(tile.get('path').attributes('d')).toBe(ICONS.clock.outline[0]?.d)

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
})
