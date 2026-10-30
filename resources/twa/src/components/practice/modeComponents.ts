import type { Component } from 'vue'
import type { PracticeElement, PracticeElementKind } from '@/api/types'
import CollocationChoice from './CollocationChoice.vue'
import DictationInput from './DictationInput.vue'
import PairsBoard from './PairsBoard.vue'
import SentenceBuilder from './SentenceBuilder.vue'

/**
 * Which component draws which element (docs/16 §11.3): the one place that knows. A mode component takes the same props
 * (`element`, `mode: 'solo' | 'battle'`, `disabled`) and sends `answer(payload)` (the dictation and the sentence also
 * `skip`), so the solo screen and the battle screen draw any of them the same way.
 *
 * The tasks 18.2 – 18.5 replace the component files, one each; this table is not touched again.
 */
export const MODE_COMPONENTS: Record<PracticeElementKind, Component> = {
  pairs_round: PairsBoard,
  collocation: CollocationChoice,
  sentence: SentenceBuilder,
  dictation: DictationInput,
}

/** The component for an element of the session (or of a round of a battle). */
export function modeComponentFor(element: PracticeElement): Component {
  return MODE_COMPONENTS[element.kind]
}
