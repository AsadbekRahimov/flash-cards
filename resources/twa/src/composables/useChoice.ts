import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue'
import type { ChoiceResult } from '@/api/types'

/** How long a right pick stays on screen, coloured, before the next card may appear. */
export const CHOICE_FEEDBACK_MS = 700

export type OptionState = 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal'

/** Case- and whitespace-insensitive equality, the way the server de-duplicates options. */
export function sameText(a: string, b: string): boolean {
  return a.trim().replace(/\s+/g, ' ').toLowerCase() === b.trim().replace(/\s+/g, ' ').toLowerCase()
}

export interface ChoiceHandlers {
  /** The learner has picked: the pick is final and goes to the server now, which grades it. */
  pick: (picked: string) => void
  /** The learner has seen the answer: the next card may be shown (the pause after a right pick, or "Next" after a wrong one). */
  advance: () => void
}

/**
 * The shared logic of the four-choice exercises (reverse, audio): the learner picks one option, the SERVER grades it
 * (docs/06 §12.2) and `result` carries its word — the card has nothing that gives the right option away, so the options are
 * coloured only once it arrives. One pick per card.
 *
 * The pick is final, so it is handed over at once (`handlers.pick`): leaving the card afterwards loses nothing. Until the
 * server answers, the picked option is `selected`. What is left to the learner is only when the next card appears
 * (`handlers.advance`):
 * - a right pick: after a short pause (`CHOICE_FEEDBACK_MS`) from the moment the result arrives, nothing to press;
 * - a wrong pick (docs/14 N6): no automatic move — the right option stays highlighted for the learner to read it,
 *   `awaitingConfirm` turns on, and `confirm()` (the "Next" button) calls `advance`, once.
 */
export function useChoice(
  options: Ref<string[]>,
  result: Ref<ChoiceResult | null>,
  handlers: ChoiceHandlers,
): {
  picked: Ref<number | null>
  stateOf: (index: number) => OptionState
  /** null until the server has answered. */
  isCorrect: Ref<boolean | null>
  /** A wrong pick's verdict is on screen and waits for `confirm()`. */
  awaitingConfirm: Ref<boolean>
  /** `confirm()` was called: the learner has asked for the next card. */
  confirmed: Ref<boolean>
  pick: (index: number) => void
  confirm: () => void
} {
  const picked = ref<number | null>(null)
  const confirmed = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null

  const isCorrect = computed((): boolean | null => (picked.value === null || result.value === null ? null : result.value.correct))
  const awaitingConfirm = computed((): boolean => isCorrect.value === false)

  function stateOf(index: number): OptionState {
    if (picked.value === null) return 'idle'
    if (result.value === null) return index === picked.value ? 'selected' : 'idle'
    const label = options.value[index] ?? ''
    if (index === picked.value) return result.value.correct ? 'correct' : 'incorrect'
    return sameText(label, result.value.right) ? 'reveal' : 'idle'
  }

  function pick(index: number): void {
    if (picked.value !== null) return
    const label = options.value[index]
    if (label === undefined) return
    picked.value = index
    handlers.pick(label)
  }

  function confirm(): void {
    if (!awaitingConfirm.value || confirmed.value) return
    confirmed.value = true
    handlers.advance()
  }

  // A right pick moves on by itself, a short while after the colour is on screen.
  watch(
    () => isCorrect.value,
    (right) => {
      if (right !== true || timer !== null) return
      timer = setTimeout(() => {
        timer = null
        handlers.advance()
      }, CHOICE_FEEDBACK_MS)
    },
    { flush: 'sync' },
  )

  onBeforeUnmount(() => {
    if (timer !== null) clearTimeout(timer)
  })

  return { picked, stateOf, isCorrect, awaitingConfirm, confirmed, pick, confirm }
}
