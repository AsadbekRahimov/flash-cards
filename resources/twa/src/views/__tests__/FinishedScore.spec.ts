import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import FinishedView from '../FinishedView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import type { SessionScore, SessionSummary } from '@/api/types'

/*
 * The word points of a session on the finish screen (docs/32 §8.2, `score_session`): "+N" for the session, where they went (the weekly
 * rating / the month), what the cap cut off, the day after it. In a v2 week `league_points` = `week_added`: it is not shown a second time.
 * Without `score_session` the screen is the v1 one.
 */

vi.mock('@/api', () => ({
  api: { achievements: { list: vi.fn() }, league: { monthMe: vi.fn() }, me: { get: vi.fn() }, today: { get: vi.fn() }, training: { open: vi.fn(), finish: vi.fn() } },
}))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ close: vi.fn(), notificationHaptic: vi.fn() }) }))

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'landing', component: { template: '<div />' } },
    { path: '/today', name: 'today', component: { template: '<div />' } },
    { path: '/league/month', name: 'league-month', component: { template: '<div />' } },
    { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
    { path: '/achievements', name: 'achievements', component: { template: '<div />' } },
    { path: '/courses', name: 'courses', component: { template: '<div />' } },
    { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
  ],
})

const score = (overrides: Partial<SessionScore> = {}): SessionScore => ({
  earned: 70,
  week_added: 65,
  month_added: 65,
  week_rating_delta: 40,
  capped_off: 5,
  today: { points: 100, cap: 100, category: 'free', new: [10, 20], review: [20, 20], remaining: 0, day_ends_at: '2026-11-25T19:00:00+00:00', month: null },
  ...overrides,
})

const summary = (overrides: Partial<SessionSummary> = {}): SessionSummary => ({
  reviewed: 12,
  correct: 10,
  new_words: 3,
  goal: { target: 20, done: 12, met: false },
  ...overrides,
})

function render(value: SessionSummary, mode: 'daily_review' | 'lesson' = 'daily_review') {
  const training = useTrainingStore()
  training.mode = mode
  if (mode === 'lesson') training.lesson = { stage: 1, lesson: 3, title: 'Напитки' }
  training.summary = value
  return mount(FinishedView, { props: { reviewed: value.reviewed, nextInDays: 1, reason: null }, global: { plugins: [router] } })
}

describe('FinishedView — the word points of the session (docs/32 §8.2)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('reduce'), media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
    vi.mocked(api.achievements.list).mockRejectedValue(new Error('offline'))
    vi.mocked(api.league.monthMe).mockRejectedValue(new Error('offline'))
    vi.mocked(api.today.get).mockRejectedValue(new Error('offline'))
  })
  afterEach(() => {
    setLocale('ru')
    vi.unstubAllGlobals()
  })

  it('without `score_session` (an older server, the rules off): the v1 line "+35 баллов", nothing of v2', async () => {
    const w = render(summary({ league_points: 35 }))
    await flushPromises()

    expect(w.get('[data-testid=points-earned]').text()).toBe('+35 баллов')
    expect(w.find('[data-testid=score-session]').exists()).toBe(false)
    expect(w.find('[data-testid=score-split]').exists()).toBe(false)
  })

  it('says the points of the session, where they went and what the cap cut off - the weekly part (league_points) once', async () => {
    const w = render(summary({ league_points: 65, score_session: score() }))
    await flushPromises()

    const block = w.get('[data-testid=score-session]')
    expect(w.findAll('[data-testid=points-earned]')).toHaveLength(1)
    expect(w.get('[data-testid=points-earned]').text()).toBe('+70 баллов')
    expect(w.get('[data-testid=score-split]').text()).toBe('в недельный рейтинг +40 · в месяц +65')
    expect(w.get('[data-testid=score-capped-off]').text()).toBe('5 баллов сверх дневного потолка не засчитаны')
    expect(w.get('[data-testid=score-today-line]').text()).toBe('Сегодня: 100 / 100 баллов')
    // `league_points` (65 = week_added) is not a number of its own on the screen
    expect(block.text()).not.toContain('+65 баллов')
  })

  it('one part when the week and the month got the same; no cap line when nothing was cut; no day line without a credit today', async () => {
    const w = render(summary({ league_points: 21, score_session: score({ earned: 21, week_added: 21, month_added: 21, week_rating_delta: 21, capped_off: 0, today: null }) }))
    await flushPromises()

    expect(w.get('[data-testid=points-earned]').text()).toBe('+21 балл')
    expect(w.get('[data-testid=score-split]').text()).toBe('в неделю и месяц +21')
    expect(w.find('[data-testid=score-capped-off]').exists()).toBe(false)
    expect(w.find('[data-testid=score-today-line]').exists()).toBe(false)
  })

  it('a session that earned nothing: no block', async () => {
    const w = render(summary({ league_points: 0, score_session: score({ earned: 0, week_added: 0, month_added: 0, week_rating_delta: 0, capped_off: 0, today: null }) }))
    await flushPromises()

    expect(w.find('[data-testid=score-session]').exists()).toBe(false)
    expect(w.find('[data-testid=points-earned]').exists()).toBe(false)
  })

  it('a lesson: the points of the session are the tile of the stats, the split is a row below - not "+70" twice', async () => {
    const w = render(summary({ league_points: 65, score_session: score(), practice_entry: { course: 'english-a1', lesson_id: 41 } }), 'lesson')
    await flushPromises()

    expect(w.findAll('[data-testid=points-earned]')).toHaveLength(1)
    expect(w.get('[data-testid=stats] [data-testid=points-earned]').text()).toBe('+70')
    expect(w.get('[data-testid=score-split]').text()).toBe('в недельный рейтинг +40 · в месяц +65')
    expect(w.get('[data-testid=score-session]').text()).not.toContain('+70')
  })

  it('in Uzbek', async () => {
    setLocale('uz')
    const w = render(summary({ league_points: 65, score_session: score() }))
    await flushPromises()

    expect(w.get('[data-testid=points-earned]').text()).toBe('+70 ball')
    expect(w.get('[data-testid=score-split]').text()).toBe('haftalik reytingga +40 · oylik reytingga +65')
    expect(w.get('[data-testid=score-capped-off]').text()).toBe('5 ball kunlik chegaradan oshgani uchun hisoblanmadi')
    expect(w.get('[data-testid=score-today-line]').text()).toBe('Bugun: 100 / 100 ball')
  })
})
