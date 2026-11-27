import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import TrainingView from '../TrainingView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import type { Card, ReviewResponse } from '@/api/types'

// An answer that waited for the network is kept in `sessionStorage` (docs/40 §2.6): every test starts without one.
beforeEach(() => window.sessionStorage.clear())

vi.mock('@/api', () => ({
  api: { training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), answer: vi.fn(), finish: vi.fn() } },
}))

const telegram = vi.hoisted(() => ({
  close: vi.fn(),
  showBackButton: () => () => {},
  haptic: vi.fn(),
  selectionHaptic: vi.fn(),
  notificationHaptic: vi.fn(),
}))
const sound = vi.hoisted(() => ({ playSound: vi.fn() }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => telegram }))
vi.mock('@/composables/useFeedbackSound', () => sound)

const flash = (id: number, kind: Card['card_kind'] = 'due'): Card => ({
  word_id: id,
  word: `word${id}`,
  translation: `слово${id}`,
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: kind,
  serve_token: null,
})

const meaning = (id: number): Card => ({
  ...flash(id),
  prompt: { type: 'meaning', front: `word${id}`, masked_example: null, options: ['a', 'b', 'c', 'd'], answer_length: null },
})

const reply = (next: Card | null, overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 1,
  easiness_factor: 2.5,
  graded: null,
  card: next,
  progress: { done: 1, total: 30 },
  ...overrides,
})

async function render(first: Card, stubTransition = true) {
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1, mode: 'lesson', lesson: { stage: 1, lesson: 1, title: null }, total_words: 30, card: first, progress: { done: 0, total: 30 },
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
      { path: '/finished', name: 'finished', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'training', params: { sessionId: 1 } })
  const wrapper = mount(TrainingView, {
    props: { sessionId: 1 },
    global: { plugins: [router], stubs: stubTransition ? {} : { transition: false } },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

/** Reveals the card on screen and presses a grade; the review answers with the next card. */
async function gradeFlash(w: Awaited<ReturnType<typeof render>>, testid: string, next: Card) {
  vi.mocked(api.training.review).mockResolvedValue(reply(next))
  if (w.find('[data-testid=reveal-btn]').exists()) await w.get('[data-testid=reveal-btn]').trigger('click')
  await w.get(`[data-testid=${testid}]`).trigger('click')
  await flushPromises()
}

describe('the lesson comes alive (docs/14 §6.2)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })
  afterEach(() => {
    setLocale('ru')
    vi.unstubAllGlobals()
  })

  describe('the run of right answers', () => {
    it('shows no chip before the third right answer in a row', async () => {
      const w = await render(flash(1))

      expect(w.find('[data-testid=combo]').exists()).toBe(false)
      await gradeFlash(w, 'grade-medium', flash(2))
      await gradeFlash(w, 'grade-easy', flash(3))

      expect(w.find('[data-testid=combo]').exists()).toBe(false)
    })

    it('shows "3 подряд" from the third (a number and a word, no emoji), grows, and goes quietly on "Not yet"', async () => {
      const w = await render(flash(1))
      await gradeFlash(w, 'grade-medium', flash(2))
      await gradeFlash(w, 'grade-easy', flash(3))
      await gradeFlash(w, 'grade-medium', flash(4))

      expect(w.get('[data-testid=combo]').text()).toBe('3 подряд')
      expect(w.get('[data-testid=combo] .font-mono').text()).toBe('3')
      expect(w.get('[data-testid=combo]').attributes('aria-label')).toBe('Верных ответов подряд: 3')
      expect(w.get('[data-testid=combo]').classes()).not.toContain('combo-pop')

      await gradeFlash(w, 'grade-medium', flash(5))
      expect(w.get('[data-testid=combo]').text()).toBe('4 подряд')

      await gradeFlash(w, 'grade-hard', flash(6))
      expect(w.find('[data-testid=combo]').exists()).toBe(false)
      // No punishment: no error haptic, no sound of a miss.
      expect(telegram.notificationHaptic).not.toHaveBeenCalledWith('error')
      expect(sound.playSound).not.toHaveBeenCalledWith('wrong')
    })

    it('leaves the progress counter and the bar as they were', async () => {
      const w = await render(flash(1))

      expect(w.get('[data-testid=progress]').text()).toBe('0 из 30')
      expect(w.get('[data-testid=progress-bar] .progress-fill').attributes('style')).toContain('width: 0%')
    })

    it('jumps once and gives the success haptic on every fifth answer, and not in between', async () => {
      const w = await render(flash(1))
      const store = useTrainingStore()
      store.combo = 3
      for (const [n, milestone] of [[4, false], [5, true], [6, false], [7, false], [8, false], [9, false], [10, true]] as const) {
        telegram.notificationHaptic.mockClear()
        await gradeFlash(w, 'grade-medium', flash(100 + n))

        expect(store.combo).toBe(n)
        const chip = w.get('[data-testid=combo]')
        expect(chip.classes().includes('combo-pop'), `combo ${n}`).toBe(milestone)
        expect(telegram.notificationHaptic.mock.calls.some(([k]) => k === 'success'), `haptic ${n}`).toBe(milestone)
      }
    })

    it('does not repeat the haptic that a four-choice card gives with its own verdict', async () => {
      const w = await render(meaning(1))
      const store = useTrainingStore()
      store.combo = 4
      vi.mocked(api.training.answer).mockResolvedValue(reply(meaning(2), { graded: { quality: 5, correct: true, expected: 'a' } }))

      await w.get('[data-testid=option-0]').trigger('click')
      await flushPromises()

      expect(store.combo).toBe(5)
      // One buzz, the card's own (the same mock serves MeaningCard): this screen adds nothing on top.
      expect(telegram.notificationHaptic).toHaveBeenCalledTimes(1)
    })

    it('hands the best run of the session to the finish screen', async () => {
      const w = await render(flash(1))
      for (let i = 2; i <= 6; i++) await gradeFlash(w, 'grade-medium', flash(i))
      await gradeFlash(w, 'grade-hard', flash(7))
      await gradeFlash(w, 'grade-medium', flash(8))

      const store = useTrainingStore()
      expect(store.combo).toBe(1)
      expect(store.bestCombo).toBe(5)
    })
  })

  describe('sounds', () => {
    it('play "right" for a right answer and "wrong" for a wrong one, once each', async () => {
      const w = await render(meaning(1))
      vi.mocked(api.training.answer).mockResolvedValue(reply(meaning(2), { graded: { quality: 5, correct: true, expected: 'a' } }))
      await w.get('[data-testid=option-0]').trigger('click')
      await flushPromises()
      expect(sound.playSound.mock.calls).toEqual([['right']])

      useTrainingStore().continueAfterChoice()
      await flushPromises()
      vi.mocked(api.training.answer).mockResolvedValue(reply(meaning(3), { graded: { quality: 1, correct: false, expected: 'b' } }))
      await w.get('[data-testid=option-0]').trigger('click')
      await flushPromises()

      expect(sound.playSound.mock.calls).toEqual([['right'], ['wrong']])
    })

    it('are quiet for "Not yet" (an honest grade) and play "right" for "Got it"', async () => {
      const w = await render(flash(1))
      await gradeFlash(w, 'grade-hard', flash(2))
      expect(sound.playSound).not.toHaveBeenCalled()

      await gradeFlash(w, 'grade-medium', flash(3))
      expect(sound.playSound.mock.calls).toEqual([['right']])
    })
  })

  describe('the swap of the cards', () => {
    it('shows the next card at once, without waiting for the old one to leave', async () => {
      const w = await render(flash(1), false)
      await w.get('[data-testid=reveal-btn]').trigger('click')
      vi.mocked(api.training.review).mockResolvedValue(reply(flash(2)))

      await w.get('[data-testid=grade-medium]').trigger('click')
      await flushPromises()

      // No timers advanced: the new card is already in the DOM, the old one is on its way out under the same transition.
      const words = w.findAll('[data-testid=word]').map((n) => n.text())
      expect(words).toContain('word2')
      const stage = w.get('.card-stage')
      expect(stage.findAll('[data-testid=flashcard]').length).toBeGreaterThanOrEqual(1)
    })

    it('keeps the old card on screen while the next one is on its way (a slow network does not blank the screen)', async () => {
      const w = await render(flash(1), false)
      await w.get('[data-testid=reveal-btn]').trigger('click')
      let arrive: (value: ReviewResponse) => void = () => {}
      vi.mocked(api.training.review).mockReturnValue(new Promise((resolve) => { arrive = resolve }))

      await w.get('[data-testid=grade-medium]').trigger('click')
      await flushPromises()

      expect(w.get('[data-testid=word]').text()).toBe('word1')
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      arrive(reply(flash(2)))
      await flushPromises()
      expect(w.findAll('[data-testid=word]').map((n) => n.text())).toContain('word2')
    })

    it('is one transition for every kind of card', async () => {
      const w = await render(meaning(1), false)
      vi.mocked(api.training.answer).mockResolvedValue(reply(flash(2), { graded: { quality: 5, correct: true, expected: 'a' } }))
      await w.get('[data-testid=option-0]').trigger('click')
      await flushPromises()
      useTrainingStore().continueAfterChoice()
      await flushPromises()

      expect(w.findAll('[data-testid=flashcard]').length).toBe(1)
      expect(w.find('[data-testid=meaning-card]').exists()).toBe(true) // still leaving
    })
  })
})
