import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import QuizOption from '../QuizOption.vue'
import AppIcon from '@/components/ui/AppIcon.vue'

describe('QuizOption', () => {
  it('renders label and reports idle state by default', () => {
    const w = mount(QuizOption, { props: { label: 'apple', index: 0, state: 'idle' } })
    expect(w.text()).toContain('apple')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
  })

  it('is the option button of the design system', () => {
    const w = mount(QuizOption, { props: { label: 'apple', index: 0, state: 'idle' } })
    const button = w.get('[data-testid=option-0]')

    expect(button.element.tagName).toBe('BUTTON')
    expect(button.attributes('type')).toBe('button')
    expect(button.classes()).toContain('option')
  })

  it('emits pick with index when clicked', async () => {
    const w = mount(QuizOption, { props: { label: 'apple', index: 2, state: 'idle' } })
    await w.get('[data-testid=option-2]').trigger('click')
    expect(w.emitted('pick')).toEqual([[2]])
  })

  it('does not emit pick when disabled', async () => {
    const w = mount(QuizOption, {
      props: { label: 'apple', index: 0, state: 'idle', disabled: true },
    })
    await w.get('[data-testid=option-0]').trigger('click')
    expect(w.emitted('pick')).toBeUndefined()
  })

  it('renders correct/incorrect/reveal states via data-state attr', () => {
    const states = ['correct', 'incorrect', 'reveal', 'selected'] as const
    for (const state of states) {
      const w = mount(QuizOption, { props: { label: 'x', index: 0, state } })
      expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe(state)
    }
  })

  it('draws a check on a correct pick and a cross on an incorrect one, and no icon in the other states', () => {
    const icon = (state: 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal') =>
      mount(QuizOption, { props: { label: 'x', index: 0, state } }).findComponent(AppIcon)

    expect(icon('correct').props('name')).toBe('check')
    expect(icon('incorrect').props('name')).toBe('x-mark')
    for (const state of ['idle', 'selected', 'reveal'] as const) {
      expect(icon(state).exists(), state).toBe(false)
    }
  })

  it('gives a disabled option that has no state yet the quiet look of "dimmed", not the ones that show the verdict', () => {
    const option = (state: 'idle' | 'correct' | 'incorrect' | 'reveal' | 'selected', disabled: boolean) =>
      mount(QuizOption, { props: { label: 'x', index: 0, state, disabled } }).get('[data-testid=option-0]')
    const dimmed = (state: 'idle' | 'correct' | 'incorrect' | 'reveal' | 'selected', disabled: boolean) =>
      option(state, disabled).attributes('data-dimmed') === 'true'

    expect(dimmed('idle', true)).toBe(true)
    expect(option('idle', true).classes()).toEqual(expect.arrayContaining(['bg-transparent', 'text-muted']))
    // Not faded with opacity: the muted text keeps its contrast.
    expect(option('idle', true).classes()).not.toContain('opacity-60')
    // data-state stays the one the screen gave (the exam and the training read it).
    expect(option('idle', true).attributes('data-state')).toBe('idle')
    expect(dimmed('idle', false)).toBe(false)
    for (const state of ['correct', 'incorrect', 'reveal', 'selected'] as const) {
      expect(dimmed(state, true), state).toBe(false)
    }
  })

  it('leads with its number, in mono and hidden from the screen reader', () => {
    const w = mount(QuizOption, { props: { label: 'apple', index: 2, state: 'idle' } })
    const number = w.get('[data-testid=option-2] span')

    expect(number.text()).toBe('3')
    expect(number.classes()).toEqual(expect.arrayContaining(['font-mono', 'text-muted']))
    expect(number.attributes('aria-hidden')).toBe('true')
  })

  it('names the verdict in words, not by colour only: "your answer" on a miss, "right answer" on the right one', () => {
    const caption = (state: 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal') =>
      mount(QuizOption, { props: { label: 'x', index: 1, state } }).find('[data-testid=option-caption-1]')

    expect(caption('incorrect').text()).toBe('ваш ответ')
    expect(caption('reveal').text()).toBe('верный ответ')
    expect(caption('correct').text()).toBe('верный ответ')
    for (const state of ['idle', 'selected'] as const) {
      expect(caption(state).exists(), state).toBe(false)
    }
  })
})
