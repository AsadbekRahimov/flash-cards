import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { CHOICE_FEEDBACK_MS, sameText, useChoice } from '../useChoice'

/** `useChoice` hooks into the component lifecycle (the timer is dropped on unmount), so it runs inside a component. */
function mountChoice(options: string[], correct: string) {
  const onGrade = vi.fn()
  let choice!: ReturnType<typeof useChoice>
  const wrapper = mount(
    defineComponent({
      setup() {
        choice = useChoice(ref(options), ref(correct), onGrade)
        return () => h('div')
      },
    }),
  )
  return { choice, onGrade, wrapper }
}

const OPTIONS = ['kitchen', 'chicken', 'bedroom', 'garden']

describe('useChoice', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('grades a right pick with 4 after the feedback pause, with nothing to confirm', () => {
    const { choice, onGrade } = mountChoice(OPTIONS, 'Kitchen')

    choice.pick(0)

    expect(choice.picked.value).toBe(0)
    expect(choice.isCorrect.value).toBe(true)
    expect(choice.stateOf(0)).toBe('correct')
    expect(choice.stateOf(1)).toBe('idle')
    expect(choice.awaitingConfirm.value).toBe(false)
    expect(onGrade).not.toHaveBeenCalled()

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS - 1)
    expect(onGrade).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(onGrade).toHaveBeenCalledTimes(1)
    expect(onGrade).toHaveBeenCalledWith(4, 'kitchen')
  })

  it('a right pick has no use for confirm()', () => {
    const { choice, onGrade } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(0)
    choice.confirm()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)

    expect(onGrade).toHaveBeenCalledTimes(1)
    expect(onGrade).toHaveBeenCalledWith(4, 'kitchen')
  })

  it('a wrong pick waits for confirm(): the right option is revealed and no timer moves on', () => {
    const { choice, onGrade } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(2)

    expect(choice.isCorrect.value).toBe(false)
    expect(choice.stateOf(2)).toBe('incorrect')
    expect(choice.stateOf(0)).toBe('reveal')
    expect(choice.stateOf(1)).toBe('idle')
    expect(choice.awaitingConfirm.value).toBe(true)

    // However long the learner reads, nothing is sent by itself.
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 20)
    expect(onGrade).not.toHaveBeenCalled()
    expect(choice.awaitingConfirm.value).toBe(true)
  })

  it('confirm() sends 2 with the picked label, and only once', () => {
    const { choice, onGrade } = mountChoice(OPTIONS, 'kitchen')
    choice.pick(2)

    choice.confirm()
    choice.confirm()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 5)

    expect(onGrade).toHaveBeenCalledTimes(1)
    expect(onGrade).toHaveBeenCalledWith(2, 'bedroom')
    expect(choice.awaitingConfirm.value).toBe(false)
  })

  it('confirm() before any pick does nothing', () => {
    const { choice, onGrade } = mountChoice(OPTIONS, 'kitchen')

    choice.confirm()

    expect(onGrade).not.toHaveBeenCalled()
    expect(choice.awaitingConfirm.value).toBe(false)
  })

  it('accepts one pick per card: a second pick changes nothing', () => {
    const { choice, onGrade } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(2)
    choice.pick(0)
    expect(choice.picked.value).toBe(2)
    expect(choice.stateOf(0)).toBe('reveal')

    choice.confirm()
    choice.pick(0)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 5)
    expect(choice.picked.value).toBe(2)
    expect(onGrade).toHaveBeenCalledTimes(1)
    expect(onGrade).toHaveBeenCalledWith(2, 'bedroom')
  })

  it('ignores an index with no option', () => {
    const { choice, onGrade } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(9)

    expect(choice.picked.value).toBeNull()
    expect(choice.awaitingConfirm.value).toBe(false)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)
    expect(onGrade).not.toHaveBeenCalled()
  })

  it('drops the pending grade when the card is left before the pause is over', () => {
    const { choice, onGrade, wrapper } = mountChoice(OPTIONS, 'kitchen')
    choice.pick(0)

    wrapper.unmount()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)

    expect(onGrade).not.toHaveBeenCalled()
  })

  it('shows every option as idle before the pick', () => {
    const { choice } = mountChoice(OPTIONS, 'kitchen')

    expect(choice.isCorrect.value).toBeNull()
    expect(OPTIONS.map((_, i) => choice.stateOf(i))).toEqual(['idle', 'idle', 'idle', 'idle'])
  })
})

describe('sameText', () => {
  it('compares ignoring case and surrounding or repeated whitespace', () => {
    expect(sameText('  Ice   Cream ', 'ice cream')).toBe(true)
    expect(sameText('walk', 'walked')).toBe(false)
  })
})
