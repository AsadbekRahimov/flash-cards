import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import TrainingView from '../TrainingView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { Card, ReviewResponse, WordScore } from '@/api/types'

// An answer that waited for the network is kept in `sessionStorage` (docs/40 §2.6): every test starts without one.
beforeEach(() => window.sessionStorage.clear())

/*
 * The chip of the word points after an answer (docs/32 §8.1, `score` of `/review`): a quiet «+N» from `score.week`; of the reasons of
 * "no points" only the cap of the day is said, once a session. The answer itself goes on as before.
 */

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

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => telegram }))
vi.mock('@/composables/useFeedbackSound', () => ({ playSound: vi.fn() }))

const flash = (id: number): Card => ({
  word_id: id,
  word: `word${id}`,
  translation: `слово${id}`,
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'due',
  serve_token: null,
})

const credited = (week: number, points = 40, cap: number | null = 100, overrides: Partial<WordScore> = {}): WordScore => ({
  kind: 'review',
  reason: 'credited',
  base: 3,
  week,
  month: week,
  today: { points, cap, category: 'free', new: [2, 20], review: [10, 20] },
  today_month: null,
  ...overrides,
})

const reply = (next: Card | null, score?: WordScore | null): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 1,
  easiness_factor: 2.5,
  graded: null,
  card: next,
  progress: { done: 1, total: 30 },
  ...(score === undefined ? {} : { score }),
})

async function render() {
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1, mode: 'daily_review', lesson: null, total_words: 30, card: flash(1), progress: { done: 0, total: 30 },
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
  const wrapper = mount(TrainingView, { props: { sessionId: 1 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof render>>

/** Reveals the flash card and presses "knew it"; the review answers with `res`. */
async function grade(w: Wrapper, res: ReviewResponse) {
  vi.mocked(api.training.review).mockResolvedValue(res)
  if (w.find('[data-testid=reveal-btn]').exists()) await w.get('[data-testid=reveal-btn]').trigger('click')
  await w.get('[data-testid=grade-medium]').trigger('click')
  await flushPromises()
}

describe('TrainingView — the chip of the word points (docs/32 §8.1)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })
  afterEach(() => setLocale('ru'))

  it('an older server (no `score`) and the rules off (`score: null`): no chip, no line', async () => {
    const w = await render()
    await grade(w, reply(flash(2)))
    expect(w.find('[data-testid=score-chip]').exists()).toBe(false)

    await grade(w, reply(flash(3), null))
    expect(w.find('[data-testid=score-chip]').exists()).toBe(false)
    expect(w.find('[data-testid=score-capped]').exists()).toBe(false)
    // the answer goes on as before
    expect(w.get('[data-testid=progress]').text()).toBe('1 из 30')
  })

  it('a credited answer: a quiet «+3» by `score.week`, with the points for a screen reader; the next answer replaces it', async () => {
    const w = await render()
    await grade(w, reply(flash(2), credited(3)))

    const chip = w.get('[data-testid=score-chip]')
    expect(chip.text()).toBe('+3')
    expect(chip.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
    expect(chip.attributes('aria-label')).toBe('Начислено: 3 балла')
    expect(w.find('[data-testid=score-capped]').exists()).toBe(false)

    await grade(w, reply(flash(3), credited(5)))
    expect(w.get('[data-testid=score-chip]').text()).toBe('+5')
  })

  it('says nothing for the reasons that are no news (already today, early, the introduction of a word)', async () => {
    const w = await render()
    for (const reason of ['already_today', 'early', 'introductory', 'same_cycle', 'not_due']) {
      await grade(w, reply(flash(2), credited(0, 0, 100, { reason, kind: null, base: 0, month: 0, today: null })))
      expect(w.find('[data-testid=score-chip]').exists()).toBe(false)
      expect(w.find('[data-testid=score-capped]').exists()).toBe(false)
    }
  })

  it('the cap of the day is said once a session, with the points of the answer that reached it', async () => {
    const w = await render()
    await grade(w, reply(flash(2), credited(2, 100, 100)))

    expect(w.get('[data-testid=score-chip]').text()).toBe('+2')
    expect(w.get('[data-testid=score-capped]').text()).toBe('На сегодня баллов больше не будет — слова всё равно учатся')

    // the next answers at the cap: quiet
    await grade(w, reply(flash(3), credited(0, 100, 100, { reason: 'capped', month: 0 })))
    expect(w.find('[data-testid=score-chip]').exists()).toBe(false)
    expect(w.find('[data-testid=score-capped]').exists()).toBe(false)
  })

  it('a full week while the month of its own still takes points: no "no more points today"; said once both are full', async () => {
    const w = await render()
    const monthDay = (points: number) => ({ points, cap: 150, category: 'pro' as const, new: [2, null] as [number, null], review: [10, null] as [number, null] })
    await grade(w, reply(flash(2), credited(0, 100, 100, { reason: 'capped', month: 3, today_month: monthDay(120) })))

    expect(w.get('[data-testid=score-chip]').text()).toBe('+3')
    expect(w.find('[data-testid=score-capped]').exists()).toBe(false)

    await grade(w, reply(flash(3), credited(0, 100, 100, { reason: 'capped', month: 3, today_month: monthDay(150) })))
    expect(w.get('[data-testid=score-capped]').text()).toBe('На сегодня баллов больше не будет — слова всё равно учатся')
  })

  it('takes the monthly points when only the month counts on the v2 rules', async () => {
    const w = await render()
    await grade(w, reply(flash(2), credited(0, 0, null, { month: 3 })))

    expect(w.get('[data-testid=score-chip]').text()).toBe('+3')
  })

  it('in Uzbek', async () => {
    setLocale('uz')
    const w = await render()
    await grade(w, reply(flash(2), credited(2, 100, 100)))

    expect(w.get('[data-testid=score-chip]').attributes('aria-label')).toBe('Berildi: 2 ball')
    expect(w.get('[data-testid=score-capped]').text()).toBe("Bugun boshqa ball berilmaydi — so'zlar baribir o'rganiladi")
  })
})
