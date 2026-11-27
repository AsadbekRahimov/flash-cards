/**
 * The size of the greeting of "Today" («Привет, Мухаммадали!») next to the stamp of the streak (T16, docs/40 §4): the heading wraps only
 * between words, so a long word gets a smaller size instead of being cut in the middle. The step is chosen by the estimated width of the
 * longest word, apart for a narrow window (under 360 px: a 320 px phone, the column beside the stamp is 176 px) and a wider one (from
 * 216 px at 360 px).
 *
 * `l` - the kit's `t-display-l` (30 px), `m` - 24 px, `s` - 20 px, `xs` - 17 px, `xxs` - 15 px (`TodayView.vue`, scoped styles). A word
 * wider than the column even at `xxs` still breaks (`overflow-wrap: break-word`), it never runs out of the screen.
 */
export type GreetingSize = 'l' | 'm' | 's' | 'xs' | 'xxs'

export interface GreetingFit {
  narrow: GreetingSize
  wide: GreetingSize
}

/** The steps, largest first: the font size in px. */
const STEPS: ReadonlyArray<[GreetingSize, number]> = [
  ['l', 30],
  ['m', 24],
  ['s', 20],
  ['xs', 17],
  ['xxs', 15],
]

/** The width of the column of the greeting beside the stamp, px: 320 px window, and 360 px (the narrowest of the "wide" ones). */
const NARROW_COLUMN = 176
const WIDE_COLUMN = 216

/**
 * The advance of a letter of Unbounded 600 in em, a little rounded up (measured: Cyrillic and Latin letters 0.69-0.81 em on average,
 * «ш ж м ю» about 1.17 (1.18 here), «i l !» about 0.3-0.45).
 */
const NARROW_GLYPHS = /[iIl1j.,:;!'’|ıfrt]/
/** «ш щ ж м ю ы ф» in both cases (escaped: no Cyrillic outside the dictionaries) and «m w». */
const WIDE_GLYPHS = /[\u0448\u0449\u0436\u043c\u044e\u044b\u0444\u0428\u0429\u0416\u041c\u042e\u042b\u0424mwMW]/

function glyphEm(char: string): number {
  if (NARROW_GLYPHS.test(char)) return 0.45
  if (WIDE_GLYPHS.test(char)) return 1.18
  return 0.78
}

/** The estimated width of a word at 1 px (em). */
export function wordEm(word: string): number {
  return [...word].reduce((sum, char) => sum + glyphEm(char), 0)
}

function step(em: number, column: number): GreetingSize {
  for (const [size, px] of STEPS) if (em * px <= column) return size
  return 'xxs'
}

export function greetingFit(text: string): GreetingFit {
  const em = text.split(/\s+/).reduce((max, word) => Math.max(max, wordEm(word)), 0)
  return { narrow: step(em, NARROW_COLUMN), wide: step(em, WIDE_COLUMN) }
}
