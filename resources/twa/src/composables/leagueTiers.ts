import type { StringKey } from '@/i18n/ru'

/**
 * League tiers (docs/13 §7.3, docs/14 §6.7): the name of a tier, shared by the League screen and the league row of
 * "Today" (docs/14 K4). The server sends `tier_key` (`LeagueTierKey`); a key this build does not know
 * (a tier added or renamed on the server) falls back to the lowest tier everywhere, so a screen never shows an
 * empty name.
 */

const LABEL_KEYS: Readonly<Record<string, StringKey>> = {
  bronze: 'league.tier.bronze',
  silver: 'league.tier.silver',
  gold: 'league.tier.gold',
  platinum: 'league.tier.platinum',
  diamond: 'league.tier.diamond',
}

/** An own property only: `constructor`, `toString` or `__proto__` sent as a key must not resolve to an inherited member. */
function own<T>(table: Readonly<Record<string, T>>, key: string): T | undefined {
  return Object.prototype.hasOwnProperty.call(table, key) ? table[key] : undefined
}

/** The i18n key of the tier name (`league.tier.silver`); an unknown key gives the bronze one. */
export function tierLabelKey(key: string): StringKey {
  return own(LABEL_KEYS, key) ?? 'league.tier.bronze'
}

/**
 * How many best days of a week count in a week of the word points v2 (docs/32 §3.5, `leagues.scoring.versions.2.best_days`): the
 * fallback for an older server, whose `GET /league` has no `best_days` yet.
 */
export const WEEK_BEST_DAYS = 5

/** The number of best days the server sends (`best_days`); anything but a positive whole number gives `WEEK_BEST_DAYS`. */
export function weekBestDays(value: unknown): number {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : WEEK_BEST_DAYS
}
