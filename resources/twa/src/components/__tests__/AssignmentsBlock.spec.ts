import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AssignmentsBlock from '../AssignmentsBlock.vue'
import { setLocale } from '@/i18n'
import type { AssignmentItem } from '@/api/types'

const item = (overrides: Partial<AssignmentItem> = {}): AssignmentItem => ({
  id: 7,
  title: 'Food',
  group: 'Group A',
  lesson_id: 41,
  stage: 2,
  lesson: 4,
  due_at: '2026-10-30T13:00:00+00:00',
  overdue: false,
  progress_pct: 40,
  completed: false,
  completed_at: null,
  late: false,
  ...overrides,
})

const block = (items: AssignmentItem[], busy = false) => mount(AssignmentsBlock, { props: { items, busy } })

describe('AssignmentsBlock', () => {
  afterEach(() => setLocale('ru'))

  it('labels the block and shows the lesson with the module, the group and the due date in a neutral chip', () => {
    const w = block([item()])

    expect(w.get('.section-label').text()).toBe('Задания центра')
    expect(w.get('[data-testid=assignment-title]').text()).toBe('Food')
    expect(w.get('[data-testid=assignment]').text()).toContain('Group A · Модуль 2, урок 4')
    // The time is shown in the device's time zone, so only the shape is fixed.
    const due = w.get('[data-testid=assignment-due]')
    expect(due.text()).toMatch(/^до \d{1,2} окт\., \d{2}:\d{2}$/)
    expect(due.classes()).toEqual(expect.arrayContaining(['chip', 'chip-neutral']))
    expect(w.find('[data-testid=assignment-done]').exists()).toBe(false)
  })

  it('turns the chip red for an open assignment past its due date', () => {
    const due = block([item({ overdue: true })]).get('[data-testid=assignment-due]')

    expect(due.text()).toMatch(/^Срок прошёл: /)
    expect(due.classes()).toContain('chip-danger')
    expect(due.classes()).not.toContain('chip-neutral')
  })

  it('shows a done assignment with a green chip, or an amber one when it was done late, and no button', () => {
    const w = block([
      item({ id: 1, completed: true, progress_pct: 100 }),
      item({ id: 2, completed: true, late: true, progress_pct: 100 }),
    ])
    const [inTime, late] = w.findAll('[data-testid=assignment-done]')

    expect(inTime?.text()).toBe('Выполнено')
    expect(inTime?.classes()).toContain('chip-success')
    expect(late?.text()).toBe('Выполнено с опозданием')
    expect(late?.classes()).toContain('chip-warning')
    // The due date no longer matters once it is done.
    expect(w.find('[data-testid=assignment-due]').exists()).toBe(false)
    expect(w.find('[data-testid=assignment-btn]').exists()).toBe(false)
    expect(w.findAll('.progress-success')).toHaveLength(2)
  })

  it('draws the learner\'s own progress as a bar named for assistive tech, and as a percent', () => {
    const w = block([item({ progress_pct: 40 })])
    const bar = w.get('[role=progressbar]')

    expect(w.get('[data-testid=assignment-progress]').text()).toBe('40%')
    expect(bar.attributes('aria-valuenow')).toBe('40')
    expect(bar.attributes('aria-label')).toBe('Пройдено 40% слов урока')
    expect(w.get('.progress-fill').attributes('style')).toContain('width: 40%')
    expect(bar.classes()).not.toContain('progress-success')
  })

  it('offers "Start" before anything is done and "Continue" afterwards, with the lesson id', async () => {
    const w = block([item({ id: 1, lesson_id: 41, progress_pct: 0 }), item({ id: 2, lesson_id: 42, progress_pct: 40 })])
    const [start, resume] = w.findAll('[data-testid=assignment-btn]')

    expect(start?.text()).toBe('Начать')
    expect(resume?.text()).toBe('Продолжить')
    expect(start?.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))

    await resume?.trigger('click')
    await start?.trigger('click')
    expect(w.emitted('open')).toEqual([[42], [41]])
  })

  it('disables the buttons while a session is being opened', async () => {
    const w = block([item()], true)
    const button = w.get('[data-testid=assignment-btn]')

    expect(button.attributes('disabled')).toBeDefined()
    await button.trigger('click')
    expect(w.emitted('open')).toBeUndefined()
  })

  it('renders in Uzbek', () => {
    setLocale('uz')
    const w = block([item({ progress_pct: 0 })])

    expect(w.get('.section-label').text()).toBe('Markaz vazifalari')
    expect(w.get('[data-testid=assignment]').text()).toContain('Group A · 2-modul, 4-dars')
    expect(w.get('[data-testid=assignment-due]').text()).toMatch(/gacha$/)
    expect(w.get('[data-testid=assignment-btn]').text()).toBe('Boshlash')
  })
})
