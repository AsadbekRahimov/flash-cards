import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CourseView from '../CourseView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import type { Course, CourseDetail } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: {
    courses: {
      list: vi.fn(),
      get: vi.fn(),
      select: vi.fn(),
    },
    training: {
      open: vi.fn(),
    },
    exam: {
      openMock: vi.fn(),
    },
    // The paywall sheet (a refused mock) reads /me, the promo form and /today.
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {} }),
}))

const detail = (overrides: Partial<CourseDetail> = {}): CourseDetail => ({
  slug: 'english-a1',
  title: 'Английский A1',
  description: null,
  level: 'A1',
  exam_tag: null,
  words_count: 6,
  is_active: false,
  progress: { done: 2, total: 5 },
  stages: [
    {
      number: 1,
      title: 'Basics',
      progress: { done: 2, total: 5 },
      lessons: [
        { id: 11, number: 1, title: 'Greetings', progress: { done: 2, total: 3 } },
        { id: 12, number: 2, title: null, progress: { done: 0, total: 2 } },
      ],
    },
    { number: 2, title: 'Food', progress: { done: 0, total: 0 }, lessons: [] },
  ],
  ...overrides,
})

async function render(slug = 'english-a1') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
      { path: '/exam/:sessionId(\\d+)', name: 'exam', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(CourseView, { props: { slug }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('CourseView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('loads the course by slug and shows stages with lesson progress', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail())

    const { wrapper } = await render()

    expect(api.courses.get).toHaveBeenCalledWith('english-a1')
    expect(wrapper.get('[data-testid=title]').text()).toBe('Английский A1')
    expect(wrapper.get('[data-testid=progress-text]').text()).toBe('Изучено 2 из 5')

    const stage = wrapper.get('[data-testid=stage-1]')
    expect(stage.text()).toContain('Basics')
    expect(stage.text()).toContain('Модуль 1')

    const first = wrapper.get('[data-testid=lesson-11]')
    expect(first.text()).toContain('Урок 1 · Greetings')
    expect(first.get('[data-testid=lesson-progress]').text()).toBe('2 / 3')
    expect(first.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 67%')

    const untitled = wrapper.get('[data-testid=lesson-12]')
    expect(untitled.text()).toContain('Урок 2')
    expect(untitled.text()).not.toContain('·')
  })

  it('offers to learn every lesson that has words', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({
      stages: [{
        number: 1, title: 'Basics', progress: { done: 0, total: 3 },
        lessons: [
          { id: 11, number: 1, title: 'Greetings', progress: { done: 2, total: 3 } },
          { id: 12, number: 2, title: null, progress: { done: 0, total: 0 } },
        ],
      }],
    }))

    const { wrapper } = await render()

    const learn = wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]')
    expect(learn.text()).toBe('Учить')
    expect(learn.attributes('disabled')).toBeUndefined()
    // An empty lesson has nothing to learn.
    expect(wrapper.get('[data-testid=lesson-12]').get('[data-testid=learn-btn]').attributes('disabled')).toBeDefined()
  })

  it('opens a personal lesson session from the picker and goes to the training screen', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail())
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 77, mode: 'lesson', lesson: { stage: 1, lesson: 2, title: null }, total_words: 2,
    })

    const { wrapper, router } = await render()
    await wrapper.get('[data-testid=lesson-12]').get('[data-testid=learn-btn]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 12, source: 'picker' })
    expect(router.currentRoute.value.name).toBe('training')
    expect(router.currentRoute.value.params.sessionId).toBe('77')
  })

  it('stays on the course and explains why a lesson could not be opened', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail())
    vi.mocked(api.training.open).mockRejectedValue(
      new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
    )

    const { wrapper, router } = await render()
    await router.push('/courses')
    await wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('courses')
    expect(wrapper.get('[data-testid=open-error]').text()).toBe('Урок не найден.')
  })

  it('forgets the open error when the learner leaves the screen', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail())
    vi.mocked(api.training.open).mockRejectedValue(
      new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
    )

    const { wrapper } = await render()
    await wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').trigger('click')
    await flushPromises()
    expect(useTrainingStore().openError).toBe('Урок не найден.')

    wrapper.unmount()

    expect(useTrainingStore().openError).toBe('')
  })

  it('selects the course and flips the button to the active mark', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail())
    vi.mocked(api.courses.select).mockResolvedValue({
      slug: 'english-a1', title: 'Английский A1', description: null, level: 'A1', exam_tag: null,
      words_count: 6, is_featured: false, is_active: true, progress: { done: 2, total: 5 },
    } satisfies Course)

    const { wrapper } = await render()
    await wrapper.get('[data-testid=choose-btn]').trigger('click')
    await flushPromises()

    expect(api.courses.select).toHaveBeenCalledWith('english-a1')
    expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=active-badge]').text()).toContain('Активный')
  })

  it('shows the active mark for the active course', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true }))

    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=active-badge]').exists()).toBe(true)
  })

  it('shows a placeholder for a course without lessons', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [], progress: { done: 0, total: 0 } }))

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=no-lessons]').text()).toBe('В курсе пока нет уроков.')
  })

  it('explains an unavailable course', async () => {
    vi.mocked(api.courses.get).mockRejectedValue(
      new ApiClientError('not_found', 404, 'course_not_found', 'Course not found.'),
    )

    const { wrapper } = await render('gone')

    expect(wrapper.get('[data-testid=error]').text()).toContain('Курс не найден или недоступен.')
    expect(wrapper.find('[data-testid=title]').exists()).toBe(false)
  })

  // --- IELTS / SAT mock (task 2D.4, docs/13 §8.4) ---------------------------------

  it('offers the mock exam only on IELTS / SAT courses', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', words_count: 120 }))
    const { wrapper } = await render()
    expect(wrapper.get('[data-testid=mock-exam-btn]').text()).toContain('Пробный тест')
    expect(wrapper.get('[data-testid=mock-exam-hint]').text()).toBe('40 слов курса · 20 мин · результат в процентах и сравнение с другими')

    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'sat', words_count: 12 }))
    const sat = await render()
    expect(sat.wrapper.find('[data-testid=mock-exam-btn]').exists()).toBe(true)
    expect(sat.wrapper.get('[data-testid=mock-exam-hint]').text()).toContain('12 слов курса')

    for (const tag of [null, 'general', 'school']) {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: tag }))
      const other = await render()
      expect(other.wrapper.find('[data-testid=mock-exam]').exists()).toBe(false)
    }
  })

  it('opens the mock for a Pro learner and goes to the exam screen in mock mode', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
    vi.mocked(api.exam.openMock).mockResolvedValue({
      session_id: 91, mode: 'personal_mock', course_id: 5, started_at: '2026-10-20T10:00:00Z',
      ends_at: '2026-10-20T10:20:00Z', total_questions: 40, seconds: 1200, resumed: false,
    })

    const { wrapper, router } = await render()
    await wrapper.get('[data-testid=mock-exam-btn]').trigger('click')
    await flushPromises()

    expect(api.exam.openMock).toHaveBeenCalledWith('english-a1')
    expect(router.currentRoute.value.name).toBe('exam')
    expect(router.currentRoute.value.params.sessionId).toBe('91')
    expect(router.currentRoute.value.query.mode).toBe('mock')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('shows the paywall with the simulator reason when the server refuses a Free learner', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'sat' }))
    vi.mocked(api.exam.openMock).mockRejectedValue(
      new ApiClientError('forbidden', 403, 'exam_simulator_pro_required', 'Mock exams are a Pro feature.'),
    )
    vi.mocked(api.me.get).mockResolvedValue({
      learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
      groups: [],
      stats: { words_learned: 0, words_due_today: 0 },
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
      paywall: { mode: 'coming_soon', contact: null },
      settings: settingsFixture(),
    })

    const { wrapper, router } = await render()
    await router.push('/courses')
    await wrapper.get('[data-testid=mock-exam-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('courses')
    expect(wrapper.get('[data-testid=paywall-lead]').text()).toContain('Пробные тесты IELTS и SAT — в Pro')
    expect(wrapper.find('[data-testid=mock-exam-error]').exists()).toBe(false)

    await wrapper.get('[data-testid=paywall-close]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('explains any other refusal of the mock on the course screen', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
    vi.mocked(api.exam.openMock).mockRejectedValue(
      new ApiClientError('validation', 422, 'not_enough_words', 'Too few words.'),
    )

    const { wrapper } = await render()
    await wrapper.get('[data-testid=mock-exam-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=mock-exam-error]').text()).toBe('В курсе пока слишком мало слов для теста.')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    vi.mocked(api.courses.get).mockResolvedValue(detail())

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=stage-1]').text()).toContain('1-modul')
    expect(wrapper.get('[data-testid=lesson-11]').text()).toContain('1-dars · Greetings')
    expect(wrapper.get('[data-testid=progress-text]').text()).toBe("5 tadan 2 tasi o'rganildi")
    expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Tanlash')
    expect(wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').text()).toBe("O'rganish")

    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
    const mock = await render()
    expect(mock.wrapper.get('[data-testid=mock-exam-btn]').text()).toContain('Sinov testi')
  })
})
