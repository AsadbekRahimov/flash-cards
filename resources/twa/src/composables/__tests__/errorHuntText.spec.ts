import { describe, expect, it } from 'vitest'
import { correctedSentence, wrongSentence } from '../errorHuntText'
import { huntTokens } from '@/api/__tests__/practiceFixtures'

describe('errorHuntText', () => {
  it('cuts the sentence as it was given around its wrong word', () => {
    expect(wrongSentence(huntTokens, 'tOn4')).toEqual({ before: 'I am very interested ', marked: 'on', after: ' modern art.' })
    expect(wrongSentence(huntTokens, 'tI0')).toEqual({ before: '', marked: 'I', after: ' am very interested on modern art.' })
    expect(wrongSentence(huntTokens, 'tArt6')).toEqual({ before: 'I am very interested on modern ', marked: 'art.', after: '' })
    expect(wrongSentence(huntTokens, 'nope')).toBeNull()
  })

  it('cuts the corrected sentence around the correction, of one word or of several', () => {
    expect(correctedSentence(huntTokens, 'tOn4', 'I am very interested in modern art.')).toEqual({ before: 'I am very interested ', marked: 'in', after: ' modern art.' })
    expect(correctedSentence(huntTokens, 'tOn4', 'I am very interested in the modern art.')).toEqual({
      before: 'I am very interested ',
      marked: 'in the',
      after: ' modern art.',
    })
    // The last word with its punctuation, and the first one.
    expect(correctedSentence(huntTokens, 'tArt6', 'I am very interested on modern painting.')?.marked).toBe('painting.')
    expect(correctedSentence(huntTokens, 'tI0', 'We am very interested on modern art.')?.marked).toBe('We')
  })

  it('gives up when the corrected sentence does not add up with the words', () => {
    expect(correctedSentence(huntTokens, 'tOn4', 'Completely different.')).toBeNull()
    expect(correctedSentence(huntTokens, 'tOn4', 'You are very interested in modern art.')).toBeNull()
    expect(correctedSentence(huntTokens, 'nope', 'I am very interested in modern art.')).toBeNull()
  })
})
