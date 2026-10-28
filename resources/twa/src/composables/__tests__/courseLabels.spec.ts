import { afterEach, describe, expect, it } from 'vitest'
import { catalogTabs, courseChips, goalLabel, progressPercent, progressText } from '../courseLabels'
import { setLocale } from '@/i18n'

afterEach(() => setLocale('ru'))

const course = (overrides: Partial<{ level: string | null; exam_tag: string | null; words_count: number }> = {}) => ({
  level: 'A1',
  exam_tag: 'general',
  words_count: 500,
  ...overrides,
})

describe('courseChips', () => {
  it('lists the level, the goal and the size, in this order', () => {
    expect(courseChips(course())).toEqual([
      { key: 'level', label: 'A1', tone: 'neutral' },
      { key: 'goal', label: 'Общий английский', tone: 'neutral' },
      { key: 'words', label: '500 слов', tone: 'neutral' },
    ])
  })

  it.each([
    ['ielts', 'IELTS'],
    ['sat', 'SAT'],
  ])('puts the %s exam goal in the accent colour', (tag, label) => {
    expect(courseChips(course({ exam_tag: tag })).find((chip) => chip.key === 'goal')).toEqual({ key: 'goal', label, tone: 'accent' })
  })

  it('keeps the other goals neutral', () => {
    expect(courseChips(course({ exam_tag: 'school' })).find((chip) => chip.key === 'goal')).toEqual({
      key: 'goal',
      label: 'Школа',
      tone: 'neutral',
    })
  })

  it('shows an unknown goal as it came, neutral', () => {
    expect(courseChips(course({ exam_tag: 'toefl' })).find((chip) => chip.key === 'goal')).toEqual({
      key: 'goal',
      label: 'toefl',
      tone: 'neutral',
    })
  })

  it.each([null, ''])('leaves out the level %j', (level) => {
    expect(courseChips(course({ level })).map((chip) => chip.key)).toEqual(['goal', 'words'])
  })

  it.each([null, ''])('leaves out the goal %j', (tag) => {
    expect(courseChips(course({ exam_tag: tag })).map((chip) => chip.key)).toEqual(['level', 'words'])
  })

  it('always keeps the size, even for a course with nothing else', () => {
    expect(courseChips(course({ level: null, exam_tag: null, words_count: 1 }))).toEqual([{ key: 'words', label: '1 слово', tone: 'neutral' }])
  })

  it.each([
    [0, '0 слов'],
    [1, '1 слово'],
    [2, '2 слова'],
    [5, '5 слов'],
    [21, '21 слово'],
    [900, '900 слов'],
  ])('inflects the size: %i', (count, label) => {
    expect(courseChips(course({ words_count: count })).at(-1)?.label).toBe(label)
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    expect(courseChips(course({ exam_tag: 'ielts' })).map((chip) => chip.label)).toEqual(['A1', 'IELTS', "500 ta so'z"])
    expect(courseChips(course()).map((chip) => chip.label)).toEqual(['A1', 'Umumiy ingliz tili', "500 ta so'z"])
  })
})

describe('goalLabel', () => {
  it('translates the known goals', () => {
    expect(goalLabel('general')).toBe('Общий английский')
    expect(goalLabel('school')).toBe('Школа')
    expect(goalLabel('ielts')).toBe('IELTS')
    expect(goalLabel('sat')).toBe('SAT')
    setLocale('uz')
    expect(goalLabel('school')).toBe('Maktab')
  })

  it('returns an unknown tag as is, and null for no goal', () => {
    expect(goalLabel('toefl')).toBe('toefl')
    expect(goalLabel(null)).toBeNull()
    expect(goalLabel('')).toBeNull()
  })
})

describe('progressPercent', () => {
  it.each([
    [{ done: 0, total: 0 }, 0],
    [{ done: 0, total: 10 }, 0],
    [{ done: 2, total: 5 }, 40],
    [{ done: 1, total: 3 }, 33],
    [{ done: 2, total: 3 }, 67],
    [{ done: 5, total: 5 }, 100],
  ])('%j -> %i', (progress, percent) => {
    expect(progressPercent(progress)).toBe(percent)
  })

  it('never goes over 100 and stays 0 for an empty course', () => {
    expect(progressPercent({ done: 9, total: 5 })).toBe(100)
    expect(progressPercent({ done: 3, total: 0 })).toBe(0)
  })
})

describe('progressText', () => {
  it('is the caption "Learned N of M" in both languages', () => {
    expect(progressText({ done: 184, total: 900 })).toBe('Изучено 184 из 900')
    setLocale('uz')
    expect(progressText({ done: 184, total: 900 })).toBe("900 tadan 184 tasi o'rganildi")
  })
})

describe('catalogTabs', () => {
  it('is "Courses | My decks", pointing at the two roots of the tab', () => {
    expect(catalogTabs()).toEqual([
      { to: { name: 'courses' }, label: 'Курсы', testid: 'seg-courses' },
      { to: { name: 'decks' }, label: 'Мои колоды', testid: 'seg-decks' },
    ])
  })

  it('follows the interface language', () => {
    setLocale('uz')

    expect(catalogTabs().map((item) => item.label)).toEqual(['Kurslar', "Mening to'plamlarim"])
  })
})
