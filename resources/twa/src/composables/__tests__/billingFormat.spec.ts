import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '@/i18n'
import { PROVIDER_CURRENCY, PROVIDER_ORDER, formatPrice } from '../billingFormat'

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
})
