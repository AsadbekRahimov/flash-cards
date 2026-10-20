import { computed, onBeforeUnmount, ref, type Ref } from 'vue'
import type { Quality } from '@/api/types'

/** How long the picked option stays colored before the grade goes to the server. */
export const CHOICE_FEEDBACK_MS = 700

export type OptionState = 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal'

/** Case- and whitespace-insensitive equality, the way the server de-duplicates options. */
export function sameText(a: string, b: string): boolean {
  return a.trim().replace(/\s+/g, ' ').toLowerCase() === b.trim().replace(/\s+/g, ' ').toLowerCase()
}

/**
 * The shared logic of the four-choice exercises (reverse, audio): the learner picks one option,
 * every option shows whether it was right, and after a short pause the grade — 4 (Good) for the
 * right pick, 2 (Again) for a wrong one — is handed to the caller. One pick per card.
 */
export function useChoice(
  options: Ref<string[]>,
  correct: Ref<string>,
  onGrade: (quality: Quality, picked: string) => void,
): {
  picked: Ref<number | null>
  stateOf: (index: number) => OptionState
  isCorrect: Ref<boolean | null>
  pick: (index: number) => void
} {
  const picked = ref<number | null>(null)
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
    const quality: Quality = sameText(label, correct.value) ? 4 : 2
    timer = setTimeout(() => {
      timer = null
      onGrade(quality, label)
    }, CHOICE_FEEDBACK_MS)
  }

  onBeforeUnmount(() => {
    if (timer !== null) clearTimeout(timer)
  })

  return { picked, stateOf, isCorrect, pick }
}
