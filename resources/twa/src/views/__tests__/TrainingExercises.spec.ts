import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import TrainingView from '../TrainingView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import { CHOICE_FEEDBACK_MS } from '@/composables/useChoice'
import type { Card, ExercisePrompt, TrainingStartResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), answer: vi.fn(), finish: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: vi.fn(),
    showBackButton: () => () => {},
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

  it('a reverse pick is sent as quality after the feedback pause', async () => {
    vi.useFakeTimers()
    vi.mocked(api.training.review).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 1,
      easiness_factor: 2.5,
      graded: null,
      card: card(null, { word_id: 8 }),
      progress: { done: 1, total: 3 },
    })
    const { wrapper } = await render(card(prompts.reverse))

    await wrapper.get('[data-testid=option-1]').trigger('click')
    expect(api.training.review).not.toHaveBeenCalled()
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS)
    await flushPromises()

    expect(api.training.review).toHaveBeenCalledWith(1, 7, 4, expect.any(Number), null)
    expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
  })

  it('a wrong reverse pick stays on screen until "Next", then sends quality 2 (N6)', async () => {
    vi.useFakeTimers()
    vi.mocked(api.training.review).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 0,
      easiness_factor: 2.5,
      graded: null,
      card: card(null, { word_id: 8 }),
      progress: { done: 1, total: 3 },
    })
    const { wrapper } = await render(card(prompts.reverse))

    await wrapper.get('[data-testid=option-0]').trigger('click')
    expect(wrapper.get('[data-testid=option-0]').attributes('data-state')).toBe('incorrect')
    expect(wrapper.get('[data-testid=option-1]').attributes('data-state')).toBe('reveal')

    // However long the learner reads the right answer, the card stays.
    vi.advanceTimersByTime(CHOICE_FEEDBACK_MS * 30)
    await flushPromises()
    expect(api.training.review).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid=reverse-card]').exists()).toBe(true)

    const next = wrapper.get('[data-testid=continue-btn]')
    expect(next.text()).toBe('Дальше')
    await next.trigger('click')
    await flushPromises()

    expect(api.training.review).toHaveBeenCalledTimes(1)
    expect(api.training.review).toHaveBeenCalledWith(1, 7, 2, expect.any(Number), null)
    expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
  })

  it('a wrong audio pick waits for "Next" as well', async () => {
    vi.mocked(api.training.review).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 0,
      easiness_factor: 2.5,
      graded: null,
      card: card(null, { word_id: 8 }),
      progress: { done: 1, total: 3 },
    })
    const { wrapper } = await render(card(prompts.audio))

    await wrapper.get('[data-testid=option-0]').trigger('click')
    await flushPromises()
    expect(api.training.review).not.toHaveBeenCalled()

    await wrapper.get('[data-testid=continue-btn]').trigger('click')
    await flushPromises()
    expect(api.training.review).toHaveBeenCalledWith(1, 7, 2, expect.any(Number), null)
  })

  it('"Next" after a wrong pick is pressed once: the grade is sent once', async () => {
    vi.mocked(api.training.review).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 0,
      easiness_factor: 2.5,
      graded: null,
      card: card(null, { word_id: 8 }),
      progress: { done: 1, total: 3 },
    })
    const { wrapper } = await render(card(prompts.reverse))
    await wrapper.get('[data-testid=option-3]').trigger('click')

    const next = wrapper.get('[data-testid=continue-btn]')
    await next.trigger('click')
    await next.trigger('click')
    await flushPromises()

    expect(api.training.review).toHaveBeenCalledTimes(1)
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
