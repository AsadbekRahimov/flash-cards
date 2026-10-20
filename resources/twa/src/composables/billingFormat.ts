import { t } from '@/i18n'
import type { BillingCurrency, BillingProviderKey } from '@/api/types'

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
