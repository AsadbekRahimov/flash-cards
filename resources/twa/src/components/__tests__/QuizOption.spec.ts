import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import QuizOption from '../QuizOption.vue'

describe('QuizOption', () => {
  it('renders label and reports idle state by default', () => {
    const w = mount(QuizOption, { props: { label: 'apple', index: 0, state: 'idle' } })
    expect(w.text()).toContain('apple')
    expect(w.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
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
})
