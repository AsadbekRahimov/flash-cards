import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PaywallSheet from '../PaywallSheet.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useTodayStore } from '@/stores/today'
import type { BillingCatalog, MeResponse, PracticeLimitHit } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn(), checkout: vi.fn(), order: vi.fn() },
  },
}))

/**
 * `reason = 'practice_limit'` (docs/18 §3, G1): the end of the free sessions of practice or of the free battles of today. The lead quotes the
 * numbers of the refusal and says when the counters are full again, in the zone of the learner; the rest of the sheet - purchase, promo
 * code, "coming soon", "Repeat words" - is the sheet of every other reason.
 */

/** 2026-11-02, 15:00 in Tashkent (UTC+5): the learner's "today" ends at 00:00 of the 3rd, at 19:00 UTC. */
const NOW = new Date('2026-11-02T10:00:00Z')
const MIDNIGHT = '2026-11-02T19:00:00+00:00'

const SESSIONS: PracticeLimitHit = { kind: 'sessions', limit: 2, used: 2, resets_at: MIDNIGHT }
const BATTLES: PracticeLimitHit = { kind: 'battles', limit: 1, used: 1, resets_at: MIDNIGHT }

const me = (mode: 'coming_soon' | 'purchase' = 'coming_soon', plan: 'free' | 'pro' = 'free'): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: {
    plan,
    source: plan === 'pro' ? 'subscription' : 'free',
    until: plan === 'pro' ? '2026-12-01T00:00:00+00:00' : null,
    new_words_per_day: plan === 'pro' ? null : 20,
    renewal: null,
  },
  paywall: { mode, contact: null },
  settings: settingsFixture(),
})

const catalog = (): BillingCatalog => ({
  items: [
    { plan: 'pro', tier: null, period: 'month', prices: { XTR: 150, UZS: 2_900_000 }, list_prices: { XTR: 150, UZS: 2_900_000 } },
    { plan: 'pro', tier: null, period: 'year', prices: { XTR: 1300, UZS: 24_900_000 }, list_prices: { XTR: 1300, UZS: 24_900_000 } },
  ],
  providers: ['telegram_stars', 'payme', 'click'],
  offer: null,
  subscription: null,
})

/** The learner's zone, as the Today screen has it. */
const knowZone = (timezone: string, dueCount = 0) => {
  useTodayStore().data = { timezone, due_count: dueCount } as never
}

async function render(props: { limitHit?: PracticeLimitHit | null; reason?: 'practice_limit' | 'practice' } = {}, response: MeResponse = me()) {
  vi.mocked(api.me.get).mockResolvedValue(response)
  const wrapper = mount(PaywallSheet, { props: { reason: 'practice_limit', limitHit: SESSIONS, ...props } })
  await flushPromises()
  return wrapper
}

const lead = (w: Awaited<ReturnType<typeof render>>) => w.get('[data-testid=paywall-lead]').text()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  // Only the clock of the date: `flushPromises` waits on timers, which must keep running.
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
  vi.mocked(api.today.get).mockResolvedValue({ new_words_remaining: null, due_count: 0 } as never)
  vi.mocked(api.billing.catalog).mockResolvedValue(catalog())
  knowZone('Asia/Tashkent')
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

describe('PaywallSheet — the end of the free sessions of practice', () => {
  it('says they are over, how many there were, and when the new ones come: tomorrow, at the turn of the learner\'s day', async () => {
    const w = await render()

    expect(lead(w)).toBe('Бесплатные тренировки на сегодня закончились (2 из 2). Новые — завтра в 00:00 или сразу с Pro. Pro скоро откроется.')
  })

  it('says it in Uzbek', async () => {
    setLocale('uz')
    const w = await render()

    expect(lead(w)).toBe('Bugungi bepul amaliyot tugadi (2 tadan 2 tasi). Yangilari — ertaga soat 00:00 da yoki darhol Pro bilan. Pro tez orada ochiladi.')
  })

  it('quotes the limit of the refusal twice, never "3 of 2": a limit that was lowered meanwhile is still a limit', async () => {
    const w = await render({ limitHit: { ...SESSIONS, limit: 2, used: 3 } })

    expect(lead(w)).toContain('(2 из 2)')
    expect(lead(w)).not.toContain('3')
  })

  it('takes the numbers from the refusal, not from the text', async () => {
    const w = await render({ limitHit: { ...SESSIONS, limit: 5, used: 5 } })

    expect(lead(w)).toContain('закончились (5 из 5)')
  })
})

describe('PaywallSheet — the end of the free battles', () => {
  it('says the same about the challenges', async () => {
    const w = await render({ limitHit: BATTLES })

    expect(lead(w)).toBe('Бесплатные вызовы на сегодня закончились (1 из 1). Новые — завтра в 00:00 или сразу с Pro. Pro скоро откроется.')
  })

  it('says it in Uzbek', async () => {
    setLocale('uz')
    const w = await render({ limitHit: BATTLES })

    expect(lead(w)).toBe('Bugungi bepul chaqiruvlar tugadi (1 tadan 1 tasi). Yangilari — ertaga soat 00:00 da yoki darhol Pro bilan. Pro tez orada ochiladi.')
  })
})

describe('PaywallSheet — when the counters are full again', () => {
  it('is told in the zone of the learner, not of the device: the same moment is 19:00 for a learner in UTC', async () => {
    knowZone('UTC')
    const w = await render()

    expect(lead(w)).toContain('Новые — в 19:00 или сразу с Pro.')
  })

  it('follows the zone the Today screen names, whatever the device is on', async () => {
    knowZone('Europe/Moscow') // 22:00 of the same day: an hour of today, not its turn
    const w = await render()

    expect(lead(w)).toContain('Новые — в 22:00 или сразу с Pro.')
  })

  it('uses the zone of the device while the learner\'s is not known (the Today screen was not opened in this run)', async () => {
    useTodayStore().data = null
    const w = await render()

    expect(lead(w)).toMatch(/Новые — (завтра )?в \d{2}:\d{2} или сразу с Pro\./)
  })

  it('does not claim "tomorrow" when the clock of the device is days off: the time alone is true', async () => {
    vi.setSystemTime(new Date('2026-10-28T10:00:00Z'))
    const w = await render()

    expect(lead(w)).toContain('Новые — в 00:00 или сразу с Pro.')
  })

  it('is told in the same way whenever in the day the sheet opens, up to the last minute', async () => {
    vi.setSystemTime(new Date('2026-11-02T18:59:00Z'))
    const w = await render()

    expect(lead(w)).toContain('Новые — завтра в 00:00 или сразу с Pro.')
  })
})

describe('PaywallSheet — the rest of the sheet is the sheet of every reason', () => {
  it('is the same sheet: the title, what Free gives, what Pro gives, the promo code and "Got it"', async () => {
    const w = await render()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro скоро')
    expect(w.get('[data-testid=paywall-free]').text()).toContain('20 новых слов в день')
    expect(w.findAll('[data-testid=benefit]').length).toBeGreaterThan(0)
    expect(w.find('[data-testid=promo-toggle]').exists()).toBe(true)
    expect(w.get('[data-testid=paywall-close]').text()).toBe('Понятно')
    expect(w.find('[data-testid=purchase]').exists()).toBe(false)
  })

  it('offers "Repeat words" when cards are due: reviews are never limited, the wall is not a dead end', async () => {
    knowZone('Asia/Tashkent', 5)
    const w = await render()

    expect(w.get('[data-testid=paywall-review]').text()).toBe('Повторить слова')
    expect(w.get('[data-testid=paywall-review]').classes()).toContain('btn-primary')
    await w.get('[data-testid=paywall-review]').trigger('click')
    expect(w.emitted('review')).toHaveLength(1)
  })

  it('offers no "Repeat words" when nothing is due', async () => {
    const w = await render()

    expect(w.find('[data-testid=paywall-review]').exists()).toBe(false)
  })

  it('closes with the button, the cross and the backdrop', async () => {
    const w = await render()

    await w.get('[data-testid=paywall-close]').trigger('click')
    await w.get('[data-testid=paywall-x]').trigger('click')
    await w.get('[data-testid=paywall-backdrop]').trigger('click')

    expect(w.emitted('close')).toHaveLength(3)
  })

  it('sells Pro when it is for sale: the reason first, then what Pro gives, the pay buttons, the promo code and "Repeat words"', async () => {
    knowZone('Asia/Tashkent', 3)
    const w = await render({}, me('purchase'))

    expect(w.get('[data-testid=paywall-title]').text()).toBe('LexiFlow Pro')
    expect(lead(w)).toBe(
      'Бесплатные тренировки на сегодня закончились (2 из 2). Новые — завтра в 00:00 или сразу с Pro. ' +
        'Новые слова без дневного лимита, упражнения на написание, свои колоды, заморозки серии и пробные тесты.',
    )
    expect(w.findAll('[data-testid^=pay-]').filter((button) => button.element.tagName === 'BUTTON')).toHaveLength(3)
    expect(w.get('[data-testid=paywall-review]').classes()).toContain('btn-tinted')
    expect(w.find('[data-testid=promo-toggle]').exists()).toBe(true)
  })

  it('does not tell a Pro learner they are on Free', async () => {
    const w = await render({}, me('coming_soon', 'pro'))

    expect(w.find('[data-testid=paywall-free]').exists()).toBe(false)
  })

  it('gives way to the promo code: a learner who activates Pro sees the plan, not the end of the free sessions', async () => {
    vi.mocked(api.promo.redeem).mockResolvedValue(me('coming_soon', 'pro'))
    const w = await render()

    await w.get('[data-testid=promo-toggle]').trigger('click')
    await w.get('[data-testid=promo-input]').setValue('PRO30')
    await w.get('form').trigger('submit')
    await flushPromises()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro')
    expect(w.find('[data-testid=paywall-lead]').exists()).toBe(false)
  })

  it('does not promise what is not there: no word of statistics, in both languages', async () => {
    for (const locale of ['ru', 'uz'] as const) {
      for (const hit of [SESSIONS, BATTLES]) {
        setActivePinia(createPinia())
        knowZone('Asia/Tashkent')
        setLocale(locale)
        const w = await render({ limitHit: hit })

        expect(w.text().toLowerCase(), `${locale}/${hit.kind}`).not.toMatch(/статистик|statistik/)
      }
    }
  })
})

describe('PaywallSheet — without the numbers of a refusal', () => {
  it('says what the sheet of the practice says: nothing is quoted that is not known', async () => {
    const w = await render({ limitHit: null })

    expect(lead(w)).toBe('Практика — игры на закрепление слов и баттлы с друзьями — доступна в Pro. Pro скоро откроется.')
  })

  it('the other reasons ignore the numbers they are given', async () => {
    const w = await render({ reason: 'practice', limitHit: SESSIONS })

    expect(lead(w)).toBe('Практика — игры на закрепление слов и баттлы с друзьями — доступна в Pro. Pro скоро откроется.')
  })
})
