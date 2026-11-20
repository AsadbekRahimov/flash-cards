import { formatDate } from '@/composables/format'
import { t } from '@/i18n'
import type { BillingCurrency, BillingPeriod, BillingProviderKey, CatalogItem, SubscriptionSummary } from '@/api/types'

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
 * `UZS` (100 tiyin = 1 sum) — «150 Stars», «29 000 so'm». Stars is the name of the currency in Telegram, the same in every
 * language (the Pro mock-up, docs/design Pro: no star emoji).
 */
export function formatPrice(minorUnits: number, currency: BillingCurrency, locale: string): string {
  if (currency === 'XTR') return `${new Intl.NumberFormat(locale).format(minorUnits)} Stars`

  const sum = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(Math.round(minorUnits / 100))
  return `${sum} ${t('billing.sum')}`
}

/** The currencies a saving is read in, the first one both periods are priced in wins: sums, then Stars. */
const SAVING_CURRENCIES: readonly BillingCurrency[] = ['UZS', 'XTR']

/** The periods this build can sell, in the order the screens show them; the months each is worth in the saving arithmetic. */
export const PERIOD_MONTHS: Readonly<Record<BillingPeriod, number>> = { month: 1, quarter: 3, year: 12 }

const PERIOD_ORDER = Object.keys(PERIOD_MONTHS) as BillingPeriod[]

function isKnownPeriod(period: string): period is BillingPeriod {
  return (PERIOD_ORDER as string[]).includes(period)
}

/**
 * The rows of the catalog the screens show, month → quarter → year whatever order the server used. A period this build does not
 * know is skipped (the contract of `BillingCatalogResource`), and so is a second row of the same period. An older server sends no
 * quarter: the list is just shorter then.
 */
export function orderedItems(items: readonly CatalogItem[]): CatalogItem[] {
  return PERIOD_ORDER.flatMap((period) => {
    const found = items.find((item) => isKnownPeriod(item.period) && item.period === period)
    return found ? [found] : []
  })
}

/**
 * How much cheaper `period` is than the same time paid month by month, in whole percent, or null when it saves less than 1 % (and for
 * the month itself). The server's word first (`saving_pct` of the catalog); an older server does not send it, then the same rule is
 * applied here: `round((1 − price / (months × month price)) × 100)`, both list prices (before the offer) in one currency, UZS, else XTR.
 */
export function periodSavingPct(items: readonly CatalogItem[], period: BillingPeriod): number | null {
  const item = items.find((i) => i.period === period)
  if (!item || period === 'month') return null
  if (item.saving_pct !== undefined) return item.saving_pct >= 1 ? Math.round(item.saving_pct) : null

  const month = items.find((i) => i.period === 'month')
  if (!month) return null
  const months = PERIOD_MONTHS[period]

  for (const currency of SAVING_CURRENCIES) {
    const monthly = month.list_prices[currency] ?? month.prices[currency]
    const price = item.list_prices[currency] ?? item.prices[currency]
    if (monthly === undefined || price === undefined || monthly <= 0 || price <= 0) continue

    const pct = Math.round((1 - price / (months * monthly)) * 100)
    return pct >= 1 ? pct : null
  }

  return null
}

/**
 * A Stars subscription whose renewal charge failed (`past_due`, docs/13 §0 grace): Telegram retries the charge by
 * itself, Pro still works until the end of the grace period — and the server refuses every new payment meanwhile
 * (`checkout_conflict`, there is also no `can_cancel`). The screens hide the buy buttons and say what to do instead.
 */
export function inStarsGrace(subscription: SubscriptionSummary | null | undefined): boolean {
  return subscription?.status === 'past_due' && subscription.provider === 'telegram_stars'
}

/** "Telegram could not charge Stars for the renewal. Top up your Stars — Telegram will retry. Pro is valid until 3 Nov." */
export function graceText(until: string | null, locale: string): string {
  const reason = t('billing.grace')
  return until ? `${reason} ${t('billing.grace_until', { date: formatDate(until, 'dayMonthYear', locale) })}` : reason
}
