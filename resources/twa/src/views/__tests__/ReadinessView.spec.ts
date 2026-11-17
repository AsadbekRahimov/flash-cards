import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ReadinessView from '../ReadinessView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { ru } from '@/i18n/ru'
import { uz } from '@/i18n/uz'
import type { ReadinessResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: { readiness: { get: vi.fn() }, exam: { openLevelQuiz: vi.fn() } },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const readiness = (quiz: ReadinessResponse['quiz'] | null = null): ReadinessResponse => ({
  course: { id: 3, slug: 'english-a1-a2', title: 'Английский A1–A2', level: 'A1', level_label: 'A1–A2' },
  percent: 42,
  words: { total: 100, learned: 42, mastered: 10, known: 32, in_progress: 18, not_started: 40 },
  thresholds: { known_days: 7, mastered_days: 21 },
  modules: [{ number: 1, title: 'Личность', learned: 20, total: 50 }, { number: 2, title: null, learned: 0, total: 50 }],
  quiz: quiz ?? { enabled: true, questions: 12, seconds: 600, week_start: '2026-11-16', this_week: null, history: [], best_percent: null },
})

async function render(data: ReadinessResponse | Error, query = '') {
  if (data instanceof Error) vi.mocked(api.readiness.get).mockRejectedValue(data)
  else vi.mocked(api.readiness.get).mockResolvedValue(data)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses/:slug/readiness', name: 'readiness', component: { template: '<div />' } },
      { path: '/exam/:sessionId(\\d+)', name: 'exam', component: { template: '<div />' } },
      { path: '/today', name: 'today', component: { template: '<div />' } },
    ],
  })
  await router.push(`/courses/english-a1-a2/readiness${query}`)
  const wrapper = mount(ReadinessView, { props: { slug: 'english-a1-a2' }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

const text = (w: Awaited<ReturnType<typeof render>>['wrapper'], id: string): string => w.get(`[data-testid=${id}]`).text()

describe('ReadinessView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('shows the percent, the three parts, the formula and the modules', async () => {
    const { wrapper } = await render(readiness(), '?source=profile')

    expect(api.readiness.get).toHaveBeenCalledWith('english-a1-a2', 'profile')
    expect(text(wrapper, 'readiness-percent')).toBe('42%')
    expect(text(wrapper, 'readiness-heading')).toBe('Освоено 42% слов уровня A1–A2')
    expect(text(wrapper, 'readiness-learned')).toContain('42')
    expect(text(wrapper, 'readiness-in-progress')).toContain('18')
    expect(text(wrapper, 'readiness-not-started')).toContain('40')
    expect(text(wrapper, 'readiness-mastered')).toContain('10')
    expect(text(wrapper, 'readiness-explain')).toContain('с перерывом в 7 дней')
    expect(text(wrapper, 'readiness-explain')).toContain('не результат экзамена')
    expect(text(wrapper, 'readiness-module-1')).toContain('20 / 50')
    expect(text(wrapper, 'readiness-module-2')).toContain('Модуль 2')
    expect(wrapper.get('[data-testid=readiness-bar]').attributes('aria-valuenow')).toBe('42')
  })

  it('falls back to the source "today" for an unknown one and drops the level for a course without it', async () => {
    const data = readiness()
    data.course.level_label = null
    const { wrapper } = await render(data, '?source=nonsense')

    expect(api.readiness.get).toHaveBeenCalledWith('english-a1-a2', 'today')
    expect(text(wrapper, 'readiness-heading')).toBe('Освоено 42% слов курса')
  })

  it('has an empty chart and the scored-test button when no test was taken', async () => {
    const { wrapper } = await render(readiness())

    expect(wrapper.find('[data-testid=quiz-chart-empty]').exists()).toBe(true)
    expect(text(wrapper, 'quiz-btn')).toBe('Пройти тест недели')
    expect(wrapper.get('[data-testid=quiz-btn]').attributes('data-scored')).toBe('open')
    expect(text(wrapper, 'quiz-hint')).toContain('12 вопросов')
    expect(wrapper.find('[data-testid=quiz-this-week]').exists()).toBe(false)
  })

  it('says a repeat is practice and is not recorded once this week is scored', async () => {
    const { wrapper } = await render(readiness({
      enabled: true, questions: 12, seconds: 600, week_start: '2026-11-16',
      this_week: { correct: 8, total: 12, percent: 66, finished_at: '2026-11-17T09:12:00+00:00' },
      history: [{ week_start: '2026-11-16', correct: 8, total: 12, percent: 66 }],
      best_percent: 66,
    }))

    expect(text(wrapper, 'quiz-this-week')).toContain('8 из 12')
    expect(text(wrapper, 'quiz-best')).toBe('Лучший результат: 66%')
    expect(text(wrapper, 'quiz-btn')).toBe('Пройти ещё раз (тренировочно, без записи)')
    expect(wrapper.get('[data-testid=quiz-btn]').attributes('data-scored')).toBe('done')
    expect(text(wrapper, 'quiz-hint')).toContain('не запишется')
    expect(wrapper.find('[data-testid=quiz-bar-2026-11-16]').exists()).toBe(true)
  })

  it('opens the test and goes to the exam screen with mode=quiz', async () => {
    vi.mocked(api.exam.openLevelQuiz).mockResolvedValue({
      session_id: 91, mode: 'level_quiz', course_id: 3, week_start: '2026-11-16', scored: true,
      started_at: null, ends_at: null, total_questions: 12, seconds: 600, resumed: false,
    })
    const { wrapper, router } = await render(readiness())

    await wrapper.get('[data-testid=quiz-btn]').trigger('click')
    await flushPromises()

    expect(api.exam.openLevelQuiz).toHaveBeenCalledWith('english-a1-a2')
    expect(router.currentRoute.value.name).toBe('exam')
    expect(router.currentRoute.value.params.sessionId).toBe('91')
    expect(router.currentRoute.value.query.mode).toBe('quiz')
  })

  it('explains a course with too few words instead of a general error', async () => {
    vi.mocked(api.exam.openLevelQuiz).mockRejectedValue(new ApiClientError('validation', 422, 'not_enough_words', 'x'))
    const { wrapper, router } = await render(readiness())

    await wrapper.get('[data-testid=quiz-btn]').trigger('click')
    await flushPromises()

    expect(text(wrapper, 'quiz-error')).toBe('В курсе пока мало слов для теста недели.')
    expect(router.currentRoute.value.name).toBe('readiness')
  })

  it('has no test block when the quiz is off', async () => {
    const { wrapper } = await render(readiness({ enabled: false }))

    expect(wrapper.find('[data-testid=readiness-quiz]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=readiness-summary]').exists()).toBe(true)
  })

  it('says the feature is unavailable on 404 readiness_disabled, without a retry loop', async () => {
    const { wrapper } = await render(new ApiClientError('not_found', 404, 'readiness_disabled', 'off'))

    expect(text(wrapper, 'readiness-disabled')).toContain('Готовность к уровню сейчас недоступна.')
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    const { wrapper } = await render(readiness())

    expect(text(wrapper, 'readiness-heading')).toBe("A1–A2 darajasi so'zlarining 42% o'zlashtirildi")
    expect(text(wrapper, 'quiz-btn')).toBe('Hafta testini topshirish')
    expect(text(wrapper, 'readiness-explain')).toContain('imtihon natijasi emas')
  })
})

describe('readiness texts make no promise about an exam', () => {
  it('says "not an exam result" in both languages and never promises to pass', () => {
    const forms = (value: unknown): string[] => (typeof value === 'string' ? [value] : Object.values(value as Record<string, string>))
    for (const text of forms(ru['readiness.explain'])) expect(text).toContain('не результат экзамена')
    for (const text of forms(uz['readiness.explain'])) expect(text).toContain('imtihon natijasi emas')

    const all = [...Object.entries(ru), ...Object.entries(uz)]
      .filter(([key]) => key.startsWith('readiness.') || key.startsWith('result.quiz') || key.startsWith('exam.quiz'))
      .flatMap(([, value]) => forms(value))
      .join(' ')
      .toLowerCase()
    for (const banned of ['сдадите', 'готовы к экзамену', 'topshira olasiz', 'imtihonga tayyor']) expect(all).not.toContain(banned)
  })
})
