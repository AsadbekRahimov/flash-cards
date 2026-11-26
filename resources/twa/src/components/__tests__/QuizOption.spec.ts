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

  it('draws a check on a correct pick and on the right answer, a cross on an incorrect one, and no mark in the ring of an idle one', () => {
    const icon = (state: 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal') =>
      mount(QuizOption, { props: { label: 'x', index: 0, state } }).findComponent(AppIcon)

    expect(icon('selected').props('name')).toBe('check')
    expect(icon('correct').props('name')).toBe('check')
    expect(icon('reveal').props('name')).toBe('check')
    expect(icon('incorrect').props('name')).toBe('x-mark')
    expect(icon('idle').exists()).toBe(false)
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

  it('has no number in front any more: a 24 px round indicator on the right instead (docs/design/2026-11-25-redesign Question)', () => {
    const w = mount(QuizOption, { props: { label: 'apple', index: 2, state: 'idle' } })
    const button = w.get('[data-testid=option-2]')

    expect(button.text()).toBe('apple')
    expect(button.find('.font-mono').exists()).toBe(false)
    const ring = w.get('[data-testid=option-indicator-2]')
    expect(button.element.lastElementChild).toBe(ring.element)
    expect(ring.classes()).toEqual(expect.arrayContaining(['size-6', 'rounded-full', 'border-track']))
    expect(ring.attributes('aria-hidden')).toBe('true')
  })

  it('fills the ring by the state: primary for the pick, success for the right answer, danger for a miss', () => {
    const ring = (state: 'selected' | 'correct' | 'incorrect' | 'reveal') =>
      mount(QuizOption, { props: { label: 'x', index: 0, state } }).get('[data-testid=option-indicator-0]').classes()

    expect(ring('selected')).toEqual(expect.arrayContaining(['bg-primary', 'text-on-primary']))
    expect(ring('correct')).toContain('bg-success')
    expect(ring('reveal')).toContain('bg-success')
    expect(ring('incorrect')).toContain('bg-danger')
  })

  it('leaves the ring out of a dimmed option, as the quiet options of the Feedback mock-up', () => {
    const w = mount(QuizOption, { props: { label: 'x', index: 0, state: 'idle', disabled: true } })
    expect(w.find('[data-testid=option-indicator-0]').exists()).toBe(false)
  })

  it('is the box of the mock-up: radius 14 (rounded-option of .option), 18 px text, at least 58 px high (48 in a low window)', () => {
    const classes = mount(QuizOption, { props: { label: 'x', index: 0, state: 'idle' } }).get('[data-testid=option-0]').classes()
    expect(classes).toEqual(expect.arrayContaining(['option', 'min-h-[58px]', 'text-[18px]', 'short:min-h-12']))
  })

  it('is a radio: aria-checked on the picked option, aria-disabled (still focusable) once it can no longer be picked', () => {
    const option = (state: 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal', disabled = false) =>
      mount(QuizOption, { props: { label: 'x', index: 0, state, disabled } }).get('[data-testid=option-0]')

    expect(option('idle').attributes('role')).toBe('radio')
    expect(option('idle').attributes('aria-checked')).toBe('false')
    expect(option('reveal', true).attributes('aria-checked')).toBe('false')
    for (const state of ['selected', 'correct', 'incorrect'] as const) {
      expect(option(state, true).attributes('aria-checked'), state).toBe('true')
    }
    expect(option('idle').attributes('aria-disabled')).toBeUndefined()
    const disabled = option('reveal', true)
    expect(disabled.attributes('aria-disabled')).toBe('true')
    // Not the native `disabled`: the screen reader still reaches the option and reads "right answer".
    expect(disabled.attributes('disabled')).toBeUndefined()
  })

  it('moves the focus with the arrow keys inside its radio group, around the ends; Enter / Space pick as on a button', async () => {
    const Group = {
      components: { QuizOption },
      emits: ['pick'],
      template: `<div role="radiogroup" aria-label="q">
        <QuizOption v-for="i in 3" :key="i" :label="'o' + i" :index="i - 1" state="idle" @pick="$emit('pick', $event)" />
      </div>`,
    }
    const w = mount(Group, { attachTo: document.body })
    const options = w.findAll('[role=radio]')
    ;(options[0]!.element as HTMLElement).focus()

    await options[0]!.trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(options[1]!.element)
    await options[1]!.trigger('keydown', { key: 'ArrowRight' })
    expect(document.activeElement).toBe(options[2]!.element)
    await options[2]!.trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(options[0]!.element)
    await options[0]!.trigger('keydown', { key: 'ArrowUp' })
    expect(document.activeElement).toBe(options[2]!.element)
    await options[2]!.trigger('keydown', { key: 'ArrowLeft' })
    expect(document.activeElement).toBe(options[1]!.element)

    // A native button: the keyboard "click" of Enter / Space is the same click.
    expect(options[1]!.element.tagName).toBe('BUTTON')
    await options[1]!.trigger('click')
    expect(w.emitted('pick')).toEqual([[1]])
    w.unmount()
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
