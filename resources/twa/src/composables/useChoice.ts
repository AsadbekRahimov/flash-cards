import { computed, onBeforeUnmount, ref, type Ref } from 'vue'
import type { Quality } from '@/api/types'

/** How long a right pick stays colored before the grade goes to the server. */
export const CHOICE_FEEDBACK_MS = 700

export type OptionState = 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal'

/** Case- and whitespace-insensitive equality, the way the server de-duplicates options. */
export function sameText(a: string, b: string): boolean {
  return a.trim().replace(/\s+/g, ' ').toLowerCase() === b.trim().replace(/\s+/g, ' ').toLowerCase()
}

/**
 * The shared logic of the four-choice exercises (reverse, audio): the learner picks one option and
 * every option shows whether it was right. One pick per card; the grade is handed to the caller:
 * - a right pick: after a short pause (`CHOICE_FEEDBACK_MS`) with 4 (Good), nothing to press;
 * - a wrong pick (docs/14 N6): no automatic move — the right option stays highlighted for the learner to read
 *   it, `awaitingConfirm` turns on, and `confirm()` (the "Next" button) hands over 2 (Again), once.
 */
export function useChoice(
  options: Ref<string[]>,
  correct: Ref<string>,
  onGrade: (quality: Quality, picked: string) => void,
): {
  picked: Ref<number | null>
  stateOf: (index: number) => OptionState
  isCorrect: Ref<boolean | null>
  /** A wrong pick is on screen and waits for `confirm()`. */
  awaitingConfirm: Ref<boolean>
  pick: (index: number) => void
  confirm: () => void
} {
  const picked = ref<number | null>(null)
  const awaitingConfirm = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null
  let wrongLabel: string | null = null

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
    if (sameText(label, correct.value)) {
      timer = setTimeout(() => {
        timer = null
        onGrade(4, label)
      }, CHOICE_FEEDBACK_MS)
      return
    }
    wrongLabel = label
    awaitingConfirm.value = true
  }

  function confirm(): void {
    if (!awaitingConfirm.value || wrongLabel === null) return
    const label = wrongLabel
    awaitingConfirm.value = false
    wrongLabel = null
    onGrade(2, label)
  }

  onBeforeUnmount(() => {
    if (timer !== null) clearTimeout(timer)
  })

  return { picked, stateOf, isCorrect, awaitingConfirm, pick, confirm }
}
