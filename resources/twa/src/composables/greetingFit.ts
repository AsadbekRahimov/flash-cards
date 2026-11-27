/**
 * The size of the greeting of "Today" («Привет, Мухаммадали!») next to the stamp of the streak (T16, docs/40 §4): the heading wraps only
 * between words, so a long word gets a smaller size instead of being cut in the middle. The step is chosen by the longest word, apart
 * for a narrow window (under 360 px: 320 px phones, the column beside the stamp is ~176 px) and a wider one (~216-250 px).
 *
 * `l` - the kit's `t-display-l` (30 px), `m` - 24 px, `s` - 20 px, `xs` - 17 px (`TodayView.vue`, scoped styles). A word longer than
 * the column even at `xs` still breaks (`overflow-wrap: break-word`), it never runs out of the screen.
 */
export type GreetingSize = 'l' | 'm' | 's' | 'xs'

export interface GreetingFit {
  narrow: GreetingSize
  wide: GreetingSize
}

/** The longest word (letters, as the eye counts them: code points) a size still holds in the column: narrow, then wide. */
const LIMITS: ReadonlyArray<[GreetingSize, number, number]> = [
  ['l', 7, 9],
  ['m', 9, 12],
  ['s', 11, 14],
]

function size(longest: number, column: 1 | 2): GreetingSize {
  for (const limit of LIMITS) if (longest <= limit[column]) return limit[0]
  return 'xs'
}

export function greetingFit(text: string): GreetingFit {
  const longest = text.split(/\s+/).reduce((max, word) => Math.max(max, [...word].length), 0)
  return { narrow: size(longest, 1), wide: size(longest, 2) }
}
