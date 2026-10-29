import { describe, expect, it } from 'vitest'
import { BLANK, filledWith, shownOptions, splitPhrase } from '../collocationPhrase'
import type { PracticeChoice } from '@/api/types'

const options: PracticeChoice[] = [
  { id: 'oA1', text: 'make' },
  { id: 'oB2', text: 'have' },
  { id: 'oC3', text: 'do' },
]

describe('splitPhrase — the phrase around its blank', () => {
  it('cuts the text at the blank and keeps the spaces next to it', () => {
    expect(BLANK).toBe('___')
    expect(splitPhrase("Let's ___ a meeting on Monday.")).toEqual({ before: "Let's ", after: ' a meeting on Monday.' })
  })

  it('works with a blank at either end', () => {
    expect(splitPhrase('___ a decision is hard.')).toEqual({ before: '', after: ' a decision is hard.' })
    expect(splitPhrase('He is good ___')).toEqual({ before: 'He is good ', after: '' })
  })

  it('has no parts for a text without a blank or with two (it is shown as it is)', () => {
    expect(splitPhrase('Let us have a meeting.')).toBeNull()
    expect(splitPhrase('They ___ it ___.')).toBeNull()
    expect(splitPhrase('')).toBeNull()
  })
})

describe('filledWith — what the whole phrase has in place of the blank', () => {
  const parts = splitPhrase("Let's ___ a meeting on Monday.")!

  it('is the word written into the blank', () => {
    expect(filledWith("Let's have a meeting on Monday.", parts)).toBe('have')
  })

  it('can be several words', () => {
    expect(filledWith('I need to take a break now.', splitPhrase('I need to ___ now.')!)).toBe('take a break')
  })

  it('is null when the whole phrase is not the text with something in the blank', () => {
    expect(filledWith('Another phrase altogether.', parts)).toBeNull()
    expect(filledWith("Let's a meeting on Monday.", parts)).toBeNull() // nothing in the blank
    expect(filledWith("Let's have a meeting.", parts)).toBeNull() // the end differs
    expect(filledWith('', parts)).toBeNull()
  })

  it('works with a blank at either end', () => {
    expect(filledWith('Making a decision is hard.', splitPhrase('___ a decision is hard.')!)).toBe('Making')
    expect(filledWith('He is good at', splitPhrase('He is good ___')!)).toBe('at')
  })
})

describe('shownOptions — how each option looks', () => {
  it('leaves everything idle until something is picked', () => {
    const shown = shownOptions(options, null, null, null)

    expect(shown.map((option) => option.state)).toEqual(['idle', 'idle', 'idle'])
    expect(shown.map((option) => option.caption)).toEqual([null, null, null])
    expect(shown.map((option) => option.text)).toEqual(['make', 'have', 'do'])
  })

  it('marks a pick that waits for the server as selected, and says whose answer it is', () => {
    const shown = shownOptions(options, 'oA1', null, null)

    expect(shown.map((option) => option.state)).toEqual(['selected', 'idle', 'idle'])
    expect(shown.map((option) => option.caption)).toEqual(['your', null, null])
  })

  it('a right pick is correct and nothing else is marked', () => {
    const shown = shownOptions(options, 'oB2', 'correct', ['oB2'])

    expect(shown.map((option) => option.state)).toEqual(['idle', 'correct', 'idle'])
    expect(shown.map((option) => option.caption)).toEqual([null, 'your', null])
  })

  it('a wrong pick is incorrect and the right option is marked as the right one', () => {
    const shown = shownOptions(options, 'oA1', 'wrong', ['oB2'])

    expect(shown.map((option) => option.state)).toEqual(['incorrect', 'reveal', 'idle'])
    expect(shown.map((option) => option.caption)).toEqual(['your', 'right', null])
  })

  it('marks both right options, the picked one included', () => {
    const wrong = shownOptions(options, 'oC3', 'wrong', ['oA1', 'oB2'])
    const right = shownOptions(options, 'oA1', 'correct', ['oA1', 'oB2'])

    expect(wrong.map((option) => option.state)).toEqual(['reveal', 'reveal', 'incorrect'])
    expect(right.map((option) => option.state)).toEqual(['correct', 'reveal', 'idle'])
  })

  it('shows the right option after a hint, when nothing was picked', () => {
    const shown = shownOptions(options, null, 'skipped', ['oB2'])

    expect(shown.map((option) => option.state)).toEqual(['idle', 'reveal', 'idle'])
    expect(shown.map((option) => option.caption)).toEqual([null, 'right', null])
  })

  it('takes the verdict of the server for the pick, it does not compare ids itself', () => {
    expect(shownOptions(options, 'oA1', 'correct', ['oB2'])[0]?.state).toBe('correct')
    expect(shownOptions(options, 'oB2', 'wrong', ['oB2'])[1]?.state).toBe('incorrect')
  })

  it('keeps the right options hidden while there is no verdict', () => {
    const shown = shownOptions(options, 'oA1', null, ['oB2'])

    expect(shown.map((option) => option.state)).toEqual(['selected', 'idle', 'idle'])
  })

  it('does not touch the options it was given', () => {
    const before = JSON.stringify(options)

    shownOptions(options, 'oA1', 'wrong', ['oB2'])

    expect(JSON.stringify(options)).toBe(before)
  })
})
