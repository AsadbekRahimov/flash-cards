import type { PracticeChoice } from '@/api/types'

/** A sentence cut around one marked part: `before` ends with a space and `after` starts with one when they are not empty. */
export interface MarkedSentence {
  before: string
  marked: string
  after: string
}

const joinBefore = (words: readonly string[]): string => (words.length > 0 ? `${words.join(' ')} ` : '')
const joinAfter = (words: readonly string[]): string => (words.length > 0 ? ` ${words.join(' ')}` : '')

/** The sentence of an "Error hunt" as it was given, cut around its wrong word; null when the word is not among the tokens. */
export function wrongSentence(tokens: readonly PracticeChoice[], errorId: string): MarkedSentence | null {
  const index = tokens.findIndex((token) => token.id === errorId)
  if (index < 0) return null
  const texts = tokens.map((token) => token.text)
  return { before: joinBefore(texts.slice(0, index)), marked: texts[index] ?? '', after: joinAfter(texts.slice(index + 1)) }
}

/**
 * The corrected sentence (`reveal.full_text`) cut around the correction: the server keeps every other word as it was and puts the right
 * option (one word or a few) in place of the wrong one, punctuation around it kept - so the words before and after it are the tokens
 * before and after the wrong one. Null when the text does not add up that way (the screen then shows it plain).
 */
export function correctedSentence(tokens: readonly PracticeChoice[], errorId: string, fullText: string): MarkedSentence | null {
  const index = tokens.findIndex((token) => token.id === errorId)
  if (index < 0) return null
  const words = fullText.trim().split(/\s+/)
  const tail = tokens.length - index - 1
  if (words.length < tokens.length) return null

  const before = words.slice(0, index)
  const after = words.slice(words.length - tail)
  const same = (part: readonly string[], from: number): boolean => part.every((word, offset) => word === tokens[from + offset]?.text)
  if (!same(before, 0) || !same(after, index + 1)) return null

  return { before: joinBefore(before), marked: words.slice(index, words.length - tail).join(' '), after: joinAfter(after) }
}
