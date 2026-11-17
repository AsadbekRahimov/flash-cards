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

  it('draws a filled bar only for weeks with a result, oldest first, and a bare mark for gaps', () => {
    const w = mount(WeeklyQuizChart, {
      props: { history: [item('2026-11-02', 6), item('2026-11-16', 9)], weeks: 4, currentWeek: '2026-11-16' },
    })

    const bars = w.findAll('[data-testid^=quiz-bar-]')
    expect(bars.map((b) => b.attributes('data-testid'))).toEqual(['quiz-bar-2026-11-02', 'quiz-bar-2026-11-16'])
    expect(bars.map((b) => b.attributes('data-percent'))).toEqual(['50', '75'])
    expect(bars[1]!.attributes('style')).toContain('height: 75%')
    expect(bars[1]!.attributes('data-current')).toBe('true')
    expect(bars[0]!.attributes('data-current')).toBe('false')

    const gap = w.get('[data-testid=quiz-gap-2026-10-26]')
    expect(gap.attributes('style')).toBeUndefined()
    expect(gap.classes()).not.toContain('bg-accent')
    expect(w.find('[data-testid=quiz-bar-2026-10-26]').exists()).toBe(false)
    expect(w.find('[data-testid=quiz-bar-2026-11-09]').exists()).toBe(false)
  })

  it('keeps a 0% result visible with its value above it', () => {
    const w = mount(WeeklyQuizChart, { props: { history: [item('2026-11-16', 0)], weeks: 4, currentWeek: '2026-11-16' } })

    const bar = w.get('[data-testid=quiz-bar-2026-11-16]')
    expect(bar.attributes('data-percent')).toBe('0')
    expect(bar.attributes('style')).toContain('height: 4%')
    expect(w.findAll('li')[3]!.text()).toContain('0')
  })

  it('cuts the empty weeks left of the first result when there are fewer than three results, but keeps 4 columns', () => {
    const few = mount(WeeklyQuizChart, { props: { history: [item('2026-11-02', 6)], weeks: 12, currentWeek: '2026-11-16' } })
    expect(few.findAll('li')).toHaveLength(3 + 1)
    expect(few.findAll('li')[0]!.find('[data-testid=quiz-bar-2026-11-02]').exists()).toBe(true)

    const recent = mount(WeeklyQuizChart, { props: { history: [item('2026-11-16', 6)], weeks: 12, currentWeek: '2026-11-16' } })
    expect(recent.findAll('li')).toHaveLength(4)

    const many = mount(WeeklyQuizChart, {
      props: { history: [item('2026-11-02', 6), item('2026-11-09', 6), item('2026-11-16', 6)], weeks: 12, currentWeek: '2026-11-16' },
    })
    expect(many.findAll('li')).toHaveLength(12)
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
