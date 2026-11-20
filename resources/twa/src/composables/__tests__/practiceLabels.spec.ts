import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '@/i18n'
import { KNOWN_PRACTICE_MODES, PRACTICE_MODE_ORDER, estimateMinutes, isKnownPracticeMode, formatDuration, lessonHeader, lessonNumber, modeTitle } from '../practiceLabels'

afterEach(() => setLocale('ru'))

describe('practiceLabels', () => {
  it('the modes in the order of the hub', () => {
    expect(PRACTICE_MODE_ORDER).toEqual(['pairs', 'collocation', 'sentence', 'dictation', 'pairs_timed', 'word_form', 'error_hunt'])
  })

  it('names a mode in the language of the learner', () => {
    expect(PRACTICE_MODE_ORDER.map(modeTitle)).toEqual(['Найди пары', 'Подбери сочетание', 'Собери предложение', 'Слушай и пиши', 'Пары на время', 'Форма слова', 'Найди ошибку'])
    setLocale('uz')
    expect(PRACTICE_MODE_ORDER.map(modeTitle)).toEqual(['Juftlarni toping', "Birikmani to'ldiring", 'Gap tuzing', 'Tinglang va yozing', 'Vaqtga juftlar', "So'z shakli", 'Xatoni toping'])
  })

  it('knows its own modes and nothing else: a mode of a newer server is not one of them', () => {
    for (const mode of PRACTICE_MODE_ORDER) expect(isKnownPracticeMode(mode), mode).toBe(true)
    for (const value of ['speed_round', '', null, undefined, 3, 'toString']) expect(isKnownPracticeMode(value), String(value)).toBe(false)
    expect(KNOWN_PRACTICE_MODES).toEqual(PRACTICE_MODE_ORDER)
  })

  it('says where a lesson is, with its name when it has one', () => {
    expect(lessonNumber({ id: 41, stage: 2, number: 4, title: 'Еда' })).toBe('Модуль 2 · Урок 4')
    expect(lessonHeader({ id: 41, stage: 2, number: 4, title: 'Еда' })).toBe('Модуль 2 · Урок 4 · Еда')
    expect(lessonHeader({ id: 41, stage: 2, number: 4, title: null })).toBe('Модуль 2 · Урок 4')
    setLocale('uz')
    expect(lessonHeader({ id: 41, stage: 2, number: 4, title: null })).toContain('2')
  })

  it('never writes "null": a lesson outside a module is "Lesson 4", one with no number is its title or nothing', () => {
    expect(lessonNumber({ id: 41, stage: null, number: 4, title: 'Еда' })).toBe('Урок 4')
    expect(lessonHeader({ id: 41, stage: null, number: 4, title: 'Еда' })).toBe('Урок 4 · Еда')
    expect(lessonHeader({ id: 41, stage: 2, number: null, title: 'Еда' })).toBe('Еда')
    expect(lessonNumber({ id: 41, stage: 2, number: null, title: 'Еда' })).toBe('')
    expect(lessonHeader({ id: 41, stage: null, number: null, title: null })).toBe('')
    setLocale('uz')
    expect(lessonHeader({ id: 41, stage: null, number: 4, title: null })).toBe('4-dars')
  })

  it('rounds a length to whole minutes, never under one', () => {
    expect([0, 29, 30, 89, 90, 120, 149, 150, 300].map(estimateMinutes)).toEqual([1, 1, 1, 1, 2, 2, 2, 3, 5])
  })

  it('writes a duration as minutes and two-digit seconds', () => {
    expect([0, 999, 7_000, 59_400, 93_000, 600_000, 3_723_000].map(formatDuration)).toEqual(['0:00', '0:01', '0:07', '0:59', '1:33', '10:00', '62:03'])
    expect(formatDuration(-5)).toBe('0:00')
  })
})
