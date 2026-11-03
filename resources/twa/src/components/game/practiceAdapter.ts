import type { GameElement, GameElementKind, GamePracticeElement, GameReveal } from '@/api/gameRunTypes'
import type { PracticeElement, PracticeReveal } from '@/api/types'
import type { PracticeOutcome } from '@/stores/practice'
import type { GameOutcome } from '@/stores/gameRun'

/**
 * The adapter between a run and the components of the practice (docs/05 §5b.3): a pairs round, a collocation, a sentence and a dictation
 * of a run have the shape of the practice elements, so the practice components draw them as they are - this file only hands them
 * the props they know (`element`, `mode`, `disabled`) and the outcome they explain. Nothing of the components is copied.
 */

export const PRACTICE_KINDS: readonly GameElementKind[] = ['pairs_round', 'collocation', 'sentence', 'dictation']

export const isPracticeElement = (element: GameElement): element is GamePracticeElement => PRACTICE_KINDS.includes(element.kind)

/** The options a hint of the bazaar has taken away (`options_removed`), by id. */
export function removedOptions(element: GameElement): Set<string> {
  const hint = 'hint' in element ? element.hint : undefined
  return new Set(hint !== undefined && 'options_removed' in hint ? hint.options_removed : [])
}

/** The element as the practice component knows it: a collocation without the options the hint took away, and without the hint. */
export function practiceElementOf(element: GamePracticeElement): PracticeElement {
  if (element.kind !== 'collocation') return element
  const removed = removedOptions(element)
  return { id: element.id, kind: 'collocation', text: element.text, options: element.options.filter((option) => !removed.has(option.id)) }
}

/** The outcome as `AnswerReveal` of the practice explains it. */
export function practiceOutcomeOf(outcome: GameOutcome & { element: GamePracticeElement }): PracticeOutcome {
  const reveal: GameReveal | null = outcome.reveal
  return {
    element: practiceElementOf(outcome.element),
    answer: outcome.answer,
    verdict: outcome.verdict,
    firstAttempt: outcome.verdict === 'correct',
    reveal: reveal as PracticeReveal | null,
    pair: outcome.pair,
    roundDone: outcome.roundDone,
    ...(outcome.earlier ? { earlier: true } : {}),
  }
}
