import { afterEach, describe, expect, it } from 'vitest'
import { catalogTabs, progressPercent, progressText } from '../courseLabels'
import { setLocale } from '@/i18n'

afterEach(() => setLocale('ru'))

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
