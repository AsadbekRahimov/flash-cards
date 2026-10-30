import type { BattleReveal, PracticeChoice, PracticeElement } from '@/api/types'

/**
 * Words for the reveal of a round (docs/16 §7.4): the server sends the right answer and the answers of the players in
 * the form of the mode - ids of fragments, of options, of pairs - and the round brings the element with the texts
 * behind those ids, so the screen can say them.
 */

/** What a player gave, in words. `text` is null when there is no answer to say (time ran out, "I don't know"). */
export interface ShownAnswer {
  text: string | null
  /** Pairs only: how many of the matches were right. */
  pairs?: { right: number; total: number }
}

/** The right answer, in words. For pairs a list of "word — translation", in the order of the left column. */
export interface ShownCorrect {
  text: string | null
  pairs: { left: string; right: string }[]
}

const textOf = (choices: PracticeChoice[], id: unknown): string | null =>
  typeof id === 'string' ? (choices.find((choice) => choice.id === id)?.text ?? null) : null

/** `Let's ___ a meeting.` with the option put in the blank. */
export const fillBlank = (template: string, word: string): string => template.replace(/_{2,}/, word)

export function shownCorrect(element: PracticeElement, correct: BattleReveal['correct']): ShownCorrect {
  switch (element.kind) {
    case 'sentence':
      return { text: correct.correct_text ?? null, pairs: [] }
    case 'dictation':
      return { text: correct.expected ?? null, pairs: [] }
    case 'collocation': {
      const words = (correct.correct_option_ids ?? []).map((id) => textOf(element.options, id)).filter((word): word is string => word !== null)
      return { text: words.length > 0 ? fillBlank(element.text, words.join(' / ')) : null, pairs: [] }
    }
    case 'pairs_round': {
      const matches = correct.pairs ?? {}
      const pairs = element.left.flatMap((left) => {
        const right = textOf(element.right, matches[left.id])
        return right === null ? [] : [{ left: left.text, right }]
      })
      return { text: null, pairs }
    }
  }
}

export function shownAnswer(element: PracticeElement, answer: Record<string, unknown> | null, correct: BattleReveal['correct']): ShownAnswer {
  if (answer === null || answer.skip === true) return { text: null }

  switch (element.kind) {
    case 'sentence': {
      const order = Array.isArray(answer.order) ? answer.order : []
      const words = order.map((id) => textOf(element.fragments, id)).filter((word): word is string => word !== null)
      return { text: words.length > 0 ? words.join(' ') : null }
    }
    case 'dictation':
      return { text: typeof answer.text === 'string' && answer.text.trim() !== '' ? answer.text : null }
    case 'collocation': {
      const word = textOf(element.options, answer.option_id)
      return { text: word === null ? null : fillBlank(element.text, word) }
    }
    case 'pairs_round': {
      const given = typeof answer.pairs === 'object' && answer.pairs !== null ? (answer.pairs as Record<string, unknown>) : {}
      const right = Object.entries(given).filter(([left, match]) => correct.pairs?.[left] === match).length
      return { text: null, pairs: { right, total: element.left.length } }
    }
  }
}
