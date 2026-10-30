import { describe, expect, it } from 'vitest'
import { MODE_COMPONENTS, modeComponentFor } from '../modeComponents'
import CollocationChoice from '../CollocationChoice.vue'
import DictationInput from '../DictationInput.vue'
import PairsBoard from '../PairsBoard.vue'
import SentenceBuilder from '../SentenceBuilder.vue'
import { collocationElement, dictationElement, pairsElement, sentenceElement } from '@/api/__tests__/practiceFixtures'
import type { PracticeElementKind } from '@/api/types'

/**
 * The interface every component of a mode keeps (docs/16 §11.3), whoever writes it: the screens of a solo session and
 * of a battle draw them all the same way. The tasks of the four modes replace the files; these checks stay true.
 */
interface Declared {
  props?: Record<string, unknown>
  emits?: string[] | Record<string, unknown>
}

const declared = (component: unknown): Declared => component as Declared
const emitsOf = (component: unknown): string[] => {
  const emits = declared(component).emits
  return Array.isArray(emits) ? emits : Object.keys(emits ?? {})
}

describe('modeComponents — which component draws which element', () => {
  it('has one component for each kind of element', () => {
    const kinds: PracticeElementKind[] = ['pairs_round', 'collocation', 'sentence', 'dictation']

    expect(Object.keys(MODE_COMPONENTS).sort()).toEqual([...kinds].sort())
    expect(MODE_COMPONENTS.pairs_round).toBe(PairsBoard)
    expect(MODE_COMPONENTS.collocation).toBe(CollocationChoice)
    expect(MODE_COMPONENTS.sentence).toBe(SentenceBuilder)
    expect(MODE_COMPONENTS.dictation).toBe(DictationInput)
  })

  it('picks the component by the `kind` of the element', () => {
    expect(modeComponentFor(pairsElement())).toBe(PairsBoard)
    expect(modeComponentFor(collocationElement())).toBe(CollocationChoice)
    expect(modeComponentFor(sentenceElement())).toBe(SentenceBuilder)
    expect(modeComponentFor(dictationElement())).toBe(DictationInput)
  })
})

describe.each([
  ['PairsBoard', PairsBoard, ['answer']],
  ['CollocationChoice', CollocationChoice, ['answer']],
  ['SentenceBuilder', SentenceBuilder, ['answer', 'skip']],
  // The dictation also says `silent` ("No sound for now", solo only, docs/15 §3.4): the solo screen leads to the hub, a battle ignores it.
  ['DictationInput', DictationInput, ['answer', 'silent', 'skip']],
] as const)('%s keeps the interface of a mode component', (_name, component, events) => {
  it('takes `element`, `mode` and `disabled`', () => {
    expect(Object.keys(declared(component).props ?? {}).sort()).toEqual(['disabled', 'element', 'mode'])
  })

  it('sends ' + events.map((event) => '`' + event + '`').join(', '), () => {
    expect(emitsOf(component).sort()).toEqual([...events])
  })
})
