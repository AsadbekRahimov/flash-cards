import { computed, onBeforeUnmount, ref, type Ref } from 'vue'
import type { Quality } from '@/api/types'

/** How long a right pick stays on screen, coloured, before the next card may appear. */
export const CHOICE_FEEDBACK_MS = 700

export type OptionState = 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal'

/** Case- and whitespace-insensitive equality, the way the server de-duplicates options. */
export function sameText(a: string, b: string): boolean {
  return a.trim().replace(/\s+/g, ' ').toLowerCase() === b.trim().replace(/\s+/g, ' ').toLowerCase()
}

export interface ChoiceHandlers {
  /** The learner has picked: the grade is final (4 for a right pick, 2 for a wrong one) and goes to the server now. */
  grade: (quality: Quality, picked: string) => void
  /** The learner has seen the answer: the next card may be shown (the pause after a right pick, or "Next" after a wrong one). */
  advance: () => void
}

/**
 * The shared logic of the four-choice exercises (reverse, audio): the learner picks one option and every option shows
 * whether it was right. One pick per card.
 *
 * The grade is final at the pick, so it is handed over at once (`handlers.grade`): leaving the card afterwards loses
 * nothing. What is left to the learner is only when the next card appears (`handlers.advance`):
 * - a right pick: after a short pause (`CHOICE_FEEDBACK_MS`), nothing to press;
 * - a wrong pick (docs/14 N6): no automatic move — the right option stays highlighted for the learner to read it,
 *   `awaitingConfirm` turns on, and `confirm()` (the "Next" button) calls `advance`, once.
 */
export function useChoice(
  options: Ref<string[]>,
  correct: Ref<string>,
  handlers: ChoiceHandlers,
): {
  picked: Ref<number | null>
  stateOf: (index: number) => OptionState
  isCorrect: Ref<boolean | null>
  /** A wrong pick is on screen and waits for `confirm()`. */
  awaitingConfirm: Ref<boolean>
  /** `confirm()` was called: the learner has asked for the next card. */
  confirmed: Ref<boolean>
  pick: (index: number) => void
  confirm: () => void
} {
  const picked = ref<number | null>(null)
  const awaitingConfirm = ref(false)
  const confirmed = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null

  const isCorrect = computed((): boolean | null => {
    if (picked.value === null) return null
    const label = options.value[picked.value]
    return label !== undefined && sameText(label, correct.value)
  })

  function stateOf(index: number): OptionState {
    if (picked.value === null) return 'idle'
    const label = options.value[index] ?? ''
    if (index === picked.value) return isCorrect.value ? 'correct' : 'incorrect'
    return sameText(label, correct.value) ? 'reveal' : 'idle'
  }

  function pick(index: number): void {
    if (picked.value !== null) return
    const label = options.value[index]
    if (label === undefined) return
    picked.value = index
    const right = sameText(label, correct.value)
    if (!right) awaitingConfirm.value = true
    handlers.grade(right ? 4 : 2, label)
    if (right) {
      timer = setTimeout(() => {
        timer = null
        handlers.advance()
      }, CHOICE_FEEDBACK_MS)
    }
  }

  function confirm(): void {
    if (!awaitingConfirm.value || confirmed.value) return
    confirmed.value = true
    handlers.advance()
  }

  onBeforeUnmount(() => {
    if (timer !== null) clearTimeout(timer)
  })

  return { picked, stateOf, isCorrect, awaitingConfirm, confirmed, pick, confirm }
}
