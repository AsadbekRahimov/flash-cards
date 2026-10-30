import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '@/i18n'
import { MODE_LOOK, PRACTICE_MODE_ORDER, estimateMinutes, formatDuration, lessonHeader, lessonNumber, modeTitle } from '../practiceLabels'
import { ICONS } from '@/components/ui/icons'

afterEach(() => setLocale('ru'))

describe('practiceLabels', () => {
  it('the four modes in the order of the hub, each with an icon that exists and a colour of its own', () => {
    expect(PRACTICE_MODE_ORDER).toEqual(['pairs', 'collocation', 'sentence', 'dictation'])
    const tiles = PRACTICE_MODE_ORDER.map((mode) => MODE_LOOK[mode].tile)
    expect(new Set(tiles).size).toBe(4)
    for (const mode of PRACTICE_MODE_ORDER) expect(ICONS[MODE_LOOK[mode].icon], mode).toBeDefined()
  })

  it('names a mode in the language of the learner', () => {
    expect(PRACTICE_MODE_ORDER.map(modeTitle)).toEqual(['Найди пары', 'Подбери сочетание', 'Собери предложение', 'Слушай и пиши'])
    setLocale('uz')
    expect(PRACTICE_MODE_ORDER.map(modeTitle)).toEqual(['Juftlarni toping', "So'z birikmasini tanlang", 'Gap tuzing', 'Eshiting va yozing'])
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
