import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import { useCoursesStore } from '@/stores/courses'
import type { Course, DayPlan, PracticeAccessToday, TodayResponse } from '@/api/types'

/*
 * "Today" when the active course is a course of phrases (docs/35 §3.2): the card of the step and the plan say «фразы». `/today` names no
 * section of its course: the catalog already read in this run says it (no request is made for it); without one - «слова».
 */

vi.mock('@/api', () => ({
  api: {
    today: { get: vi.fn() },
    assignments: { list: vi.fn() },
    training: { open: vi.fn() },
    me: { get: vi.fn(), updateSettings: vi.fn() },
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), learner: { first_name: 'Азиз' } }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const PRACTICE: PracticeAccessToday = { available: true, pro_required: false, modes: ['pairs', 'pairs_timed', 'collocation'], battle: false, pro_promise: 'none' }

const lessonStep = (overrides: Partial<DayPlan> = {}): DayPlan => ({
  step: 1,
  done: false,
  lesson: { done: false, lesson_id: null, next_lesson_id: 41, words_left: 5 },
  training: null,
  game: null,
  ...overrides,
})

const afterLesson = (overrides: Partial<DayPlan> = {}): DayPlan => ({
  step: 2,
  done: false,
  lesson: { done: true, lesson_id: 40, next_lesson_id: 41, words_left: 6 },
  training: { lesson_id: 40, title: 'Напитки', words: 5, done: false, open_session_id: null },
  game: { lesson_id: 40, course: 'english-a1', mode: 'pairs_timed', played: false },
  ...overrides,
})

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 4,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 12, best: 12, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
  active_course: {
    id: 3,
    slug: 'english-a1',
    title: 'English A1',
    progress: { learned: 120, total: 900 },
    next_lesson: { id: 41, stage: 1, lesson: 3, title: 'Напитки', words_left: 5 },
    readiness: null,
  },
  open_session_id: null,
  entitlement: { plan: 'pro', source: 'sponsor:organization:1', until: null, lapsed: null },
  onboarding_completed: true,
  league: { tier: 1, tier_key: 'bronze', rank: 3, points: 120, members_count: 30 },
  timezone: 'Asia/Tashkent',
  practice: PRACTICE,
  hard_round: { available: true, reason: null, words: 6, open_session_id: null, resets_at: null },
  game: { available: true, daily_available: true, active_run: null },
  assignments_possible: false,
  plan: lessonStep(),
  ...overrides,
})

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/billing', name: 'billing', component: stub },
      { path: '/referrals', name: 'referrals', component: stub },
      { path: '/league', name: 'league', component: stub },
      { path: '/settings', name: 'settings', component: stub },
      { path: '/game', name: 'game', component: stub },
      { path: '/game/daily', name: 'game-daily', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
  await router.push('/today')
  const wrapper = mount(TodayView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

type Wrapper = Awaited<ReturnType<typeof render>>
const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()

const phraseCourse: Course = {
  slug: 'english-a1',
  title: 'Разговорные фразы',
  description: null,
  level: 'A2–B1',
  exam_tag: 'phrases',
  section: 'phrases',
  words_count: 300,
  is_featured: false,
  is_active: true,
  progress: { done: 120, total: 300 },
}

describe('TodayView: the active course is a course of phrases', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    window.localStorage.setItem('lexiflow:intro-seen', '1')
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    vi.mocked(api.me.get).mockResolvedValue({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 10, renewal: null, lapsed: null },
      paywall: { mode: 'coming_soon', contact: null },
    } as never)
  })
  afterEach(() => {
    setLocale('ru')
    document.body.innerHTML = ''
  })

  it('step 1: «урок фраз», «5 новых фраз» on the card and in the plan', async () => {
    useCoursesStore().courses = [phraseCourse]
    const w = await render(today())

    expect(text(w, 'hero-eyebrow')).toBe('Урок фраз 3')
    expect(text(w, 'hero-title')).toBe('Напитки')
    expect(text(w, 'hero-text')).toBe('5 новых фраз')
    expect(w.get('[data-testid=plan-step][data-key=lesson]').text()).toContain('5 новых фраз')
  })

  it('step 1 with the level, from the open course of the catalog', async () => {
    useCoursesStore().detail = { ...phraseCourse, stages: [] }
    const response = today()
    response.active_course!.readiness = {
      level_label: 'A2–B1',
      percent: 10,
      words: { total: 300, learned: 120, mastered: 10, in_progress: 20, not_started: 150 },
      quiz: { enabled: false },
    }
    const w = await render(response)

    expect(text(w, 'hero-eyebrow')).toBe('A2–B1 · урок фраз 3')
  })

  it('step 2: «Закрепите 5 фраз»; step 3: the game on the phrases', async () => {
    useCoursesStore().courses = [phraseCourse]
    const w = await render(today({ plan: afterLesson() }))
    expect(text(w, 'hero-text')).toBe('Закрепите 5 фраз')
    expect(w.get('[data-testid=plan-step][data-key=lesson]').text()).toContain('5 новых фраз')

    const played = await render(today({ plan: afterLesson({ step: 3, training: { lesson_id: 40, title: 'Напитки', words: 5, done: true, open_session_id: null } }) }))
    expect(text(played, 'hero-text')).toBe('Игра на фразах этого урока')
  })

  it('a lesson of the day that is not surely of the active course (no title) keeps «слова»', async () => {
    useCoursesStore().courses = [phraseCourse]
    const w = await render(today({ plan: afterLesson({ training: { lesson_id: 40, title: null, words: 5, done: false, open_session_id: null } }) }))
    expect(text(w, 'hero-text')).toBe('Закрепите 5 слов')
  })

  it('no catalog in this run: «слова», and no request for the catalog (the mocked api has no `courses` to call)', async () => {
    const w = await render(today())
    expect(text(w, 'hero-text')).toBe('5 новых слов')
    expect(text(w, 'hero-eyebrow')).toBe('Урок 3')
  })

  it('a usual course in the catalog: «слова»', async () => {
    useCoursesStore().courses = [{ ...phraseCourse, section: 'courses', exam_tag: 'general' }]
    const w = await render(today())
    expect(text(w, 'hero-text')).toBe('5 новых слов')
  })

  it('in Uzbek', async () => {
    setLocale('uz')
    useCoursesStore().courses = [phraseCourse]
    const w = await render(today())
    expect(text(w, 'hero-text')).toBe('5 ta yangi ibora')
    expect(text(w, 'hero-eyebrow')).toBe('Iboralar 3-darsi')
  })
})
