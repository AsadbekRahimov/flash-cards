import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import type { DayPlan, PracticeAccessToday, TodayResponse, TrainingStartResponse } from '@/api/types'

/*
 * "Today" by the mock-ups of 2026-11-25 (Main / MainNext): the big card of the step of the plan of the day (lesson -> review of the
 * lesson -> timed pairs, docs/04 A.2a `plan`), the card "Plan for today · step N of M", the goal with the streak, the tiles "Review" and
 * "Word games"; the blocks that the mock-up has no place for stay under them.
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

const opened = (sessionId: number): TrainingStartResponse => ({
  session_id: sessionId,
  mode: 'lesson_review',
  lesson: { stage: 1, lesson: 3, title: 'Напитки' },
  total_words: 5,
  card: null,
  progress: { done: 0, total: 5 },
  done_reason: null,
  quota: { new_words_remaining: null },
}) as TrainingStartResponse

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
const steps = (w: Wrapper) => w.findAll('[data-testid=plan-step]').map((row) => [row.attributes('data-key'), row.attributes('data-state')])

describe('TodayView: the plan of the day', () => {
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

  describe('step 1: the lesson', () => {
    it('shows the lesson with its level, its title and the new words, and the plan "step 1 of 3"', async () => {
      const response = today()
      response.active_course!.readiness = {
        level_label: 'A1–A2',
        percent: 10,
        words: { total: 900, learned: 120, mastered: 10, in_progress: 20, not_started: 750 },
        quiz: { enabled: false },
      }
      const w = await render(response)

      const hero = w.get('[data-testid=lesson-hero]')
      expect(hero.attributes('data-step')).toBe('1')
      // the daftar of the kit: the ruled surface, radius xl, the red margin
      expect(hero.classes()).toEqual(expect.arrayContaining(['rounded-xl', 'bg-surface']))
      expect(hero.find('[data-testid=hero-lines]').exists()).toBe(true)
      expect(hero.find('[data-testid=hero-margin]').exists()).toBe(true)
      expect(text(w, 'hero-eyebrow')).toBe('A1–A2 · урок 3')
      expect(text(w, 'hero-title')).toBe('Напитки')
      expect(text(w, 'hero-text')).toBe('5 новых слов')
      // no estimate of minutes: the server sends none
      expect(hero.text()).not.toMatch(/минут/)
      expect(text(w, 'hero-btn')).toBe('Начать урок')

      expect(text(w, 'plan-counter')).toBe('шаг 1 из 3')
      expect(steps(w)).toEqual([['lesson', 'current'], ['training', 'upcoming'], ['game', 'upcoming']])
      expect(w.get('[data-testid=plan-step][data-key=lesson]').attributes('aria-current')).toBe('step')
      expect(w.get('[data-testid=plan-step][data-key=lesson]').text()).toContain('5 новых слов')
      // the current step: the muhr circle with its number (PlanStep of the kit)
      expect(w.get('[data-testid=plan-step][data-key=lesson] [data-testid=plan-step-circle]').text()).toBe('1')
      // the previous session card is not there
      expect(w.find('[data-testid=review-card]').exists()).toBe(false)
    })

    it('writes "Урок N" without a level and without a title', async () => {
      const response = today()
      response.active_course!.next_lesson = { id: 41, stage: 1, lesson: 3, title: null }
      const w = await render(response)

      expect(text(w, 'hero-eyebrow')).toBe('Урок 3')
      expect(text(w, 'hero-title')).toBe('Урок 3')
    })

    it('opens the lesson and goes to the training screen', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ ...opened(55), mode: 'lesson' } as TrainingStartResponse)
      const w = await render(today())

      await w.get('[data-testid=hero-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'plan' })
      expect(w.router.currentRoute.value.fullPath).toBe('/training/55')
    })

    it('quotes the Free quota of new words from the API, and with none left leads to Pro instead of the lesson', async () => {
      const free = { plan: 'free', source: 'free', until: null, lapsed: null } as const
      const w = await render(today({ entitlement: free, new_words_remaining: 10 }))
      expect(text(w, 'hero-note')).toBe('Новых слов на сегодня: 10')
      w.unmount()

      const spent = await render(today({ entitlement: free, new_words_remaining: 0, due_count: 0 }))
      expect(text(spent, 'hero-note')).toBe('Новые слова на сегодня закончились')
      expect(spent.find('[data-testid=hero-btn]').exists()).toBe(false)
      await spent.get('[data-testid=hero-pro]').trigger('click')
      expect(spent.find('[data-testid=paywall]').exists()).toBe(true)
    })

    it('leaves the game step out when the timed pairs cannot come today: "step 1 of 2"', async () => {
      const w = await render(today({ practice: { ...PRACTICE, pro_required: true } }))

      expect(text(w, 'plan-counter')).toBe('шаг 1 из 2')
      expect(steps(w)).toEqual([['lesson', 'current'], ['training', 'upcoming']])
    })
  })

  describe('the picture of the topic (kit Muhrla 01 / 02)', () => {
    const withCourse = (slug: string, stage: number | null): Partial<TodayResponse> => {
      const base = today()
      return { active_course: { ...base.active_course!, slug, next_lesson: { ...base.active_course!.next_lesson!, stage } } }
    }

    it('draws the topic of the module of the next lesson on the daftar of step 1, in the place of the kit (112 px)', async () => {
      const w = await render(today(withCourse('english-a1-a2', 3)))

      const art = w.get('[data-testid=lesson-hero] [data-testid=hero-art] [data-testid=topic-art]')
      expect(art.attributes('src')).toBe('/assets/img/m1/topics/english-a1-a2/03-120.webp')
      expect(art.attributes('width')).toBe('112')
      expect(art.attributes('alt')).toBe('')
    })

    it('keeps the topic after the lesson of the active course (steps 2 and 3), with the chip "lesson done" in green', async () => {
      const w = await render(today({ ...withCourse('english-a1-a2', 3), plan: afterLesson() }))

      expect(w.find('[data-testid=hero-art]').exists()).toBe(true)
      expect(w.get('[data-testid=hero-eyebrow]').attributes('data-tone')).toBe('success')
    })

    it('has no picture for a course without pictures or a lesson without a module: the text takes the width', async () => {
      const centre = await render(today())
      expect(centre.find('[data-testid=hero-art]').exists()).toBe(false)
      expect(text(centre, 'hero-title')).toBe('Напитки')
      expect(centre.get('[data-testid=hero-eyebrow]').attributes('data-tone')).toBe('neutral')
      centre.unmount()

      const noStage = await render(today(withCourse('english-a1-a2', null)))
      expect(noStage.find('[data-testid=hero-art]').exists()).toBe(false)
    })

    it('greets the done plan with the mascot celebrating instead of a topic', async () => {
      const w = await render(today({ plan: { ...afterLesson(), step: null, done: true } }))

      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('done')
      expect(w.get('[data-testid=lesson-hero] [data-testid=mascot-art]').attributes('data-pose')).toBe('celebrate')
    })
  })

  describe('step 2: the review of the lesson', () => {
    it('says the lesson is done and offers to fix its words, without a word about points', async () => {
      const w = await render(today({ plan: afterLesson(), goal: { target: 20, done: 13, met: false } }))

      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('2')
      expect(text(w, 'hero-eyebrow')).toBe('Урок «Напитки» пройден')
      expect(text(w, 'hero-title')).toBe('Тренировка')
      expect(text(w, 'hero-text')).toBe('Закрепите 5 слов')
      expect(w.get('[data-testid=lesson-hero]').text()).not.toMatch(/балл|очк/i)
      expect(text(w, 'hero-btn')).toBe('Начать тренировку')
      expect(text(w, 'plan-counter')).toBe('шаг 2 из 3')
      expect(steps(w)).toEqual([['lesson', 'done'], ['training', 'current'], ['game', 'upcoming']])
      expect(text(w, 'games-tile-text')).toBe('Шаг 3 на сегодня')
    })

    it('writes "Урок пройден" for a lesson out of the active course, and "Продолжить" for a live review', async () => {
      const w = await render(today({ plan: afterLesson({ training: { lesson_id: 40, title: null, words: 1, done: false, open_session_id: 9 } }) }))

      expect(text(w, 'hero-eyebrow')).toBe('Урок пройден')
      // the lesson is not surely of the active course: no picture of a topic
      expect(w.find('[data-testid=hero-art]').exists()).toBe(false)
      expect(text(w, 'hero-text')).toBe('Закрепите 1 слово')
      expect(text(w, 'hero-btn')).toBe('Продолжить')
    })

    it('opens the review of the lesson with the source of the plan and goes to the training screen', async () => {
      vi.mocked(api.training.open).mockResolvedValue(opened(77))
      const w = await render(today({ plan: afterLesson() }))

      await w.get('[data-testid=hero-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson_review', lesson_id: 40, source: 'plan' })
      expect(w.router.currentRoute.value.fullPath).toBe('/training/77')
    })

    it('on a refusal (409 lesson_review_unavailable) stays, says why and shows the plan the server reads now', async () => {
      vi.mocked(api.training.open).mockRejectedValue(
        new ApiClientError('conflict', 409, 'lesson_review_unavailable', 'No new words.', undefined, { reason: 'no_new_words' }),
      )
      const w = await render(today({ plan: afterLesson() }))
      vi.mocked(api.today.get).mockResolvedValue(today({ plan: afterLesson({ step: 3, training: null }) }))

      await w.get('[data-testid=hero-btn]').trigger('click')
      await flushPromises()

      expect(w.router.currentRoute.value.fullPath).toBe('/today')
      expect(api.today.get).toHaveBeenCalledTimes(2)
      expect(text(w, 'open-error')).toBe('Новых слов этого урока сегодня не было — закреплять пока нечего.')
      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('3')
      expect(steps(w)).toEqual([['lesson', 'done'], ['game', 'current']])
      expect(text(w, 'plan-counter')).toBe('шаг 2 из 2')
    })

    it('shows any other failure under the card and keeps the plan', async () => {
      vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('network', 0, 'network', 'offline'))
      const w = await render(today({ plan: afterLesson() }))

      await w.get('[data-testid=hero-btn]').trigger('click')
      await flushPromises()

      expect(text(w, 'open-error')).not.toBe('')
      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('2')
    })
  })

  describe('step 3: the game', () => {
    it('leads to the practice hub of the lesson that starts the timed pairs by itself', async () => {
      const w = await render(today({ plan: afterLesson({ step: 3, training: { lesson_id: 40, title: 'Напитки', words: 5, done: true, open_session_id: null } }) }))

      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('3')
      expect(text(w, 'hero-title')).toBe('Пары на время')
      expect(text(w, 'hero-btn')).toBe('Играть')
      expect(w.get('[data-testid=hero-btn]').attributes('href')).toBe('/practice/english-a1?lesson=40&play=pairs_timed&from=plan')
      expect(steps(w)).toEqual([['lesson', 'done'], ['training', 'done'], ['game', 'current']])
      expect(text(w, 'plan-counter')).toBe('шаг 3 из 3')

      await w.get('[data-testid=hero-btn]').trigger('click')
      await flushPromises()
      expect(w.router.currentRoute.value.name).toBe('practice-hub')
      expect(w.router.currentRoute.value.query).toEqual({ lesson: '40', play: 'pairs_timed', from: 'plan' })
    })

    it('opens the hub in the course of the lesson of the day, not in the active course (an assignment, a course switched since)', async () => {
      const w = await render(today({ plan: afterLesson({ step: 3, training: null, game: { lesson_id: 90, course: 'ielts-core', mode: 'pairs_timed', played: false } }) }))

      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('3')
      expect(w.get('[data-testid=hero-btn]').attributes('href')).toBe('/practice/ielts-core?lesson=90&play=pairs_timed&from=plan')
    })

    it('has the button of the game without an active course: the plan names the course of the lesson', async () => {
      const w = await render(today({
        active_course: null,
        due_count: 3,
        plan: afterLesson({ step: 3, lesson: { done: true, lesson_id: 90, next_lesson_id: null, words_left: null }, training: null, game: { lesson_id: 90, course: 'ielts-core', mode: 'pairs_timed', played: false } }),
      }))

      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('3')
      expect(w.get('[data-testid=hero-btn]').attributes('href')).toBe('/practice/ielts-core?lesson=90&play=pairs_timed&from=plan')
    })

    it('an older server without the course of the lesson: the active course only for a lesson surely of it, else the previous card', async () => {
      const legacyGame = { lesson_id: 40, mode: 'pairs_timed', played: false } as const
      const done = { lesson_id: 40, title: 'Напитки', words: 5, done: true, open_session_id: null }

      const own = await render(today({ plan: afterLesson({ step: 3, training: done, game: legacyGame }) }))
      expect(own.get('[data-testid=hero-btn]').attributes('href')).toBe('/practice/english-a1?lesson=40&play=pairs_timed&from=plan')
      own.unmount()

      // the lesson is not of the active course (no title): no step card without a button - the previous card of the session
      const other = await render(today({ plan: afterLesson({ step: 3, training: { ...done, title: null }, game: legacyGame }) }))
      expect(other.find('[data-testid=lesson-hero]').exists()).toBe(false)
      expect(other.find('[data-testid=review-card]').exists()).toBe(true)
      other.unmount()

      // the review switched off: nothing says whose the lesson is
      const unknown = await render(today({ plan: afterLesson({ step: 3, training: null, game: legacyGame }) }))
      expect(unknown.find('[data-testid=lesson-hero]').exists()).toBe(false)
      expect(unknown.find('[data-testid=review-card]').exists()).toBe(true)
    })

    it('leaves the training step out when the review is switched off', async () => {
      const w = await render(today({ plan: afterLesson({ step: 3, training: null }) }))

      expect(steps(w)).toEqual([['lesson', 'done'], ['game', 'current']])
      expect(text(w, 'plan-counter')).toBe('шаг 2 из 2')
    })

    it('leaves the game step out when the game is closed', async () => {
      const w = await render(today({ plan: afterLesson({ game: null }) }))

      expect(steps(w)).toEqual([['lesson', 'done'], ['training', 'current']])
      expect(text(w, 'plan-counter')).toBe('шаг 2 из 2')
    })
  })

  describe('the plan is done', () => {
    it('says so, marks every step done and offers the review of what is due', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ ...opened(31), mode: 'daily_review' } as TrainingStartResponse)
      const plan = afterLesson({
        step: null,
        done: true,
        training: { lesson_id: 40, title: 'Напитки', words: 5, done: true, open_session_id: null },
        game: { lesson_id: 40, course: 'english-a1', mode: 'pairs_timed', played: true },
      })
      const w = await render(today({ plan }))

      expect(w.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('done')
      expect(text(w, 'hero-title')).toBe('План на сегодня выполнен')
      expect(text(w, 'plan-counter')).toBe('всё сделано')
      expect(steps(w)).toEqual([['lesson', 'done'], ['training', 'done'], ['game', 'done']])
      expect(text(w, 'hero-review')).toBe('Повторить 4 слова')
      expect(w.find('[data-testid=hero-btn]').exists()).toBe(false)

      await w.get('[data-testid=hero-review]').trigger('click')
      await flushPromises()
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
    })

    it('says "a new lesson - tomorrow" only when the Free quota of new words is used up', async () => {
      const free = { plan: 'free', source: 'free', until: null, lapsed: null } as const
      const plan = afterLesson({ step: null, done: true, training: { lesson_id: 40, title: 'Напитки', words: 5, done: true, open_session_id: null }, game: null })

      const spent = await render(today({ entitlement: free, new_words_remaining: 0, plan }))
      expect(text(spent, 'hero-text')).toBe('Новый урок — завтра. Повторение и игры доступны всегда')
      expect(spent.find('[data-testid=hero-next-lesson]').exists()).toBe(false)
      expect(spent.find('[data-testid=all-done-pro]').exists()).toBe(true)
      spent.unmount()

      const left = await render(today({ entitlement: free, new_words_remaining: 4, plan }))
      expect(text(left, 'hero-text')).toBe('Повторение и игры доступны всегда')
      expect(left.find('[data-testid=all-done-pro]').exists()).toBe(false)
      expect(text(left, 'hero-next-lesson')).toBe('Следующий урок')
      left.unmount()

      // no lesson left in the course: the neutral text, no "Next lesson"
      const finished = today({ plan })
      finished.active_course!.next_lesson = null
      const over = await render(finished)
      expect(text(over, 'hero-text')).toBe('Повторение и игры доступны всегда')
      expect(over.find('[data-testid=hero-next-lesson]').exists()).toBe(false)
    })

    it('"Next lesson" of the done card opens the next lesson from the plan', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ ...opened(56), mode: 'lesson' } as TrainingStartResponse)
      const w = await render(today({ due_count: 0, plan: afterLesson({ step: null, done: true, training: { lesson_id: 40, title: 'Напитки', words: 5, done: true, open_session_id: null }, game: null }) }))

      // the only action of the card: tinted, not plain
      expect(w.get('[data-testid=hero-next-lesson]').classes()).toContain('btn-tinted')
      await w.get('[data-testid=hero-next-lesson]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'plan' })
      expect(w.router.currentRoute.value.fullPath).toBe('/training/56')
    })

    it('has no review button with nothing due', async () => {
      const w = await render(today({ due_count: 0, plan: afterLesson({ step: null, done: true, game: null }) }))

      expect(w.find('[data-testid=hero-review]').exists()).toBe(false)
      expect(w.get('[data-testid=review-tile]').attributes('data-active')).toBe('false')
      expect(text(w, 'review-tile-text')).toBe('Пока нечего')
    })
  })

  describe('nothing to suggest (step null, not done)', () => {
    it('keeps the previous card of the session: a finished course leads to another one', async () => {
      const response = today({ due_count: 0, plan: { step: null, done: false, lesson: { done: false, lesson_id: null, next_lesson_id: null, words_left: null }, training: null, game: null } })
      response.active_course!.next_lesson = null
      const w = await render(response)

      expect(w.find('[data-testid=lesson-hero]').exists()).toBe(false)
      expect(w.find('[data-testid=day-plan]').exists()).toBe(false)
      expect(w.find('[data-testid=review-card]').exists()).toBe(true)
      expect(w.find('[data-testid=start-next-course]').exists()).toBe(true)
    })

    it('keeps the start card for a newcomer without a course', async () => {
      const w = await render(today({
        active_course: null,
        due_count: 0,
        hard_round: null,
        plan: { step: null, done: false, lesson: { done: false, lesson_id: null, next_lesson_id: null, words_left: null }, training: null, game: null },
      }))

      expect(w.find('[data-testid=start-card]').exists()).toBe(true)
      expect(w.find('[data-testid=lesson-hero]').exists()).toBe(false)
    })
  })

  describe('the goal and the tiles (mock-up Main)', () => {
    it('shows «8 / 20 слов», a bar of 20 segments with 8 filled and the streak', async () => {
      const w = await render(today())

      expect(text(w, 'day-goal-count')).toBe('8 / 20 слов')
      expect(text(w, 'day-goal-aside')).toBe('серия 12 дней')
      const segments = w.findAll('[data-testid=day-goal-bar] span')
      expect(segments).toHaveLength(20)
      // the dots of the kit: a word learned is a dot in the muhr colour
      expect(segments.filter((s) => s.classes().includes('bg-muhr'))).toHaveLength(8)
      expect(w.get('[data-testid=goal-setting]').attributes('href')).toBe('/settings')
    })

    it('says what is left without a streak, and the goal met', async () => {
      const none = await render(today({ streak: { current: 0, best: 3, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } }))
      expect(text(none, 'day-goal-aside')).toBe('ещё 12 слов')
      none.unmount()

      const met = await render(today({ goal: { target: 20, done: 22, met: true } }))
      expect(text(met, 'day-goal-aside')).toBe('Цель выполнена')
      expect(met.findAll('[data-testid=day-goal-bar] .bg-muhr')).toHaveLength(20)
    })

    it('opens the daily review from the tile "Review" and the practice from "Word games"', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ ...opened(31), mode: 'daily_review' } as TrainingStartResponse)
      const w = await render(today())

      expect(text(w, 'review-tile-text')).toBe('4 слова готовы')
      expect(text(w, 'games-tile-text')).toBe('Шаг 3 на сегодня')
      await w.get('[data-testid=review-tile]').trigger('click')
      await flushPromises()
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })

      await w.router.push('/today')
      await w.get('[data-testid=games-tile]').trigger('click')
      await flushPromises()
      expect(w.router.currentRoute.value.fullPath).toBe('/practice/english-a1')
    })

    it('has no games tile where the practice is closed', async () => {
      const w = await render(today({ practice: { ...PRACTICE, available: false } }))

      expect(w.find('[data-testid=games-tile]').exists()).toBe(false)
      expect(w.find('[data-testid=review-tile]').exists()).toBe(true)
    })
  })

  describe('the blocks under the main screen', () => {
    it('keeps the hard round, the caravan, the course, the streak, the league and the invitation below the plan', async () => {
      const w = await render(today())

      const order = ['lesson-hero', 'day-plan', 'day-goal', 'day-tiles', 'today-list', 'course-card', 'streak-card', 'league-line']
      const nodes = order.map((id) => w.get(`[data-testid=${id}]`).element)
      for (let i = 1; i < nodes.length; i++) {
        expect(nodes[i - 1]!.compareDocumentPosition(nodes[i]!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
      }
      expect(w.find('[data-testid=hard-round-card]').exists()).toBe(true)
      expect(w.find('[data-testid=game-entry]').exists()).toBe(true)
      // the games row of the list is the tile now; the lesson of the course card is the big card
      expect(w.find('[data-testid=games-card]').exists()).toBe(false)
      expect(w.find('[data-testid=next-lesson-btn]').exists()).toBe(false)
      expect(text(w, 'next-lesson')).toContain('Напитки')
    })

    it('keeps the lesson of the course card after the lesson of the day', async () => {
      const w = await render(today({ plan: afterLesson() }))

      expect(w.find('[data-testid=next-lesson-btn]').exists()).toBe(true)
    })

    it('keeps the banner of a lapsed Pro over the plan', async () => {
      const w = await render(today({ entitlement: { plan: 'free', source: 'free', until: null, lapsed: { provider: 'telegram_stars', ended_at: '2026-11-20T07:00:00+00:00' } } }))

      const banner = w.get('[data-testid=lapsed-banner]').element
      expect(banner.compareDocumentPosition(w.get('[data-testid=lesson-hero]').element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = await render(today({ plan: afterLesson() }))

    expect(text(w, 'hero-eyebrow')).toBe("«Напитки» darsi o'tildi")
    expect(text(w, 'hero-text')).toBe("5 ta so'zni mustahkamlang")
    expect(text(w, 'plan-counter')).toBe('3 qadamdan 2-qadam')
  })
})
