import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import type { ChoiceResult } from '@/api/types'
import { CHOICE_FEEDBACK_MS, sameText, useChoice } from '../useChoice'

/** `useChoice` hooks into the component lifecycle (the timer is dropped on unmount), so it runs inside a component. */
function mountChoice(options: string[]) {
  const handlers = { pick: vi.fn(), advance: vi.fn() }
  const result = ref<ChoiceResult | null>(null)
  let choice!: ReturnType<typeof useChoice>
  const wrapper = mount(
    defineComponent({
      setup() {
        choice = useChoice(ref(options), result, handlers)
        return () => h('div')
      },
    }),
  )
  /** The server's word on the pick: the right option is `kitchen`. */
  const answer = async (correct: boolean) => {
    result.value = { correct, right: 'Kitchen', revealed: null }
    await nextTick()
  }
  return { choice, handlers, wrapper, answer }
}

const OPTIONS = ['kitchen', 'chicken', 'bedroom', 'garden']

describe('useChoice', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('hands the pick over at once and colours only the pick until the server answers: nothing is known client-side', () => {
    const { choice, handlers } = mountChoice(OPTIONS)

    choice.pick(0)

    expect(choice.picked.value).toBe(0)
    expect(handlers.pick).toHaveBeenCalledTimes(1)
    expect(handlers.pick).toHaveBeenCalledWith('kitchen')
    expect(choice.isCorrect.value).toBeNull()
    expect(OPTIONS.map((_, i) => choice.stateOf(i))).toEqual(['selected', 'idle', 'idle', 'idle'])
    expect(choice.awaitingConfirm.value).toBe(false)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 10)
    expect(handlers.advance).not.toHaveBeenCalled()
  })

  it('a right verdict colours the pick and moves on after the feedback pause, with nothing to confirm', async () => {
    const { choice, handlers, answer } = mountChoice(OPTIONS)
    choice.pick(0)
    await answer(true)

    expect(choice.isCorrect.value).toBe(true)
    expect(choice.stateOf(0)).toBe('correct')
    expect(choice.stateOf(1)).toBe('idle')
    expect(choice.awaitingConfirm.value).toBe(false)

    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS - 1)
    expect(handlers.advance).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(handlers.advance).toHaveBeenCalledTimes(1)
    expect(handlers.pick).toHaveBeenCalledTimes(1)
  })

  it('a right pick has no use for confirm()', async () => {
    const { choice, handlers, answer } = mountChoice(OPTIONS)
    choice.pick(0)
    await answer(true)
    choice.confirm()

    expect(handlers.advance).not.toHaveBeenCalled()
    expect(choice.confirmed.value).toBe(false)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    expect(handlers.advance).toHaveBeenCalledTimes(1)
  })

  it('a wrong verdict reveals the right option (found by the text the server sent) and waits for confirm()', async () => {
    const { choice, handlers, answer } = mountChoice(OPTIONS)
    choice.pick(2)
    await answer(false)

    expect(choice.isCorrect.value).toBe(false)
    expect(choice.stateOf(2)).toBe('incorrect')
    expect(choice.stateOf(0)).toBe('reveal')
    expect(choice.stateOf(1)).toBe('idle')
    expect(choice.awaitingConfirm.value).toBe(true)

    // However long the learner reads, the next card does not come by itself.
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 20)
    expect(handlers.advance).not.toHaveBeenCalled()
    expect(choice.confirmed.value).toBe(false)
  })

  it('confirm() moves on, once, and sends nothing: the pick has gone already', async () => {
    const { choice, handlers, answer } = mountChoice(OPTIONS)
    choice.pick(2)
    await answer(false)

    choice.confirm()
    choice.confirm()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 5)

    expect(handlers.advance).toHaveBeenCalledTimes(1)
    expect(handlers.pick).toHaveBeenCalledTimes(1)
    expect(choice.confirmed.value).toBe(true)
  })

  it('confirm() before the verdict does nothing', () => {
    const { choice, handlers } = mountChoice(OPTIONS)

    choice.confirm()
    choice.pick(2)
    choice.confirm()

    expect(handlers.advance).not.toHaveBeenCalled()
    expect(choice.awaitingConfirm.value).toBe(false)
    expect(choice.confirmed.value).toBe(false)
  })

  it('accepts one pick per card: a second pick changes nothing', async () => {
    const { choice, handlers, answer } = mountChoice(OPTIONS)

    choice.pick(2)
    choice.pick(0)
    await answer(false)
    choice.confirm()
    choice.pick(0)
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 5)

    expect(choice.picked.value).toBe(2)
    expect(handlers.pick).toHaveBeenCalledTimes(1)
    expect(handlers.pick).toHaveBeenCalledWith('bedroom')
    expect(handlers.advance).toHaveBeenCalledTimes(1)
  })

  it('ignores an index with no option', () => {
    const { choice, handlers } = mountChoice(OPTIONS)

    choice.pick(9)

    expect(choice.picked.value).toBeNull()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)
    expect(handlers.pick).not.toHaveBeenCalled()
    expect(handlers.advance).not.toHaveBeenCalled()
  })

  it('leaving the card after a pick loses nothing: the pick went at once, only the move on is dropped', async () => {
    const { choice, handlers, wrapper, answer } = mountChoice(OPTIONS)
    choice.pick(0)
    await answer(true)
    wrapper.unmount()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 2)

    expect(handlers.pick).toHaveBeenCalledTimes(1)
    expect(handlers.pick).toHaveBeenCalledWith('kitchen')
    expect(handlers.advance).not.toHaveBeenCalled()
  })

  it('shows every option as idle before the pick', () => {
    const { choice } = mountChoice(OPTIONS)

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
