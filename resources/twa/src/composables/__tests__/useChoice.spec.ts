import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { CHOICE_FEEDBACK_MS, sameText, useChoice } from '../useChoice'

/** `useChoice` hooks into the component lifecycle (the timer is dropped on unmount), so it runs inside a component. */
function mountChoice(options: string[], correct: string) {
  const handlers = { grade: vi.fn(), advance: vi.fn() }
  let choice!: ReturnType<typeof useChoice>
  const wrapper = mount(
    defineComponent({
      setup() {
        choice = useChoice(ref(options), ref(correct), handlers)
        return () => h('div')
      },
    }),
  )
  return { choice, handlers, wrapper }
}

const OPTIONS = ['kitchen', 'chicken', 'bedroom', 'garden']

describe('useChoice', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('grades a right pick with 4 at once and moves on after the feedback pause, with nothing to confirm', () => {
    const { choice, handlers } = mountChoice(OPTIONS, 'Kitchen')

    choice.pick(0)

    expect(choice.picked.value).toBe(0)
    expect(choice.isCorrect.value).toBe(true)
    expect(choice.stateOf(0)).toBe('correct')
    expect(choice.stateOf(1)).toBe('idle')
    expect(choice.awaitingConfirm.value).toBe(false)
    // The grade is final at the pick: it does not wait for the pause.
    expect(handlers.grade).toHaveBeenCalledTimes(1)
    expect(handlers.grade).toHaveBeenCalledWith(4, 'kitchen')
    expect(handlers.advance).not.toHaveBeenCalled()

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS - 1)
    expect(handlers.advance).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(handlers.advance).toHaveBeenCalledTimes(1)
    expect(handlers.grade).toHaveBeenCalledTimes(1)
  })

  it('a right pick has no use for confirm()', () => {
    const { choice, handlers } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(0)
    choice.confirm()

    expect(handlers.advance).not.toHaveBeenCalled()
    expect(choice.confirmed.value).toBe(false)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)

    expect(handlers.grade).toHaveBeenCalledTimes(1)
    expect(handlers.advance).toHaveBeenCalledTimes(1)
  })

  it('a wrong pick grades 2 at once, then waits for confirm(): the right option is revealed and no timer moves on', () => {
    const { choice, handlers } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(2)

    expect(choice.isCorrect.value).toBe(false)
    expect(choice.stateOf(2)).toBe('incorrect')
    expect(choice.stateOf(0)).toBe('reveal')
    expect(choice.stateOf(1)).toBe('idle')
    expect(choice.awaitingConfirm.value).toBe(true)
    expect(handlers.grade).toHaveBeenCalledTimes(1)
    expect(handlers.grade).toHaveBeenCalledWith(2, 'bedroom')

    // However long the learner reads, the next card does not come by itself.
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 20)
    expect(handlers.advance).not.toHaveBeenCalled()
    expect(choice.awaitingConfirm.value).toBe(true)
    expect(choice.confirmed.value).toBe(false)
  })

  it('confirm() moves on, once, and sends nothing: the grade has gone already', () => {
    const { choice, handlers } = mountChoice(OPTIONS, 'kitchen')
    choice.pick(2)

    choice.confirm()
    choice.confirm()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 5)

    expect(handlers.advance).toHaveBeenCalledTimes(1)
    expect(handlers.grade).toHaveBeenCalledTimes(1)
    expect(handlers.grade).toHaveBeenCalledWith(2, 'bedroom')
    expect(choice.confirmed.value).toBe(true)
  })

  it('confirm() before any pick does nothing', () => {
    const { choice, handlers } = mountChoice(OPTIONS, 'kitchen')

    choice.confirm()

    expect(handlers.grade).not.toHaveBeenCalled()
    expect(handlers.advance).not.toHaveBeenCalled()
    expect(choice.awaitingConfirm.value).toBe(false)
    expect(choice.confirmed.value).toBe(false)
  })

  it('accepts one pick per card: a second pick changes nothing', () => {
    const { choice, handlers } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(2)
    choice.pick(0)
    expect(choice.picked.value).toBe(2)
    expect(choice.stateOf(0)).toBe('reveal')

    choice.confirm()
    choice.pick(0)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 5)
    expect(choice.picked.value).toBe(2)
    expect(handlers.grade).toHaveBeenCalledTimes(1)
    expect(handlers.grade).toHaveBeenCalledWith(2, 'bedroom')
    expect(handlers.advance).toHaveBeenCalledTimes(1)
  })

  it('a pick tapped twice in a row is graded once, right or wrong', () => {
    const right = mountChoice(OPTIONS, 'kitchen')
    right.choice.pick(0)
    right.choice.pick(0)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)
    expect(right.handlers.grade).toHaveBeenCalledTimes(1)
    expect(right.handlers.advance).toHaveBeenCalledTimes(1)

    const wrong = mountChoice(OPTIONS, 'kitchen')
    wrong.choice.pick(1)
    wrong.choice.pick(1)
    expect(wrong.handlers.grade).toHaveBeenCalledTimes(1)
  })

  it('ignores an index with no option', () => {
    const { choice, handlers } = mountChoice(OPTIONS, 'kitchen')

    choice.pick(9)

    expect(choice.picked.value).toBeNull()
    expect(choice.awaitingConfirm.value).toBe(false)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)
    expect(handlers.grade).not.toHaveBeenCalled()
    expect(handlers.advance).not.toHaveBeenCalled()
  })

  it('leaving the card after a pick loses nothing: the grade went at the pick, only the move on is dropped', () => {
    const right = mountChoice(OPTIONS, 'kitchen')
    right.choice.pick(0)
    right.wrapper.unmount()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)
    expect(right.handlers.grade).toHaveBeenCalledTimes(1)
    expect(right.handlers.grade).toHaveBeenCalledWith(4, 'kitchen')
    expect(right.handlers.advance).not.toHaveBeenCalled()

    const wrong = mountChoice(OPTIONS, 'kitchen')
    wrong.choice.pick(3)
    wrong.wrapper.unmount()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)
    expect(wrong.handlers.grade).toHaveBeenCalledTimes(1)
    expect(wrong.handlers.grade).toHaveBeenCalledWith(2, 'garden')
    expect(wrong.handlers.advance).not.toHaveBeenCalled()
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
