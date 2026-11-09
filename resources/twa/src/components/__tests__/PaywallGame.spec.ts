import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PaywallSheet from '../PaywallSheet.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { MeResponse } from '@/api/types'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn() }, promo: { redeem: vi.fn() }, today: { get: vi.fn() } } }))

const me = (): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'coming_soon', contact: null },
  settings: settingsFixture(),
  game: { available: true, limits: null, pro_promise: 'full', coins: 0, tickets: 0 },
})

async function render(reason: 'game' | 'quota' = 'game') {
  vi.mocked(api.me.get).mockResolvedValue(me())
  const wrapper = mount(PaywallSheet, { props: { reason } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})
afterEach(() => setLocale('ru'))

describe('PaywallSheet: reason "game" (docs/05 §5b.1)', () => {
  it('says what Free and Pro have in the game, without numbers', async () => {
    const w = await render()

    const lead = w.get('[data-testid=paywall-lead]').text()
    expect(lead).toContain('Караван: бесплатный забег в день и билеты за серию и за друзей. В Pro — без ограничений.')
    expect(lead).not.toMatch(/\d/)
  })

  it('keeps the rest of the sheet: the pitch, the promo code and the dismiss button', async () => {
    const w = await render()

    expect(w.get('[data-testid=paywall-lead]').text()).toContain('Pro скоро откроется.')
    expect(w.find('[data-testid=paywall-close]').exists()).toBe(true)
  })

  it('does not put the game into the lead of other reasons', async () => {
    const w = await render('quota')

    expect(w.get('[data-testid=paywall-lead]').text()).not.toContain('Караван')
  })

  it('is said in Uzbek', async () => {
    setLocale('uz')
    const w = await render()

    expect(w.get('[data-testid=paywall-lead]').text()).toContain('Karvonda kuniga bitta bepul safar bor')
  })
})
