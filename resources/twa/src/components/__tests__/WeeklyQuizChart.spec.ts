import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WeeklyQuizChart from '../WeeklyQuizChart.vue'
import { setLocale } from '@/i18n'

const item = (week_start: string, correct: number, total = 12) => ({ week_start, correct, total, percent: Math.floor((100 * correct) / total) })

describe('WeeklyQuizChart', () => {
  afterEach(() => setLocale('ru'))

  it('says there are no results yet for an empty history', () => {
    const w = mount(WeeklyQuizChart, { props: { history: [], weeks: 12, currentWeek: '2026-11-16' } })

    expect(w.get('[data-testid=quiz-chart-empty]').text()).toBe('Пока нет результатов')
    expect(w.find('[data-testid=quiz-chart]').exists()).toBe(false)
  })

  it('draws one bar per week, oldest first, with gaps left empty', () => {
    const w = mount(WeeklyQuizChart, {
      props: { history: [item('2026-11-02', 6), item('2026-11-16', 9)], weeks: 4, currentWeek: '2026-11-16' },
    })

    const bars = w.findAll('[data-testid^=quiz-bar-]')
    expect(bars.map((b) => b.attributes('data-testid'))).toEqual([
      'quiz-bar-2026-10-26',
      'quiz-bar-2026-11-02',
      'quiz-bar-2026-11-09',
      'quiz-bar-2026-11-16',
    ])
    expect(bars.map((b) => b.attributes('data-percent'))).toEqual(['', '50', '', '75'])
    expect(bars[3]!.attributes('style')).toContain('height: 75%')
    expect(bars[0]!.attributes('style')).toContain('height: 0%')
  })

  it('labels every bar for assistive technology, in both languages', () => {
    const w = mount(WeeklyQuizChart, { props: { history: [item('2026-11-16', 9)], weeks: 2, currentWeek: '2026-11-16' } })
    const labels = w.findAll('li').map((li) => li.attributes('aria-label'))
    expect(labels[1]).toContain('9 из 12')
    expect(labels[0]).toContain('теста не было')

    setLocale('uz')
    const uz = mount(WeeklyQuizChart, { props: { history: [item('2026-11-16', 9)], weeks: 2, currentWeek: '2026-11-16' } })
    expect(uz.findAll('li')[1]!.attributes('aria-label')).toContain('12 tadan 9 ta')
  })

  it('counts weeks across a year boundary', () => {
    const w = mount(WeeklyQuizChart, { props: { history: [], weeks: 1, currentWeek: '2027-01-04' } })
    expect(w.find('[data-testid=quiz-chart-empty]').exists()).toBe(true)
    const full = mount(WeeklyQuizChart, { props: { history: [item('2026-12-28', 3)], weeks: 2, currentWeek: '2027-01-04' } })
    expect(full.findAll('[data-testid^=quiz-bar-]')[0]!.attributes('data-testid')).toBe('quiz-bar-2026-12-28')
  })
})
