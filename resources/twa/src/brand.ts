// The product name the Mini App shows (docs/36 §5б): the screen opened outside Telegram and, through `%VITE_BRAND%` in
// index.html, the <title>. Set by VITE_BRAND at build time; vite.config.ts puts in the default when it is not set. The
// i18n texts carry the word themselves (a guard test keeps the old name out of them).
export const BRAND: string = import.meta.env.VITE_BRAND || 'Muhrla'
