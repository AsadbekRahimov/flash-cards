import { describe, expect, it } from 'vitest'
import { fillBlank, shownAnswer, shownCorrect } from '../revealText'
import { collocationElement, dictationElement, pairsElement, sentenceElement } from '@/api/__tests__/practiceFixtures'

describe('fillBlank', () => {
  it('puts the word in the blank', () => {
    expect(fillBlank("Let's ___ a meeting.", 'have')).toBe("Let's have a meeting.")
    expect(fillBlank('She is ____ home.', 'at')).toBe('She is at home.')
  })

  it('leaves a text without a blank as it is', () => {
    expect(fillBlank('Good morning', 'have')).toBe('Good morning')
  })
})

describe('shownCorrect', () => {
  it('sentence: the correct sentence', () => {
    expect(shownCorrect(sentenceElement(), { correct_text: 'My mother is a teacher.' })).toEqual({ text: 'My mother is a teacher.', pairs: [] })
  })

  it('dictation: what was dictated', () => {
    expect(shownCorrect(dictationElement(), { expected: 'See you tomorrow' })).toEqual({ text: 'See you tomorrow', pairs: [] })
  })

  it('collocation: the whole phrase with the right option in the blank', () => {
    expect(shownCorrect(collocationElement(), { correct_option_ids: ['oB2'] })).toEqual({ text: "Let's have a meeting on Monday.", pairs: [] })
  })

  it('collocation with two right options says both', () => {
    expect(shownCorrect(collocationElement(), { correct_option_ids: ['oA1', 'oB2'] }).text).toBe("Let's make / have a meeting on Monday.")
  })

  it('collocation: an option the round does not know says nothing instead of a hole', () => {
    expect(shownCorrect(collocationElement(), { correct_option_ids: ['nope'] }).text).toBeNull()
  })

  it('pairs: the words with their translations, in the order of the left column', () => {
    const correct = { pairs: { lB4: 'rV3', lM1: 'rU2', lS3: 'rT1', lF2: 'rW4' } }

    expect(shownCorrect(pairsElement(), correct)).toEqual({
      text: null,
      pairs: [
        { left: 'mother', right: 'мама' },
        { left: 'father', right: 'папа' },
        { left: 'sister', right: 'сестра' },
        { left: 'brother', right: 'брат' },
      ],
    })
  })

  it('a reveal that lacks the field of its mode says nothing', () => {
    expect(shownCorrect(sentenceElement(), {})).toEqual({ text: null, pairs: [] })
    expect(shownCorrect(pairsElement(), {}).pairs).toEqual([])
  })
})

describe('shownAnswer', () => {
  const correct = { pairs: { lM1: 'rU2', lF2: 'rW4', lS3: 'rT1', lB4: 'rV3' } }

  it('sentence: the fragments in the order the player put them', () => {
    expect(shownAnswer(sentenceElement(), { order: ['fW2', 'fT5', 'fR4', 'fE3', 'fQ1'] }, {})).toEqual({ text: 'My mother is a teacher' })
    expect(shownAnswer(sentenceElement(), { order: ['fT5', 'fW2'] }, {}).text).toBe('mother My')
  })

  it('dictation: what was typed', () => {
    expect(shownAnswer(dictationElement(), { text: 'see you tomorow' }, {})).toEqual({ text: 'see you tomorow' })
  })

  it('collocation: the phrase with the option the player picked', () => {
    expect(shownAnswer(collocationElement(), { option_id: 'oA1' }, {})).toEqual({ text: "Let's make a meeting on Monday." })
  })

  it('pairs: how many of the matches were right, not the matches themselves', () => {
    const given = { pairs: { lM1: 'rU2', lF2: 'rW4', lS3: 'rV3', lB4: 'rT1' } }

    expect(shownAnswer(pairsElement(), given, correct)).toEqual({ text: null, pairs: { right: 2, total: 4 } })
  })

  it('pairs: all right, none right, nothing given', () => {
    expect(shownAnswer(pairsElement(), { pairs: correct.pairs }, correct).pairs).toEqual({ right: 4, total: 4 })
    expect(shownAnswer(pairsElement(), { pairs: { lM1: 'rT1' } }, correct).pairs).toEqual({ right: 0, total: 4 })
    expect(shownAnswer(pairsElement(), { pairs: {} }, correct).pairs).toEqual({ right: 0, total: 4 })
  })

  it('no answer, a skip and a blank text have nothing to say', () => {
    expect(shownAnswer(sentenceElement(), null, {})).toEqual({ text: null })
    expect(shownAnswer(sentenceElement(), { skip: true }, {})).toEqual({ text: null })
    expect(shownAnswer(dictationElement(), { text: '   ' }, {})).toEqual({ text: null })
    expect(shownAnswer(collocationElement(), { option_id: 'unknown' }, {})).toEqual({ text: null })
    expect(shownAnswer(pairsElement(), null, correct)).toEqual({ text: null })
  })

  it('does not say a fragment that is not in the task', () => {
    expect(shownAnswer(sentenceElement(), { order: ['zzz', 'fW2'] }, {}).text).toBe('My')
    expect(shownAnswer(sentenceElement(), { order: 'fW2' }, {}).text).toBeNull()
  })
})
