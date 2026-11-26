// The product name the Mini App shows (docs/36 §5б): the screen opened outside Telegram and, through `%VITE_BRAND%` in
// index.html, the <title>. Set by VITE_BRAND at build time; vite.config.ts puts in the default when it is not set. The
// i18n texts carry the word themselves (a guard test keeps the old name out of them).
export const BRAND: string = import.meta.env.VITE_BRAND || 'Muhrla'

/** The paid plan under the brand ("Muhrla Pro"): the chip of the paywall and the name of the plan on the Subscription screen. */
export const BRAND_PRO: string = `${BRAND} Pro`
