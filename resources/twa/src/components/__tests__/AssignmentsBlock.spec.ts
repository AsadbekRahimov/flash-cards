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

  it('draws one row per assignment inside the list of the parent: the lesson, the group, the module and the due date on the right', () => {
    const w = block([item()])

    // No label and no card of its own: the rows stand in the list "For today" of Home.
    expect(w.find('.section-label').exists()).toBe(false)
    expect(w.find('.card').exists()).toBe(false)
    expect(w.get('[data-testid=assignment-title]').text()).toBe('Food')
    expect(w.get('[data-testid=assignment]').text()).toContain('Group A · Модуль 2, урок 4 · выполнено 40%')
    // The time is shown in the device's time zone, so only the shape is fixed.
    const due = w.get('[data-testid=assignment-due]')
    expect(due.text()).toMatch(/^до \d{1,2} окт\., \d{2}:\d{2}$/)
    expect(due.classes()).toContain('text-warning-ink')
    expect(w.get('[data-testid=assignment-dot]').classes()).toContain('bg-warning')
    expect(w.get('[data-testid=assignment-dot]').attributes('aria-hidden')).toBe('true')
    expect(w.find('[data-testid=assignment-done]').exists()).toBe(false)
  })

  it('turns the dot and the date red for an open assignment past its due date', () => {
    const w = block([item({ overdue: true })])
    const due = w.get('[data-testid=assignment-due]')

    expect(due.text()).toMatch(/^Срок прошёл: /)
    expect(due.classes()).toContain('text-danger-ink')
    expect(due.classes()).not.toContain('text-warning-ink')
    expect(w.get('[data-testid=assignment-dot]').classes()).toContain('bg-danger')
  })

  it('shows a done assignment with a green dot and the state in green, or amber when it was done late, and no button', () => {
    const w = block([
      item({ id: 1, completed: true, progress_pct: 100 }),
      item({ id: 2, completed: true, late: true, progress_pct: 100 }),
    ])
    const [inTime, late] = w.findAll('[data-testid=assignment-done]')

    expect(inTime?.text()).toBe('Выполнено')
    expect(inTime?.classes()).toContain('text-success-ink')
    expect(late?.text()).toBe('Выполнено с опозданием')
    expect(late?.classes()).toContain('text-warning-ink')
    // The due date no longer matters once it is done.
    expect(w.find('[data-testid=assignment-due]').exists()).toBe(false)
    expect(w.find('[data-testid=assignment-btn]').exists()).toBe(false)
    expect(w.find('button').exists()).toBe(false)
    for (const dot of w.findAll('[data-testid=assignment-dot]')) expect(dot.classes()).toContain('bg-success')
  })

  it('says the learner\'s own progress in words', () => {
    const w = block([item({ progress_pct: 40 })])

    expect(w.get('[data-testid=assignment-progress]').text()).toBe('выполнено 40%')
  })

  it('makes an open assignment a row button of at least 44 px that opens the lesson by its id', async () => {
    const w = block([item({ id: 1, lesson_id: 41, progress_pct: 0 }), item({ id: 2, lesson_id: 42, progress_pct: 40 })])
    const [first, second] = w.findAll('[data-testid=assignment-btn]')

    expect(first?.element.tagName).toBe('BUTTON')
    expect(first?.attributes('type')).toBe('button')
    expect(first?.classes()).toContain('min-h-11')
    expect(first?.text()).toContain('Food')

    await second?.trigger('click')
    await first?.trigger('click')
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

    expect(w.get('[data-testid=assignment]').text()).toContain('Group A · 2-modul, 4-dars · 0% bajarildi')
    expect(w.get('[data-testid=assignment-due]').text()).toMatch(/gacha$/)
  })
})
