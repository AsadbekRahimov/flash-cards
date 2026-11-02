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
 * numbers of the refusal and says when the counters are full again, in the zone of the learner; "or right away with Pro" is said only while
 * Pro is for sale. The rest of the sheet - purchase, promo code, "coming soon", "Repeat words" - is the sheet of every other reason.
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
  // A learner who meets the end of a free limit has practice: the sheet offers the line about it among the things Pro gives.
  practice: { available: true, pro_required: false, modes: ['pairs', 'collocation', 'sentence', 'dictation'], battle: true },
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

/** What the end of the sale adds after the reason, in each mode: `Pro opens soon`, or what Pro gives. */
const SOON_RU = 'Pro скоро откроется.'
const SALE_RU = 'Новые слова без дневного лимита, упражнения на написание, свои колоды, заморозки серии и пробные тесты.'
const SOON_UZ = 'Pro tez orada ochiladi.'
const SALE_UZ = "Yangi so'zlar kunlik limitsiz, so'zni yozish mashqlari, o'z to'plamlaringiz, seriyani muzlatish va sinov testlari."

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

describe('PaywallSheet — the end of the free practice', () => {
  it('while Pro is only coming: says it is over, how many there were and when the new one comes - tomorrow, at the turn of the learner\'s day - and promises nothing', async () => {
    const w = await render()

    expect(lead(w)).toBe(`Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00. ${SOON_RU}`)
  })

  it('while Pro is only coming, in Uzbek', async () => {
    setLocale('uz')
    const w = await render()

    expect(lead(w)).toBe(`Bugungi bepul amaliyot tugadi (2 tadan 2 tasi). Yangisi — ertaga soat 00:00 da. ${SOON_UZ}`)
  })

  it('while Pro is for sale: "or right away with Pro", then what Pro gives', async () => {
    const w = await render({}, me('purchase'))

    expect(lead(w)).toBe(`Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00 или сразу с Pro. ${SALE_RU}`)
  })

  it('while Pro is for sale, in Uzbek', async () => {
    setLocale('uz')
    const w = await render({}, me('purchase'))

    expect(lead(w)).toBe(`Bugungi bepul amaliyot tugadi (2 tadan 2 tasi). Yangisi — ertaga soat 00:00 da yoki darhol Pro bilan. ${SALE_UZ}`)
  })

  it('quotes the limit of the refusal twice, never "3 of 2": a limit that was lowered meanwhile is still a limit', async () => {
    const w = await render({ limitHit: { ...SESSIONS, limit: 2, used: 3 } })

    expect(lead(w)).toContain('(2 из 2)')
    expect(lead(w)).not.toContain('3')
  })

  it('takes the numbers from the refusal, not from the text', async () => {
    const w = await render({ limitHit: { ...SESSIONS, limit: 5, used: 5 } })

    expect(lead(w)).toContain('закончилась (5 из 5)')
  })
})

describe('PaywallSheet — the end of the free battles', () => {
  it('while Pro is only coming: says the same about the battles', async () => {
    const w = await render({ limitHit: BATTLES })

    expect(lead(w)).toBe(`Бесплатные баттлы на сегодня закончились (1 из 1). Новые — завтра в 00:00. ${SOON_RU}`)
  })

  it('while Pro is only coming, in Uzbek', async () => {
    setLocale('uz')
    const w = await render({ limitHit: BATTLES })

    expect(lead(w)).toBe(`Bugungi bepul bellashuvlar tugadi (1 tadan 1 tasi). Yangilari — ertaga soat 00:00 da. ${SOON_UZ}`)
  })

  it('while Pro is for sale: "or right away with Pro", then what Pro gives', async () => {
    const w = await render({ limitHit: BATTLES }, me('purchase'))

    expect(lead(w)).toBe(`Бесплатные баттлы на сегодня закончились (1 из 1). Новые — завтра в 00:00 или сразу с Pro. ${SALE_RU}`)
  })

  it('while Pro is for sale, in Uzbek', async () => {
    setLocale('uz')
    const w = await render({ limitHit: BATTLES }, me('purchase'))

    expect(lead(w)).toBe(`Bugungi bepul bellashuvlar tugadi (1 tadan 1 tasi). Yangilari — ertaga soat 00:00 da yoki darhol Pro bilan. ${SALE_UZ}`)
  })

  it('calls them battles, never "challenges": that word is the weekly challenge of a friend, and the Uzbek one is "bellashuv" everywhere', async () => {
    const ru = await render({ limitHit: BATTLES })
    expect(lead(ru)).toContain('баттлы')
    expect(lead(ru)).not.toMatch(/вызов/i)

    setLocale('uz')
    const uz = await render({ limitHit: BATTLES })
    expect(lead(uz)).toContain('bellashuvlar')
    expect(lead(uz)).not.toMatch(/chaqiruv|musobaqa|battl/i)
  })
})

// A limit of 0 is "only Pro" (the server's supported value for a kind Free does not get): nothing was spent, nothing comes back tomorrow.
describe('PaywallSheet — a kind that is for Pro only (limit 0)', () => {
  const NO_SESSIONS: PracticeLimitHit = { kind: 'sessions', limit: 0, used: 0, resets_at: MIDNIGHT }
  const NO_BATTLES: PracticeLimitHit = { kind: 'battles', limit: 0, used: 0, resets_at: MIDNIGHT }

  const PRACTICE_RU = 'Практика — игры на закрепление слов и баттлы с друзьями — доступна в Pro.'
  const PRACTICE_UZ = "Amaliyot — so'zlarni mustahkamlash o'yinlari va do'stlar bilan bellashuvlar — Pro'da mavjud."
  const BATTLES_RU = 'Звать друзей на баттл можно в Pro. Принять приглашение друга можно всегда.'
  const BATTLES_UZ = "Do'stni bellashuvga chaqirish Pro'da mavjud. Do'stning taklifini qabul qilish har doim mumkin."

  it('sessions: the practice is in Pro - the existing lead of the practice, with no "over" and no day to wait for', async () => {
    const soon = await render({ limitHit: NO_SESSIONS })
    expect(lead(soon)).toBe(`${PRACTICE_RU} ${SOON_RU}`)

    setActivePinia(createPinia())
    knowZone('Asia/Tashkent')
    const sale = await render({ limitHit: NO_SESSIONS }, me('purchase'))
    expect(lead(sale)).toBe(`${PRACTICE_RU} ${SALE_RU}`)
  })

  it('battles: calling a friend is in Pro, accepting an invitation is always possible', async () => {
    const soon = await render({ limitHit: NO_BATTLES })
    expect(lead(soon)).toBe(`${BATTLES_RU} ${SOON_RU}`)

    setActivePinia(createPinia())
    knowZone('Asia/Tashkent')
    const sale = await render({ limitHit: NO_BATTLES }, me('purchase'))
    expect(lead(sale)).toBe(`${BATTLES_RU} ${SALE_RU}`)
  })

  it('says it in Uzbek, for sessions and for battles, in both modes', async () => {
    setLocale('uz')

    expect(lead(await render({ limitHit: NO_SESSIONS }))).toBe(`${PRACTICE_UZ} ${SOON_UZ}`)
    expect(lead(await render({ limitHit: NO_BATTLES }))).toBe(`${BATTLES_UZ} ${SOON_UZ}`)

    setActivePinia(createPinia())
    knowZone('Asia/Tashkent')
    expect(lead(await render({ limitHit: NO_SESSIONS }, me('purchase')))).toBe(`${PRACTICE_UZ} ${SALE_UZ}`)
    expect(lead(await render({ limitHit: NO_BATTLES }, me('purchase')))).toBe(`${BATTLES_UZ} ${SALE_UZ}`)
  })

  it.each([
    ['ru', 'sessions', NO_SESSIONS, /закончил|завтра|из 0|сегодня/i],
    ['ru', 'battles', NO_BATTLES, /закончил|завтра|из 0|сегодня/i],
    ['uz', 'sessions', NO_SESSIONS, /tugadi|ertaga|0 tadan|bugun/i],
    ['uz', 'battles', NO_BATTLES, /tugadi|ertaga|0 tadan|bugun/i],
  ] as const)('%s / %s: nothing "is over", no "tomorrow", no "0 of 0", no time of a reset', async (locale, _kind, hit, banned) => {
    setLocale(locale)

    for (const mode of ['coming_soon', 'purchase'] as const) {
      setActivePinia(createPinia())
      knowZone('Asia/Tashkent')
      const w = await render({ limitHit: hit }, me(mode))

      expect(lead(w), `${locale}/${hit.kind}/${mode}`).not.toMatch(banned)
      expect(lead(w), `${locale}/${hit.kind}/${mode}`).not.toMatch(/\d{2}:\d{2}/)
    }
  })

  it('does not wait for the zone of the learner: the lead does not depend on the time at all', async () => {
    useTodayStore().data = null

    expect(lead(await render({ limitHit: NO_BATTLES }))).toBe(`${BATTLES_RU} ${SOON_RU}`)
  })

  it('a limit of 0 is not a limit of 1: a kind that does have a counter still says "over"', async () => {
    const w = await render({ limitHit: BATTLES })

    expect(lead(w)).toContain('закончились (1 из 1)')
    expect(lead(w)).not.toContain(BATTLES_RU)
  })

  it('is the same sheet otherwise: the title, what Free gives, the promo code, "Got it"', async () => {
    const w = await render({ limitHit: NO_BATTLES })

    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro скоро')
    expect(w.get('[data-testid=paywall-free]').text()).toContain('20 новых слов в день')
    expect(w.find('[data-testid=promo-toggle]').exists()).toBe(true)
    expect(w.get('[data-testid=paywall-close]').text()).toBe('Понятно')
  })
})

describe('PaywallSheet — "or right away with Pro" is said only while Pro can be bought', () => {
  const HITS = { sessions: SESSIONS, battles: BATTLES }

  it.each([
    ['ru', 'sessions', 'сразу с Pro'],
    ['ru', 'battles', 'сразу с Pro'],
    ['uz', 'sessions', 'darhol Pro bilan'],
    ['uz', 'battles', 'darhol Pro bilan'],
  ] as const)('%s / %s', async (locale, kind, promise) => {
    setLocale(locale)

    const soon = await render({ limitHit: HITS[kind] })
    expect(lead(soon)).not.toContain(promise)

    setActivePinia(createPinia())
    knowZone('Asia/Tashkent')
    const sale = await render({ limitHit: HITS[kind] }, me('purchase'))
    expect(lead(sale)).toContain(promise)
  })

  it('the lead follows the sheet when it learns what is for sale: the catalog arrives after the sheet is open', async () => {
    let arrive!: (value: BillingCatalog) => void
    vi.mocked(api.billing.catalog).mockReturnValue(new Promise((resolve) => (arrive = resolve)))
    const w = await render({}, me('purchase'))
    expect(lead(w)).toBe(`Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00. ${SOON_RU}`)

    arrive(catalog())
    await flushPromises()

    expect(lead(w)).toBe(`Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00 или сразу с Pro. ${SALE_RU}`)
  })

  it('a catalog that cannot be read keeps the sheet on the words of "coming soon", which promise nothing', async () => {
    vi.mocked(api.billing.catalog).mockRejectedValue(new Error('catalog'))
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    const w = await render({}, me('purchase'))

    expect(lead(w)).toBe(`Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00. ${SOON_RU}`)
  })
})

describe('PaywallSheet — when the counters are full again', () => {
  it('is told in the zone of the learner, not of the device: the same moment is 19:00 for a learner in UTC', async () => {
    knowZone('UTC')
    const w = await render()

    expect(lead(w)).toContain('Новая — в 19:00.')
  })

  it('follows the zone the Today screen names, whatever the device is on', async () => {
    knowZone('Europe/Moscow') // 22:00 of the same day: an hour of today, not its turn
    const w = await render()

    expect(lead(w)).toContain('Новая — в 22:00.')
  })

  it('uses the zone of the device while the learner\'s is not known (the Today screen was not opened in this run)', async () => {
    useTodayStore().data = null
    const w = await render()

    expect(lead(w)).toMatch(/Новая — (завтра )?в \d{2}:\d{2}\./)
  })

  it('does not claim "tomorrow" when the clock of the device is days off: the time alone is true', async () => {
    vi.setSystemTime(new Date('2026-10-28T10:00:00Z'))
    const w = await render()

    expect(lead(w)).toContain('Новая — в 00:00.')
  })

  it('is told in the same way whenever in the day the sheet opens, up to the last minute', async () => {
    vi.setSystemTime(new Date('2026-11-02T18:59:00Z'))
    const w = await render()

    expect(lead(w)).toContain('Новая — завтра в 00:00.')
  })

  it('is told the same way while Pro is for sale, in the learner\'s zone', async () => {
    knowZone('UTC')
    const w = await render({}, me('purchase'))

    expect(lead(w)).toContain('Новая — в 19:00 или сразу с Pro.')
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

  it('says among the things Pro gives that practice and battles have no limit of the day', async () => {
    const w = await render()

    expect(w.findAll('[data-testid=benefit]').map((item) => item.text())).toContain('Практика и баттлы без дневного лимита')
    setLocale('uz')
    expect((await render()).findAll('[data-testid=benefit]').map((item) => item.text())).toContain('Amaliyot va bellashuvlar kunlik limitsiz')
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
    expect(lead(w)).toBe(`Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00 или сразу с Pro. ${SALE_RU}`)
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

  it('does not promise what is not there: no word of statistics, in both languages and in both modes', async () => {
    for (const locale of ['ru', 'uz'] as const) {
      for (const hit of [SESSIONS, BATTLES]) {
        for (const mode of ['coming_soon', 'purchase'] as const) {
          setActivePinia(createPinia())
          knowZone('Asia/Tashkent')
          setLocale(locale)
          const w = await render({ limitHit: hit }, me(mode))

          expect(w.text().toLowerCase(), `${locale}/${hit.kind}/${mode}`).not.toMatch(/статистик|statistik/)
        }
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
