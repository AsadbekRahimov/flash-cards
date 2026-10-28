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

  it('dims a disabled option that has no state yet, not the ones that show the verdict', () => {
    const dim = (state: 'idle' | 'correct' | 'incorrect' | 'reveal' | 'selected', disabled: boolean) =>
      mount(QuizOption, { props: { label: 'x', index: 0, state, disabled } })
        .get('[data-testid=option-0]')
        .classes()
        .includes('opacity-60')

    expect(dim('idle', true)).toBe(true)
    expect(dim('idle', false)).toBe(false)
    for (const state of ['correct', 'incorrect', 'reveal', 'selected'] as const) {
      expect(dim(state, true), state).toBe(false)
    }
  })
})
