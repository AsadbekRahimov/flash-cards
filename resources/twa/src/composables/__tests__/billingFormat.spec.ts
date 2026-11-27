import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '@/i18n'
import { PROVIDER_CURRENCY, PROVIDER_ORDER, formatPrice, graceText, inStarsGrace, orderedItems, periodSavingPct, splitPrice } from '../billingFormat'
import type { CatalogItem, Prices, SubscriptionSummary } from '@/api/types'

/** Intl groups digits with (narrow) no-break spaces. */
const plain = (text: string) => text.replace(/[\s  ]+/g, ' ')

describe('billingFormat', () => {
  afterEach(() => setLocale('ru'))

  it('shows whole Stars as they are, named by the word (no star emoji)', () => {
    expect(plain(formatPrice(150, 'XTR', 'ru'))).toBe('150 Stars')
    expect(plain(formatPrice(1300, 'XTR', 'ru'))).toBe('1 300 Stars')
  })

  it('divides tiyin by 100 and names the sum in the interface language', () => {
    expect(plain(formatPrice(2_900_000, 'UZS', 'ru'))).toBe('29 000 сум')

    setLocale('uz')
    expect(plain(formatPrice(24_900_000, 'UZS', 'uz'))).toBe("249 000 so'm")
  })

  it('rounds a fraction of a sum away', () => {
    expect(plain(formatPrice(2_900_049, 'UZS', 'ru'))).toBe('29 000 сум')
  })

  it('pays Stars in XTR and Payme / Click in UZS, Stars first', () => {
    expect(PROVIDER_CURRENCY).toEqual({ telegram_stars: 'XTR', payme: 'UZS', click: 'UZS' })
    expect(PROVIDER_ORDER).toEqual(['telegram_stars', 'payme', 'click'])
  })

  describe('the grace of a Stars subscription', () => {
    const subscription = (overrides: Partial<SubscriptionSummary> = {}): SubscriptionSummary => ({
      status: 'past_due', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-11-03T12:00:00+00:00', auto_renew: false, can_cancel: false, can_resume: false, perpetual: false, ...overrides,
    })

    it('is a Stars subscription whose renewal charge failed (past_due): Telegram retries it, any new payment is refused', () => {
      expect(inStarsGrace(subscription())).toBe(true)
    })

    it('is not any other state of Stars, nor another provider', () => {
      expect(inStarsGrace(subscription({ status: 'active', auto_renew: true, can_cancel: true }))).toBe(false)
      expect(inStarsGrace(subscription({ status: 'canceled', can_resume: true }))).toBe(false)
      expect(inStarsGrace(subscription({ provider: 'payme' }))).toBe(false)
      expect(inStarsGrace(null)).toBe(false)
      expect(inStarsGrace(undefined)).toBe(false)
    })

    it('says why, what to do and until when Pro lasts', () => {
      expect(graceText('2026-11-03T12:00:00+00:00', 'ru')).toBe(
        'Telegram не смог списать Stars за продление. Пополните баланс Stars — Telegram повторит списание. Pro действует до 3 ноября 2026 г.',
      )
    })

    it('says it in Uzbek', () => {
      setLocale('uz')

      expect(graceText('2026-11-03T12:00:00+00:00', 'uz')).toMatch(
        /^Telegram obunani uzaytirish uchun Stars'ni yechib ola olmadi\. Stars balansingizni to'ldiring — Telegram yana urinib ko'radi\. Pro .*2026.* gacha amal qiladi\.$/,
      )
    })

    it('leaves out the date it does not know', () => {
      expect(graceText(null, 'ru')).toBe('Telegram не смог списать Stars за продление. Пополните баланс Stars — Telegram повторит списание.')
    })
  })

  describe('periodSavingPct of the year', () => {
    const item = (period: 'month' | 'year', list: Prices, prices: Prices = list): CatalogItem => ({ plan: 'pro', tier: null, period, prices, list_prices: list })

    it('is how much cheaper a year is than twelve months, rounded to whole percent', () => {
      // 1 − 1300 / 1800 = 27.8 %
      expect(periodSavingPct([item('month', { XTR: 150 }), item('year', { XTR: 1300 })], 'year')).toBe(28)
      // 1 − 249 000 / 348 000 = 28.4 %
      expect(periodSavingPct([item('month', { UZS: 2_900_000 }), item('year', { UZS: 24_900_000 })], 'year')).toBe(28)
    })

    it('reads sums when both periods have them, even when Stars say something else', () => {
      const month = item('month', { XTR: 150, UZS: 3_900_000 })
      const year = item('year', { XTR: 1500, UZS: 29_900_000 }) // Stars: 17 %, sums: 36 %

      expect(periodSavingPct([month, year], 'year')).toBe(36)
    })

    it('falls back to Stars when a period has no sum price, and never mixes currencies', () => {
      expect(periodSavingPct([item('month', { XTR: 150, UZS: 2_900_000 }), item('year', { XTR: 1300 })], 'year')).toBe(28)
      expect(periodSavingPct([item('month', { XTR: 150 }), item('year', { UZS: 24_900_000 })], 'year')).toBeNull()
    })

    it('says nothing below 1 %, nor when the year costs more', () => {
      expect(periodSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1200 })], 'year')).toBeNull()
      expect(periodSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1195 })], 'year')).toBeNull() // 0.4 %
      expect(periodSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1188 })], 'year')).toBe(1)
      expect(periodSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1500 })], 'year')).toBeNull()
    })

    it('needs both a month and a year', () => {
      expect(periodSavingPct([], 'year')).toBeNull()
      expect(periodSavingPct([item('month', { XTR: 150 })], 'year')).toBeNull()
      expect(periodSavingPct([item('year', { XTR: 1300 })], 'year')).toBeNull()
    })

    it('compares the list prices: a one-time offer does not make the year look cheaper or dearer', () => {
      // Month discounted by an offer (105 instead of 150), the year is not: the plans still differ by 28 %.
      expect(periodSavingPct([item('month', { XTR: 150 }, { XTR: 105 }), item('year', { XTR: 1300 })], 'year')).toBe(28)
    })

    it('ignores a zero price', () => {
      expect(periodSavingPct([item('month', { XTR: 0 }), item('year', { XTR: 1300 })], 'year')).toBeNull()
    })
  })

  describe('the periods of the catalog', () => {
    const item = (period: string, list: Prices, extra: Partial<CatalogItem> = {}): CatalogItem =>
      ({ plan: 'pro', tier: null, period, prices: list, list_prices: list, ...extra }) as CatalogItem

    it('are shown month → quarter → year, whatever order the server used', () => {
      const items = [item('year', { XTR: 1300 }), item('quarter', { XTR: 390 }), item('month', { XTR: 150 })]

      expect(orderedItems(items).map((i) => i.period)).toEqual(['month', 'quarter', 'year'])
    })

    it('skip a period this build does not know, and work without a quarter (an older server, the sums)', () => {
      expect(orderedItems([item('week', { XTR: 50 }), item('year', { XTR: 1300 }), item('month', { XTR: 150 })]).map((i) => i.period)).toEqual([
        'month',
        'year',
      ])
      expect(orderedItems([])).toEqual([])
    })

    it("take the server's saving when it sends one, and say nothing below 1 % or for the month", () => {
      const items = [item('month', { XTR: 150 }, { saving_pct: 0 }), item('quarter', { XTR: 390 }, { saving_pct: 13 }), item('year', { XTR: 1300 }, { saving_pct: 0 })]

      expect(periodSavingPct(items, 'quarter')).toBe(13)
      expect(periodSavingPct(items, 'year')).toBeNull()
      expect(periodSavingPct(items, 'month')).toBeNull()
    })

    it('work the saving out of the list prices when the server does not send it: a quarter is three months', () => {
      // 1 − 390 / 450 = 13.3 %
      expect(periodSavingPct([item('month', { XTR: 150 }), item('quarter', { XTR: 390 })], 'quarter')).toBe(13)
      expect(periodSavingPct([item('quarter', { XTR: 390 })], 'quarter')).toBeNull()
      expect(periodSavingPct([item('month', { XTR: 150 })], 'quarter')).toBeNull()
    })
  })
})

describe('splitPrice', () => {
  it.each([
    ['150 Stars', '150', 'Stars'],
    ['1 300 Stars', '1 300', 'Stars'],
    ['75 000 сум', '75 000', 'сум'],
    ["75 000 so'm", '75 000', "so'm"],
    // a currency written first is still the currency, not the number
    ['USD 4.99', '4.99', 'USD'],
    ['UZS 75 000', '75 000', 'UZS'],
    ['$4.99', '$4.99', ''],
    ['4.99', '4.99', ''],
  ])('%s -> %s + %s', (price, amount, unit) => {
    expect(splitPrice(price)).toEqual({ amount, unit })
  })
})
