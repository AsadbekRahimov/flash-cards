import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CoursesView from '../CoursesView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { Course } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    courses: {
      list: vi.fn(),
      get: vi.fn(),
      select: vi.fn(),
    },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {} }),
}))

const course = (overrides: Partial<Course> = {}): Course => ({
  slug: 'english-a1',
  title: 'Английский A1',
  description: 'Первые слова',
  level: 'A1',
  exam_tag: 'general',
  words_count: 5,
  is_featured: false,
  is_active: false,
  progress: { done: 2, total: 5 },
  ...overrides,
})

async function render() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'landing', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/courses/:slug', name: 'course', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(CoursesView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('CoursesView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('renders a card per course with level, goal, word count and progress', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({
      courses: [course(), course({ slug: 'ielts', title: 'IELTS', description: null, level: null, exam_tag: 'ielts', words_count: 1, progress: { done: 0, total: 1 } })],
    })

    const { wrapper } = await render()

    const first = wrapper.get('[data-testid=course-english-a1]')
    expect(first.text()).toContain('Английский A1')
    expect(first.text()).toContain('Первые слова')
    expect(first.get('[data-testid=meta]').text()).toBe('A1 · Общий английский · 5 слов')
    expect(first.get('[data-testid=progress-text]').text()).toBe('Изучено 2 из 5')
    expect(first.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('40')
    expect(first.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 40%')

    const second = wrapper.get('[data-testid=course-ielts]')
    expect(second.get('[data-testid=meta]').text()).toBe('IELTS · 1 слово')
    expect(wrapper.findAll('[data-testid=course-link]')).toHaveLength(2)
  })

  it('links a card to the course page', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=course-link]').attributes('href')).toBe('/courses/english-a1')
  })

  it('shows "Активный" for the active course and "Выбрать" for the others', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({
      courses: [course({ is_active: true }), course({ slug: 'other', title: 'Other' })],
    })

    const { wrapper } = await render()

    const active = wrapper.get('[data-testid=course-english-a1]')
    expect(active.get('[data-testid=active-badge]').text()).toContain('Активный')
    expect(active.get('[data-testid=active-badge]').attributes('disabled')).toBeDefined()
    expect(active.find('[data-testid=choose-btn]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=course-other] [data-testid=choose-btn]').text()).toBe('Выбрать')
  })

  it('selects a course and moves the active mark to it', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({
      courses: [course({ is_active: true }), course({ slug: 'other', title: 'Other', progress: { done: 1, total: 4 } })],
    })
    vi.mocked(api.courses.select).mockResolvedValue(course({ slug: 'other', title: 'Other', is_active: true, progress: { done: 1, total: 4 } }))

    const { wrapper } = await render()
    await wrapper.get('[data-testid=course-other] [data-testid=choose-btn]').trigger('click')
    await flushPromises()

    expect(api.courses.select).toHaveBeenCalledWith('other')
    expect(wrapper.find('[data-testid=course-other] [data-testid=active-badge]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=course-english-a1] [data-testid=active-badge]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=course-english-a1] [data-testid=choose-btn]').exists()).toBe(true)
  })

  it('keeps the list and shows the reason when selecting fails', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })
    vi.mocked(api.courses.select).mockRejectedValue(
      new ApiClientError('not_found', 404, 'course_not_found', 'Course not found.'),
    )

    const { wrapper } = await render()
    await wrapper.get('[data-testid=choose-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=select-error]').text()).toBe('Курс не найден или недоступен.')
    expect(wrapper.find('[data-testid=course-english-a1]').exists()).toBe(true)
  })

  it('shows a placeholder when there are no courses', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [] })

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=empty]').text()).toBe('Курсов пока нет. Загляните позже.')
  })

  it('shows a retryable error when loading fails', async () => {
    vi.mocked(api.courses.list)
      .mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'offline'))
      .mockResolvedValueOnce({ courses: [course()] })

    const { wrapper } = await render()
    expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')

    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid=course-english-a1]').exists()).toBe(true)
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    vi.mocked(api.courses.list).mockResolvedValue({
      courses: [course({ title: 'Ingliz tili A1', description: null }), course({ slug: 'a', is_active: true })],
    })

    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Kurslar')
    const first = wrapper.get('[data-testid=course-english-a1]')
    expect(first.get('[data-testid=meta]').text()).toBe("A1 · Umumiy ingliz tili · 5 ta so'z")
    expect(first.get('[data-testid=progress-text]').text()).toBe("5 tadan 2 tasi o'rganildi")
    expect(first.get('[data-testid=choose-btn]').text()).toBe('Tanlash')
    expect(wrapper.get('[data-testid=course-a] [data-testid=active-badge]').text()).toContain('Faol')
  })
})
