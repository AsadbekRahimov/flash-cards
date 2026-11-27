import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WeekCalendar from '../today/WeekCalendar.vue'
import { setLocale } from '@/i18n'
import type { StreakWeekDay } from '@/api/types'

/* The week of F4 (kit F4-seriya-spasena.png): done - a muhr check, freeze - a snowflake, today - a ring with the day, future - dashed. */

const KIT: StreakWeekDay[] = [
  { date: '2026-11-23', held_by: 'goal', is_today: false },
  { date: '2026-11-24', held_by: 'goal', is_today: false },
  { date: '2026-11-25', held_by: 'game', is_today: false },
  { date: '2026-11-26', held_by: 'goal', is_today: false },
  { date: '2026-11-27', held_by: 'freeze', is_today: false },
  { date: '2026-11-28', held_by: null, is_today: true },
  { date: '2026-11-29', held_by: null, is_today: false },
]

const states = (w: ReturnType<typeof mount>) => w.findAll('[data-testid=week-day]').map((day) => day.attributes('data-state'))

describe('WeekCalendar', () => {
  afterEach(() => setLocale('ru'))

  it('draws the four states of the kit, Monday first', () => {
    const w = mount(WeekCalendar, { props: { week: KIT } })

    expect(states(w)).toEqual(['done', 'done', 'done', 'done', 'freeze', 'today', 'future'])
    const days = w.findAll('[data-testid=week-day]')
    expect(days[0]?.find('.bg-muhr').exists()).toBe(true)
    expect(days[4]?.find('.bg-sunken').exists()).toBe(true)
    // today: a ring in border-strong with the number of the day, which pulses once (the class of the animation)
    const today = days[5]?.get('.day-mark')
    expect(today?.classes()).toEqual(expect.arrayContaining(['border-strong', 'day-mark--today']))
    expect(today?.text()).toBe('28')
    expect(days[5]?.attributes('data-today')).toBe('true')
    expect(days[6]?.get('.day-mark').classes()).toContain('border-dashed')
  })

  it('signs the days in ru and uz and names each circle for a screen reader', () => {
    const w = mount(WeekCalendar, { props: { week: KIT } })
    expect(w.findAll('[data-testid=week-day]').map((day) => day.text())).toEqual(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', '28Сб', 'Вс'])
    expect(w.findAll('[data-testid=week-day]').map((day) => day.attributes('aria-label'))).toEqual([
      'Пн: день засчитан',
      'Вт: день засчитан',
      'Ср: день засчитан',
      'Чт: день засчитан',
      'Пт: заморозка',
      'Сб: сегодня',
      'Вс: впереди',
    ])
    expect(w.get('[data-testid=week-calendar]').attributes('aria-label')).toBe('Эта неделя')

    setLocale('uz')
    const uz = mount(WeekCalendar, { props: { week: KIT } })
    expect(uz.findAll('[data-testid=week-day]').map((day) => day.text())).toEqual(['Du', 'Se', 'Ch', 'Pa', 'Ju', '28Sh', 'Ya'])
    expect(uz.findAll('[data-testid=week-day]')[4]?.attributes('aria-label')).toBe('Ju: zaxira kun')
  })

  it('takes the name of a day from its date, and shows a held today as held', () => {
    const w = mount(WeekCalendar, {
      props: {
        week: [
          { date: '2026-11-30', held_by: 'goal', is_today: true },
          ...['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06'].map((date) => ({ date, held_by: null, is_today: false })),
        ],
      },
    })

    expect(states(w)).toEqual(['done', 'future', 'future', 'future', 'future', 'future', 'future'])
    expect(w.findAll('[data-testid=week-day]')[0]?.text()).toBe('Пн')
    expect(w.findAll('[data-testid=week-day]')[0]?.get('span:last-child').classes()).toContain('font-semibold')
  })

  it('shows a past day nothing held as missed (a lost streak)', () => {
    const w = mount(WeekCalendar, {
      props: {
        week: KIT.map((day, index) => (index === 4 ? { ...day, held_by: null } : day)),
      },
    })

    expect(states(w)[4]).toBe('missed')
    expect(w.findAll('[data-testid=week-day]')[4]?.attributes('aria-label')).toBe('Пт: пропущен')
  })
})
