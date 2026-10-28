import { t } from '@/i18n'
import type { BillingCurrency, BillingProviderKey, CatalogItem } from '@/api/types'

/** The currency each provider is paid in (docs/13 §2.1 `SubscriptionProvider::currency`). */
export const PROVIDER_CURRENCY: Record<BillingProviderKey, BillingCurrency> = {
  telegram_stars: 'XTR',
  payme: 'UZS',
  click: 'UZS',
}

/** Display order of the pay buttons. */
export const PROVIDER_ORDER: readonly BillingProviderKey[] = ['telegram_stars', 'payme', 'click']

/**
 * A price from the catalog / an order. Amounts are minor units: whole Stars for `XTR`, tiyin for
 * `UZS` (100 tiyin = 1 sum) — «150 ⭐», «29 000 so'm».
 */
export function formatPrice(minorUnits: number, currency: BillingCurrency, locale: string): string {
  if (currency === 'XTR') return `${new Intl.NumberFormat(locale).format(minorUnits)} ⭐`

  const sum = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(Math.round(minorUnits / 100))
  return `${sum} ${t('billing.sum')}`
}

/** The currencies a saving is read in, the first one both periods are priced in wins: sums, then Stars. */
const SAVING_CURRENCIES: readonly BillingCurrency[] = ['UZS', 'XTR']

/**
 * How much cheaper a year is than twelve months, in whole percent: `round((1 − year / (12 × month)) × 100)`
 * (docs/14 §6.6). Both prices are taken in one currency (UZS, else XTR) and before the open offer (`list_prices`):
 * the offer discounts a single payment and says nothing about what the periods cost. Null when there is no month or
 * year, no currency both are priced in, or the saving rounds to less than 1 %: then the tab says just «Year».
 */
export function yearSavingPct(items: readonly CatalogItem[]): number | null {
  const month = items.find((item) => item.period === 'month')
  const year = items.find((item) => item.period === 'year')
  if (!month || !year) return null

  for (const currency of SAVING_CURRENCIES) {
    const monthly = month.list_prices[currency] ?? month.prices[currency]
    const yearly = year.list_prices[currency] ?? year.prices[currency]
    if (monthly === undefined || yearly === undefined || monthly <= 0 || yearly <= 0) continue

    const pct = Math.round((1 - yearly / (12 * monthly)) * 100)
    return pct >= 1 ? pct : null
  }

  return null
}
