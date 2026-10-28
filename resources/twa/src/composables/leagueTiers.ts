import type { StringKey } from '@/i18n/ru'

/**
 * League tiers (docs/13 §7.3, docs/14 §6.7): the name of a tier and its colour, shared by the League screen and the
 * league row of "Today" (docs/14 K4). The server sends `tier_key` (`LeagueTierKey`); a key this build does not know
 * (a tier added or renamed on the server) falls back to the lowest tier everywhere, so a screen never shows an
 * empty name or a transparent icon tile.
 */

const LABEL_KEYS: Readonly<Record<string, StringKey>> = {
  bronze: 'league.tier.bronze',
  silver: 'league.tier.silver',
  gold: 'league.tier.gold',
  platinum: 'league.tier.platinum',
  diamond: 'league.tier.diamond',
}

/**
 * Colour of the trophy tile per tier. The same in both themes (like the `--tile-*` tokens), the icon on it is white.
 * Look a key up with `tierColor()`: a plain index with an unknown key would give `undefined`.
 */
export const TIER_COLORS: Readonly<Record<string, string>> = {
  bronze: '#cd7f32',
  silver: '#9ca3af',
  gold: '#eab308',
  platinum: '#14b8a6',
  diamond: '#6366f1',
}

/** An own property only: `constructor`, `toString` or `__proto__` sent as a key must not resolve to an inherited member. */
function own<T>(table: Readonly<Record<string, T>>, key: string): T | undefined {
  return Object.prototype.hasOwnProperty.call(table, key) ? table[key] : undefined
}

/** The i18n key of the tier name (`league.tier.silver`); an unknown key gives the bronze one. */
export function tierLabelKey(key: string): StringKey {
  return own(LABEL_KEYS, key) ?? 'league.tier.bronze'
}

/** The tile colour of a tier (`#9ca3af`); an unknown key gives the bronze colour. */
export function tierColor(key: string): string {
  return own(TIER_COLORS, key) ?? TIER_COLORS.bronze
}
