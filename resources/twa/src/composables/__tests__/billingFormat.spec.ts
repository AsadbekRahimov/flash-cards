import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '@/i18n'
import { PROVIDER_CURRENCY, PROVIDER_ORDER, formatPrice, graceText, inStarsGrace, yearSavingPct } from '../billingFormat'
import type { CatalogItem, Prices, SubscriptionSummary } from '@/api/types'

/** Intl groups digits with (narrow) no-break spaces. */
const plain = (text: string) => text.replace(/[\s  ]+/g, ' ')

describe('billingFormat', () => {
  afterEach(() => setLocale('ru'))

  it('shows whole Stars as they are', () => {
    expect(plain(formatPrice(150, 'XTR', 'ru'))).toBe('150 ⭐')
    expect(plain(formatPrice(1300, 'XTR', 'ru'))).toBe('1 300 ⭐')
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
        /^Telegram yangilash uchun Stars'ni yecha olmadi\. Stars balansini to'ldiring — Telegram yechishni qayta urinib ko'radi\. Pro .*2026.* gacha amal qiladi\.$/,
      )
    })

    it('leaves out the date it does not know', () => {
      expect(graceText(null, 'ru')).toBe('Telegram не смог списать Stars за продление. Пополните баланс Stars — Telegram повторит списание.')
    })
  })

  describe('yearSavingPct', () => {
    const item = (period: 'month' | 'year', list: Prices, prices: Prices = list): CatalogItem => ({ plan: 'pro', tier: null, period, prices, list_prices: list })

    it('is how much cheaper a year is than twelve months, rounded to whole percent', () => {
      // 1 − 1300 / 1800 = 27.8 %
      expect(yearSavingPct([item('month', { XTR: 150 }), item('year', { XTR: 1300 })])).toBe(28)
      // 1 − 249 000 / 348 000 = 28.4 %
      expect(yearSavingPct([item('month', { UZS: 2_900_000 }), item('year', { UZS: 24_900_000 })])).toBe(28)
    })

    it('reads sums when both periods have them, even when Stars say something else', () => {
      const month = item('month', { XTR: 150, UZS: 3_900_000 })
      const year = item('year', { XTR: 1500, UZS: 29_900_000 }) // Stars: 17 %, sums: 36 %

      expect(yearSavingPct([month, year])).toBe(36)
    })

    it('falls back to Stars when a period has no sum price, and never mixes currencies', () => {
      expect(yearSavingPct([item('month', { XTR: 150, UZS: 2_900_000 }), item('year', { XTR: 1300 })])).toBe(28)
      expect(yearSavingPct([item('month', { XTR: 150 }), item('year', { UZS: 24_900_000 })])).toBeNull()
    })

    it('says nothing below 1 %, nor when the year costs more', () => {
      expect(yearSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1200 })])).toBeNull()
      expect(yearSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1195 })])).toBeNull() // 0.4 %
      expect(yearSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1188 })])).toBe(1)
      expect(yearSavingPct([item('month', { XTR: 100 }), item('year', { XTR: 1500 })])).toBeNull()
    })

    it('needs both a month and a year', () => {
      expect(yearSavingPct([])).toBeNull()
      expect(yearSavingPct([item('month', { XTR: 150 })])).toBeNull()
      expect(yearSavingPct([item('year', { XTR: 1300 })])).toBeNull()
    })

    it('compares the list prices: a one-time offer does not make the year look cheaper or dearer', () => {
      // Month discounted by an offer (105 instead of 150), the year is not: the plans still differ by 28 %.
      expect(yearSavingPct([item('month', { XTR: 150 }, { XTR: 105 }), item('year', { XTR: 1300 })])).toBe(28)
    })

    it('ignores a zero price', () => {
      expect(yearSavingPct([item('month', { XTR: 0 }), item('year', { XTR: 1300 })])).toBeNull()
    })
  })
})
