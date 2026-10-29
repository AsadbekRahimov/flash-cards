import type { PracticeChoice, PracticeVerdict } from '@/api/types'

/** The blank of the phrase of a "Pick the collocation" task (docs/16 §2.3): the author writes exactly one. */
export const BLANK = '___'

/** The phrase around its blank. */
export interface PhraseParts {
  before: string
  after: string
}

/** The phrase cut at its blank; null when the text does not have exactly one (then it is shown as it is, with no blank drawn). */
export function splitPhrase(text: string): PhraseParts | null {
  const parts = text.split(BLANK)
  if (parts.length !== 2) return null
  return { before: parts[0] ?? '', after: parts[1] ?? '' }
}

/**
 * What the whole phrase (`reveal.full_text`: the text with its blank filled by the right option) has in place of the
 * blank — so that the filled word can be drawn as a mark of its own. Null when the whole phrase is not the text with
 * something put into the blank (the screen then shows the whole phrase plain).
 */
export function filledWith(fullText: string, parts: PhraseParts): string | null {
  const { before, after } = parts
  if (fullText.length <= before.length + after.length || !fullText.startsWith(before) || !fullText.endsWith(after)) return null
  return fullText.slice(before.length, fullText.length - after.length)
}

/** How an option is drawn (`data-state` of `.option`, docs/14 §4.3). */
export type OptionLook = 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal'

/** `your`: the option the learner picked; `right`: the right one the learner did not pick. */
export type OptionCaption = 'your' | 'right'

export interface ShownOption extends PracticeChoice {
  state: OptionLook
  caption: OptionCaption | null
}

/**
 * How each option looks, from what is known (docs/15 §3.3, docs/14 §4.3):
 * - nothing picked: all idle;
 * - the pick without a verdict (the request is on its way, or a battle waits for the rival): `selected`;
 * - the verdict on the pick: `correct` or `incorrect` — by the verdict of the SERVER, never by comparing here;
 * - the right options (`correctIds`, once they may be shown): the ones that were not picked are `reveal`.
 * Every state beyond `idle` is drawn with a symbol and a caption besides the colour.
 */
export function shownOptions(
  options: readonly PracticeChoice[],
  pickedId: string | null,
  verdict: PracticeVerdict | null,
  correctIds: readonly string[] | null,
): ShownOption[] {
  return options.map((option): ShownOption => {
    if (option.id === pickedId) {
      const state: OptionLook = verdict === null ? 'selected' : verdict === 'correct' ? 'correct' : 'incorrect'
      return { ...option, state, caption: 'your' }
    }
    if (verdict !== null && correctIds?.includes(option.id) === true) return { ...option, state: 'reveal', caption: 'right' }
    return { ...option, state: 'idle', caption: null }
  })
}
