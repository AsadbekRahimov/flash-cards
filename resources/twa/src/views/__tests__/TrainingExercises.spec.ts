import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import TrainingView from '../TrainingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import { CHOICE_FEEDBACK_MS } from '@/composables/useChoice'
import type { Card, ExercisePrompt, ReviewResponse, TrainingStartResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), answer: vi.fn(), finish: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

// `backHandler` is what the system "Back" button of Telegram would call (useBackButton registers it).
const state = vi.hoisted(() => ({ close: vi.fn(), backHandler: null as (() => void) | null }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: state.close,
    showBackButton: (handler: () => void) => {
      state.backHandler = handler
      return () => {
        state.backHandler = null
      }
    },
    haptic: () => {},
    selectionHaptic: () => {},
    notificationHaptic: () => {},
  }),
}))

const { speak } = vi.hoisted(() => ({ speak: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTts', () => ({ useTts: () => ({ supported: false, speak }) }))

const card = (prompt: ExercisePrompt | null, overrides: Partial<Card> = {}): Card => ({
  word_id: 7,
  word: 'walk',
  translation: 'идти',
  translation_fallback: false,
  example: 'They walked home.',
  transcription: null,
  audio_url: null,
  card_kind: 'due',
  serve_token: null,
  prompt,
  ...overrides,
})

const prompts = {
  reverse: { type: 'reverse', front: 'идти', masked_example: null, options: ['run', 'walk', 'sit', 'eat'], answer_length: null },
  audio: { type: 'audio', front: '', masked_example: null, options: ['бежать', 'идти', 'сидеть', 'есть'], answer_length: null },
  spelling: { type: 'spelling', front: 'идти', masked_example: null, options: null, answer_length: 4 },
  gap_fill: { type: 'gap_fill', front: 'идти', masked_example: 'They ____ home.', options: null, answer_length: 6 },
} satisfies Record<string, ExercisePrompt>

const started = (first: Card): TrainingStartResponse => ({
  session_id: 1,
  mode: 'lesson',
  lesson: { stage: 1, lesson: 2, title: null },
  total_words: 3,
  card: first,
  progress: { done: 0, total: 3 },
  done_reason: null,
})

async function render(first: Card) {
  vi.mocked(api.training.start).mockResolvedValue(started(first))
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
      { path: '/finished', name: 'finished', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'training', params: { sessionId: 1 } })
  const wrapper = mount(TrainingView, { props: { sessionId: 1 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

describe('TrainingView — exercises', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.backHandler = null
  })
  afterEach(() => {
    setLocale('ru')
    vi.useRealTimers()
  })

  it('renders the screen of prompt.type and a flash card without a prompt', async () => {
    expect((await render(card(null))).wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
    expect((await render(card(prompts.reverse))).wrapper.find('[data-testid=reverse-card]').exists()).toBe(true)
    expect((await render(card(prompts.audio))).wrapper.find('[data-testid=audio-card]').exists()).toBe(true)
    expect((await render(card(prompts.spelling))).wrapper.find('[data-testid=spelling-card]').exists()).toBe(true)
    expect((await render(card(prompts.gap_fill))).wrapper.find('[data-testid=gap-fill-card]').exists()).toBe(true)
  })

  describe('four-choice exercises: the grade goes at the pick, the next card is held back', () => {
    const reviewAnswer = (overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
      next_review_at: null,
      new_interval_days: 1,
      easiness_factor: 2.5,
      graded: null,
      card: card(null, { word_id: 8 }),
      progress: { done: 1, total: 3 },
      ...overrides,
    })

    /** A review whose answer the test sends by hand: it can come before or after the learner moves on. */
    function pendingReview() {
      let resolve: (value: ReviewResponse) => void = () => {}
      let reject: (reason: unknown) => void = () => {}
      vi.mocked(api.training.review).mockReturnValueOnce(
        new Promise<ReviewResponse>((res, rej) => {
          resolve = res
          reject = rej
        }),
      )
      return { resolve, reject }
    }

    const offline = () => new ApiClientError('network', 0, 'unknown', 'fetch failed')

    it('a right reverse pick is sent at once; the next card follows after the feedback pause', async () => {
      vi.useFakeTimers()
      vi.mocked(api.training.review).mockResolvedValue(reviewAnswer())
      const { wrapper } = await render(card(prompts.reverse))

      await wrapper.get('[data-testid=option-1]').trigger('click')
      // The grade is final at the pick: it goes now, not after the pause.
      expect(api.training.review).toHaveBeenCalledTimes(1)
      expect(api.training.review).toHaveBeenCalledWith(1, 7, 4, expect.any(Number), null)

      // The answer is back, but the learner still sees the coloured pick: the next card waits for the pause.
      await flushPromises()
      expect(useTrainingStore().status).toBe('held')
      expect(wrapper.get('[data-testid=option-1]').attributes('data-state')).toBe('correct')
      vi.advanceTimersByTime(CHOICE_FEEDBACK_MS - 1)
      await flushPromises()
      expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(false)

      vi.advanceTimersByTime(1)
      await flushPromises()
      expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
      expect(api.training.review).toHaveBeenCalledTimes(1)
    })

    it('a right pick whose answer comes after the pause shows the next card the moment it arrives', async () => {
      vi.useFakeTimers()
      const answer = pendingReview()
      const { wrapper } = await render(card(prompts.reverse))

      await wrapper.get('[data-testid=option-1]').trigger('click')
      vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 3)
      await flushPromises()
      expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(false)

      answer.resolve(reviewAnswer())
      await flushPromises()
      expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
      expect(api.training.review).toHaveBeenCalledTimes(1)
    })

    it('a wrong reverse pick is sent at once and stays on screen until "Next", which sends nothing more (N6)', async () => {
      vi.useFakeTimers()
      vi.mocked(api.training.review).mockResolvedValue(reviewAnswer({ new_interval_days: 0 }))
      const { wrapper } = await render(card(prompts.reverse))

      await wrapper.get('[data-testid=option-0]').trigger('click')
      expect(api.training.review).toHaveBeenCalledTimes(1)
      expect(api.training.review).toHaveBeenCalledWith(1, 7, 2, expect.any(Number), null)
      await flushPromises()
      expect(wrapper.get('[data-testid=option-0]').attributes('data-state')).toBe('incorrect')
      expect(wrapper.get('[data-testid=option-1]').attributes('data-state')).toBe('reveal')

      // However long the learner reads the right answer, the card stays (the answer from the server is held back).
      vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
      await flushPromises()
      expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=answer-verdict]').exists()).toBe(false)

      const next = wrapper.get('[data-testid=continue-btn]')
      expect(next.text()).toBe('Дальше')
      expect(next.attributes('disabled')).toBeUndefined()
      await next.trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
      expect(api.training.review).toHaveBeenCalledTimes(1)
    })

    it('a wrong audio pick is sent at once and waits for "Next" as well', async () => {
      vi.mocked(api.training.review).mockResolvedValue(reviewAnswer({ new_interval_days: 0 }))
      const { wrapper } = await render(card(prompts.audio))

      await wrapper.get('[data-testid=option-0]').trigger('click')
      await flushPromises()
      expect(api.training.review).toHaveBeenCalledTimes(1)
      expect(api.training.review).toHaveBeenCalledWith(1, 7, 2, expect.any(Number), null)
      expect(wrapper.find('[data-testid=audio-card]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=word]').text()).toBe('walk')

      await wrapper.get('[data-testid=continue-btn]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
      expect(api.training.review).toHaveBeenCalledTimes(1)
    })

    it('an option tapped twice, or another one after it, sends one grade', async () => {
      vi.mocked(api.training.review).mockResolvedValue(reviewAnswer({ new_interval_days: 0 }))
      const { wrapper } = await render(card(prompts.reverse))

      const first = wrapper.get('[data-testid=option-0]')
      await first.trigger('click')
      await first.trigger('click')
      await wrapper.get('[data-testid=option-1]').trigger('click')
      await flushPromises()
      await wrapper.get('[data-testid=option-1]').trigger('click')

      expect(api.training.review).toHaveBeenCalledTimes(1)
      expect(api.training.review).toHaveBeenCalledWith(1, 7, 2, expect.any(Number), null)
    })

    it('"Next" pressed twice moves on once and sends nothing', async () => {
      vi.mocked(api.training.review).mockResolvedValue(reviewAnswer({ new_interval_days: 0 }))
      const { wrapper } = await render(card(prompts.reverse))
      await wrapper.get('[data-testid=option-3]').trigger('click')
      await flushPromises()

      const next = wrapper.get('[data-testid=continue-btn]')
      await next.trigger('click')
      await next.trigger('click')
      await flushPromises()

      expect(api.training.review).toHaveBeenCalledTimes(1)
      expect(useTrainingStore().card?.word_id).toBe(8)
    })

    it('"Next" pressed before the answer is back: the button waits, the card follows the answer, one request in all', async () => {
      const answer = pendingReview()
      const { wrapper } = await render(card(prompts.reverse))
      await wrapper.get('[data-testid=option-0]').trigger('click')

      // The grade is on its way, but "Next" can be asked for.
      const next = wrapper.get('[data-testid=continue-btn]')
      expect(next.attributes('disabled')).toBeUndefined()
      await next.trigger('click')
      await flushPromises()

      const waiting = wrapper.get('[data-testid=continue-btn]')
      expect(waiting.attributes('disabled')).toBeDefined()
      expect(waiting.attributes('aria-busy')).toBe('true')
      expect(wrapper.find('[data-testid=continue-waiting]').exists()).toBe(true)
      expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(true)
      await waiting.trigger('click')

      answer.resolve(reviewAnswer({ new_interval_days: 0 }))
      await flushPromises()

      expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
      expect(api.training.review).toHaveBeenCalledTimes(1)
    })

    it('a grade that fails shows the usual error state; "Retry" asks the server for the card again', async () => {
      vi.mocked(api.training.review).mockRejectedValue(offline())
      const { wrapper } = await render(card(prompts.reverse))

      await wrapper.get('[data-testid=option-0]').trigger('click')
      await flushPromises()
      expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
      expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(false)

      vi.mocked(api.training.next).mockResolvedValue({
        card: card(prompts.reverse, { serve_token: 'ticket-2' }),
        progress: { done: 0, total: 3 },
      })
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      // A fresh exercise: every option is open again, and nothing is waiting for "Next".
      for (const i of [0, 1, 2, 3]) {
        expect(wrapper.get(`[data-testid=option-${i}]`).attributes('data-state')).toBe('idle')
      }
      expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)
      expect(api.training.review).toHaveBeenCalledTimes(1)
    })

    describe('leaving after a pick has lost nothing: the grade is sent exactly once', () => {
      it.each([
        ['✕', async (wrapper: Awaited<ReturnType<typeof render>>['wrapper']) => {
          await wrapper.get('[data-testid=close-btn]').trigger('click')
        }],
        ['the system "Back"', async () => {
          state.backHandler?.()
        }],
        ['unmounting the screen', async (wrapper: Awaited<ReturnType<typeof render>>['wrapper']) => {
          wrapper.unmount()
        }],
      ])('after a wrong pick, by %s', async (_way, leave) => {
        vi.useFakeTimers()
        vi.mocked(api.training.review).mockResolvedValue(reviewAnswer({ new_interval_days: 0 }))
        const { wrapper, router } = await render(card(prompts.reverse))

        await wrapper.get('[data-testid=option-0]').trigger('click')
        expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(true)
        await leave(wrapper)
        await flushPromises()
        vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
        await flushPromises()

        expect(api.training.review).toHaveBeenCalledTimes(1)
        expect(api.training.review).toHaveBeenCalledWith(1, 7, 2, expect.any(Number), null)
        if (_way !== 'unmounting the screen') expect(router.currentRoute.value.name).toBe('today')
        expect(state.close).not.toHaveBeenCalled()
      })

      it.each([
        ['✕', async (wrapper: Awaited<ReturnType<typeof render>>['wrapper']) => {
          await wrapper.get('[data-testid=close-btn]').trigger('click')
        }],
        ['the system "Back"', async () => {
          state.backHandler?.()
        }],
        ['unmounting the screen', async (wrapper: Awaited<ReturnType<typeof render>>['wrapper']) => {
          wrapper.unmount()
        }],
      ])('before the pause of a right pick is over, by %s', async (_way, leave) => {
        vi.useFakeTimers()
        vi.mocked(api.training.review).mockResolvedValue(reviewAnswer())
        const { wrapper } = await render(card(prompts.audio))

        await wrapper.get('[data-testid=option-1]').trigger('click')
        await leave(wrapper)
        await flushPromises()
        vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
        await flushPromises()

        expect(api.training.review).toHaveBeenCalledTimes(1)
        expect(api.training.review).toHaveBeenCalledWith(1, 7, 4, expect.any(Number), null)
      })
    })

    it('the last card: a right pick leads to the finish screen after the pause, a wrong one after "Next"', async () => {
      vi.useFakeTimers()
      vi.mocked(api.training.review).mockResolvedValue(
        reviewAnswer({ card: null, done_reason: 'lesson_complete', progress: { done: 3, total: 3 }, new_interval_days: 6 }),
      )
      vi.mocked(api.training.finish).mockResolvedValue({ reviewed: 3, correct: 2, new_words: 0 })

      const right = await render(card(prompts.reverse))
      await right.wrapper.get('[data-testid=option-1]').trigger('click')
      await flushPromises()
      expect(right.router.currentRoute.value.name).toBe('training')
      vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
      await flushPromises()
      expect(right.router.currentRoute.value.name).toBe('finished')
      expect(right.router.currentRoute.value.query).toMatchObject({ reviewed: '3', next: '6', reason: 'lesson_complete' })
      expect(api.training.finish).toHaveBeenCalledTimes(1)
      expect(api.training.review).toHaveBeenCalledTimes(1)
      // The finish screen replaces the training screen (this test has no RouterView to do it).
      right.wrapper.unmount()

      vi.clearAllMocks()
      vi.mocked(api.training.review).mockResolvedValue(
        reviewAnswer({ card: null, done_reason: 'lesson_complete', progress: { done: 3, total: 3 }, new_interval_days: 0 }),
      )
      vi.mocked(api.training.finish).mockResolvedValue({ reviewed: 3, correct: 2, new_words: 0 })
      const wrong = await render(card(prompts.reverse))
      await wrong.wrapper.get('[data-testid=option-0]').trigger('click')
      await flushPromises()
      vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
      await flushPromises()
      // The session is over on the server, but the learner is still reading the right answer.
      expect(wrong.router.currentRoute.value.name).toBe('training')
      expect(api.training.finish).not.toHaveBeenCalled()

      await wrong.wrapper.get('[data-testid=continue-btn]').trigger('click')
      await flushPromises()
      expect(wrong.router.currentRoute.value.name).toBe('finished')
      expect(api.training.finish).toHaveBeenCalledTimes(1)
      expect(api.training.review).toHaveBeenCalledTimes(1)
    })
  })

  it('the same word served again at once starts a fresh exercise, not the answered one', async () => {
    // A failed learning card is the only one left: the server serves it ahead, with a new ticket.
    vi.mocked(api.training.review).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 0,
      easiness_factor: 2.5,
      graded: null,
      card: card(prompts.reverse, { serve_token: 'ticket-2', card_kind: 'learning' }),
      progress: { done: 1, total: 3 },
    })
    const { wrapper } = await render(card(prompts.reverse))
    await wrapper.get('[data-testid=option-2]').trigger('click')
    expect(wrapper.get('[data-testid=option-2]').attributes('data-state')).toBe('incorrect')
    // The first serve had no ticket.
    expect(api.training.review).toHaveBeenLastCalledWith(1, 7, 2, expect.any(Number), null)

    await wrapper.get('[data-testid=continue-btn]').trigger('click')
    await flushPromises()

    // Same word_id, new serve: every option is open for a new pick again, and there is nothing left to confirm.
    expect(useTrainingStore().card?.word_id).toBe(7)
    for (const i of [0, 1, 2, 3]) {
      expect(wrapper.get(`[data-testid=option-${i}]`).attributes('data-state')).toBe('idle')
      expect(wrapper.get(`[data-testid=option-${i}]`).attributes('disabled')).toBeUndefined()
    }
    expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)

    vi.mocked(api.training.review).mockClear()
    await wrapper.get('[data-testid=option-1]').trigger('click')
    expect(wrapper.get('[data-testid=option-1]').attributes('data-state')).toBe('correct')
    // The grade of the second serve carries the ticket of the card the pick was made on.
    expect(api.training.review).toHaveBeenCalledTimes(1)
    expect(api.training.review).toHaveBeenCalledWith(1, 7, 4, expect.any(Number), 'ticket-2')
  })

  it('a typed answer shows the verdict, then "Next" reveals the card the review answered with', async () => {
    vi.mocked(api.training.answer).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 1,
      easiness_factor: 2.5,
      graded: { quality: 1, correct: false, expected: 'walked' },
      card: card(prompts.reverse, { word_id: 8 }),
      progress: { done: 1, total: 3 },
    })
    const { wrapper } = await render(card(prompts.gap_fill))

    await wrapper.get('[data-testid=answer-input]').setValue('walks')
    await wrapper.get('[data-testid=answer-form]').trigger('submit')
    await flushPromises()

    expect(api.training.answer).toHaveBeenCalledWith(1, 7, 'walks', expect.any(Number), null)
    const verdict = wrapper.get('[data-testid=answer-verdict]')
    expect(verdict.attributes('data-kind')).toBe('wrong')
    expect(verdict.get('[data-testid=verdict-text]').text()).toBe('Неверно')
    expect(verdict.get('[data-testid=expected]').text()).toBe('walked')
    expect(verdict.get('[data-testid=own-answer]').text()).toBe('walks')
    expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(false)

    await wrapper.get('[data-testid=continue-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=answer-verdict]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(true)
    expect(useTrainingStore().card?.word_id).toBe(8)
  })

  it('goes to the finish screen after the verdict of the last card', async () => {
    vi.mocked(api.training.answer).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 6,
      easiness_factor: 2.5,
      graded: { quality: 5, correct: true, expected: 'walk' },
      card: null,
      done_reason: 'lesson_complete',
      progress: { done: 3, total: 3 },
    })
    vi.mocked(api.training.finish).mockResolvedValue({ reviewed: 3, correct: 3, new_words: 0 })
    const { wrapper, router } = await render(card(prompts.spelling))

    await wrapper.get('[data-testid=answer-input]').setValue('walk')
    await wrapper.get('[data-testid=check-btn]').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-testid=verdict-text]').text()).toBe('Верно!')

    await wrapper.get('[data-testid=continue-btn]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('finished')
    expect(router.currentRoute.value.query).toMatchObject({ reviewed: '3', next: '6', reason: 'lesson_complete' })
  })
})
