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

  it('draws a check on the right answer (picked or revealed), a cross on a miss, and no mark before the check', () => {
    const icon = (state: 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal') =>
      mount(QuizOption, { props: { label: 'x', index: 0, state } }).findComponent(AppIcon)

    expect(icon('correct').props('name')).toBe('check')
    expect(icon('reveal').props('name')).toBe('check')
    expect(icon('incorrect').props('name')).toBe('x-mark')
    expect(icon('idle').exists()).toBe(false)
    expect(icon('selected').exists()).toBe(false)
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

  it('has the 28 px key with the number on the left (kit AnswerOption), decorative: the name of the option is its label', () => {
    const w = mount(QuizOption, { props: { label: 'apple', index: 2, state: 'idle' } })
    const button = w.get('[data-testid=option-2]')
    const key = w.get('[data-testid=option-key-2]')

    expect(button.element.firstElementChild).toBe(key.element)
    expect(key.text()).toBe('3')
    expect(key.attributes('aria-hidden')).toBe('true')
    expect(key.classes()).toEqual(expect.arrayContaining(['size-7', 'rounded-xs', 'bg-sunken', 'text-muted']))
    expect(w.get('[data-testid=option-label]').text()).toBe('apple')
    expect(w.find('[data-testid=option-indicator-2]').exists()).toBe(false)
  })

  it.each([
    ['selected', ['bg-inverse', 'text-on-inverse'], null],
    ['correct', ['bg-success-ink', 'text-on-inverse'], ['check', 'text-success-ink']],
    ['reveal', ['bg-success-ink', 'text-on-inverse'], ['check', 'text-success-ink']],
    ['incorrect', ['bg-danger-ink', 'text-on-inverse'], ['x-mark', 'text-danger-ink']],
  ] as const)('paints the key and the mark of the state %s', (state, keyClasses, mark) => {
    const w = mount(QuizOption, { props: { label: 'x', index: 0, state } })

    expect(w.get('[data-testid=option-key-0]').classes()).toEqual(expect.arrayContaining([...keyClasses]))
    const indicator = w.find('[data-testid=option-indicator-0]')
    expect(indicator.exists()).toBe(mark !== null)
    if (mark) {
      expect(indicator.findComponent(AppIcon).props('name')).toBe(mark[0])
      expect(indicator.classes()).toContain(mark[1])
      expect(indicator.attributes('aria-hidden')).toBe('true')
      // the right edge: the mark is the last thing in the box
      expect(w.get('[data-testid=option-0]').element.lastElementChild).toBe(indicator.element)
    }
  })

  it('gives the key of a dimmed option the quiet look too', () => {
    const w = mount(QuizOption, { props: { label: 'x', index: 0, state: 'idle', disabled: true } })
    expect(w.get('[data-testid=option-key-0]').classes()).toEqual(expect.arrayContaining(['bg-transparent', 'text-muted']))
    expect(w.find('[data-testid=option-indicator-0]').exists()).toBe(false)
  })

  it('is the box of the kit: .option (radius md, Body/L), at least 60 px high (48 in a low window)', () => {
    const classes = mount(QuizOption, { props: { label: 'x', index: 0, state: 'idle' } }).get('[data-testid=option-0]').classes()
    expect(classes).toEqual(expect.arrayContaining(['option', 'min-h-[60px]', 'short:min-h-12']))
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
