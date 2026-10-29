import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PracticeSummary from '../PracticeSummary.vue'
import { setLocale } from '@/i18n'
import { summary } from '@/api/__tests__/practiceFixtures'
import type { PracticeMode } from '@/api/types'

afterEach(() => setLocale('ru'))

const render = (props: Partial<InstanceType<typeof PracticeSummary>['$props']> = {}, mode: PracticeMode = 'sentence') =>
  mount(PracticeSummary, { props: { summary: summary(), mode, ...props } })

const text = (wrapper: ReturnType<typeof render>, testid: string): string => wrapper.get(`[data-testid=${testid}]`).text()

describe('PracticeSummary — the numbers', () => {
  it('done / right from the first try / hints', () => {
    const wrapper = render()

    expect(text(wrapper, 'stat-done')).toBe('5 / 5')
    expect(text(wrapper, 'stat-first')).toBe('3')
    expect(text(wrapper, 'stat-third')).toBe('1')
    const labels = wrapper.get('[data-testid=summary-stats]').text()
    expect(labels).toContain('Выполнено')
    expect(labels).toContain('Верно с первой попытки')
    expect(labels).toContain('Подсказки')
  })

  it('pairs: rounds / right from the first try / wrong matches', () => {
    const wrapper = render({ summary: summary({ total: 3, answered: 3, correct_first: 9, hints: 0, mismatches: 4 }) }, 'pairs')

    expect(text(wrapper, 'stat-done')).toBe('3 / 3')
    expect(text(wrapper, 'stat-first')).toBe('9')
    expect(text(wrapper, 'stat-third')).toBe('4')
    const labels = wrapper.get('[data-testid=summary-stats]').text()
    expect(labels).toContain('Раундов')
    expect(labels).toContain('Ошибок')
    expect(labels).not.toContain('Подсказки')
  })

  it('pairs without a count of wrong matches say none', () => {
    expect(text(render({ summary: summary({ mismatches: null }) }, 'pairs'), 'stat-third')).toBe('0')
  })

  it('a session that was cut short shows what was done of what was planned', () => {
    expect(text(render({ summary: summary({ answered: 3, total: 5, finish_reason: 'expired' }) }), 'stat-done')).toBe('3 / 5')
  })

  it('says how long it took, and how many typos there were, when there were some', () => {
    expect(text(render({ summary: summary({ duration_ms: 93_000, typos: 2 }) }), 'summary-facts')).toBe('Время: 1:33 · Опечаток: 2')
    expect(text(render({ summary: summary({ duration_ms: 7_000, typos: 0 }) }), 'summary-facts')).toBe('Время: 0:07')
  })

  it.each([
    ['completed', 'Практика пройдена.'],
    ['expired', 'Время практики вышло. Вот что успели.'],
    ['replaced', 'Эта практика закрыта: вы начали новую.'],
  ] as const)('says why it ended: %s', (finish_reason, line) => {
    expect(text(render({ summary: summary({ finish_reason }) }), 'summary-reason')).toBe(line)
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    const wrapper = render()

    expect(wrapper.get('h1').text()).toBe('Tayyor!')
    expect(wrapper.get('[data-testid=summary-stats]').text()).toContain('Bajarildi')
  })
})

describe('PracticeSummary — the mistakes', () => {
  it('one row each: what it was, what was written (struck through, and named for a screen reader) and the kind', () => {
    const wrapper = render()

    const rows = wrapper.findAll('[data-testid=mistake-row]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.get('[data-testid=mistake-label]').text()).toBe('See you tomorrow')
    expect(rows[0]!.get('[data-testid=mistake-answer]').text()).toContain('see you tomorow')
    expect(rows[0]!.get('[data-testid=mistake-answer] .line-through').text()).toBe('see you tomorow')
    expect(rows[0]!.get('[data-testid=mistake-answer] .sr-only').text()).toContain('Ваш ответ')
  })

  it('each kind has its chip, its symbol and its word', () => {
    const wrapper = render({
      summary: summary({
        mistakes: [
          { element_id: 'a', kind: 'dictation', label: 'A', your_answer: 'a', verdict: 'typo' },
          { element_id: 'b', kind: 'sentence', label: 'B', your_answer: 'b', verdict: 'wrong' },
          { element_id: 'c', kind: 'collocation', label: 'C', your_answer: null, verdict: 'skipped' },
        ],
      }),
    })

    const chips = wrapper.findAll('[data-testid=mistake-chip]')
    expect(chips.map((chip) => chip.text())).toEqual(['Опечатка', 'Ошибка', 'Подсказка'])
    expect(chips.map((chip) => chip.classes().find((c) => c.startsWith('chip-')))).toEqual(['chip-warning', 'chip-danger', 'chip-accent'])
    expect(chips.every((chip) => chip.find('svg').exists())).toBe(true)
  })

  it('a row without an answer of the learner (a hint, a word of pairs) has no struck-through line', () => {
    const wrapper = render({ summary: summary({ mistakes: [{ element_id: 'c', kind: 'collocation', label: 'C', your_answer: null, verdict: 'skipped' }] }) })

    expect(wrapper.find('[data-testid=mistake-answer]').exists()).toBe(false)
  })

  it('no mistakes: a word of praise instead of the list', () => {
    const wrapper = render({ summary: summary({ mistakes: [], retry_available: false }) })

    expect(wrapper.find('[data-testid=mistakes]').exists()).toBe(false)
    expect(text(wrapper, 'no-mistakes')).toBe('Ни одной ошибки. Отличная работа!')
  })

  it('shows what the server wrote, as text', () => {
    const wrapper = render({ summary: summary({ mistakes: [{ element_id: 'a', kind: 'sentence', label: '<i>x</i>', your_answer: '<b>y</b>', verdict: 'wrong' }] }) })

    expect(wrapper.get('[data-testid=mistake-label]').text()).toBe('<i>x</i>')
    expect(wrapper.find('[data-testid=mistake-row] i').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mistake-row] b').exists()).toBe(false)
  })
})

describe('PracticeSummary — what next', () => {
  it('"Repeat mistakes" is the one primary button when the server allows it', async () => {
    const wrapper = render()

    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    expect(wrapper.get('[data-testid=retry-btn]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid=retry-btn]').text()).toBe('Повторить ошибки')
    expect(wrapper.find('[data-testid=again-btn]').exists()).toBe(false)

    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('without mistakes to repeat it is "Again" that is primary', async () => {
    const wrapper = render({ summary: summary({ retry_available: false }) })

    expect(wrapper.find('[data-testid=retry-btn]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=again-btn]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid=again-btn]').text()).toBe('Ещё раз')
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)

    await wrapper.get('[data-testid=again-btn]').trigger('click')
    expect(wrapper.emitted('again')).toHaveLength(1)
  })

  it('after the server has refused the repeat once, "Again" takes its place', () => {
    const wrapper = render({ retryUnavailable: true })

    expect(wrapper.find('[data-testid=retry-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=again-btn]').exists()).toBe(true)
  })

  it('"To the course" is tinted, "Close" is plain', async () => {
    const wrapper = render()

    expect(wrapper.get('[data-testid=course-btn]').classes()).toContain('btn-tinted')
    expect(wrapper.get('[data-testid=course-btn]').text()).toBe('В курс')
    expect(wrapper.get('[data-testid=close-btn]').classes()).toContain('btn-plain')

    await wrapper.get('[data-testid=course-btn]').trigger('click')
    await wrapper.get('[data-testid=close-btn]').trigger('click')
    expect(wrapper.emitted('course')).toHaveLength(1)
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('the buttons are at the bottom of the screen', () => {
    expect(render().get('[data-testid=actions]').findAll('button')).toHaveLength(3)
  })

  it('while a new session is being opened the two that open one wait', () => {
    expect(render({ busy: true }).get('[data-testid=retry-btn]').attributes('disabled')).toBeDefined()
    expect(render({ busy: true, summary: summary({ retry_available: false }) }).get('[data-testid=again-btn]').attributes('disabled')).toBeDefined()
  })
})
