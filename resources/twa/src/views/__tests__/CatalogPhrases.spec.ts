import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CourseView from '../CourseView.vue'
import CoursesView from '../CoursesView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { Course, CourseDetail } from '@/api/types'

// The section of phrases in the catalog and the screen of a course of phrases (docs/35 §3.2, §3.3 a; docs/04 A.3 `section`).

vi.mock('@/api', () => ({
  api: {
    courses: { list: vi.fn(), get: vi.fn(), select: vi.fn() },
    training: { open: vi.fn() },
    exam: { openMock: vi.fn() },
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: vi.fn(() => vi.fn()), webApp: { BackButton: { hide: vi.fn() } } }),
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

const phrases = (overrides: Partial<Course> = {}): Course =>
  course({ slug: 'english-phrases-a2b1', title: 'Разговорные фразы A2–B1', level: 'A2–B1', exam_tag: 'phrases', section: 'phrases', words_count: 300, ...overrides })

function router() {
  const stub = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/decks', name: 'decks', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
}

async function renderCatalog() {
  const r = router()
  await r.push({ name: 'courses' })
  const wrapper = mount(CoursesView, { global: { plugins: [r] } })
  await flushPromises()
  return wrapper
}

async function renderCourse(slug: string) {
  const wrapper = mount(CourseView, { props: { slug }, global: { plugins: [router()] } })
  await flushPromises()
  // the lessons of a module are under its disclosure (kit Muhrla 03; the current one starts open): every module opened
  for (const toggle of wrapper.findAll('[data-testid=module-toggle]')) {
    if (toggle.attributes('aria-expanded') !== 'true') await toggle.trigger('click')
  }
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  vi.mocked(api.me.get).mockRejectedValue(new Error('offline'))
})
afterEach(() => setLocale('ru'))

describe('CoursesView: the courses of phrases', () => {
  beforeEach(() => {
    vi.mocked(api.courses.get).mockImplementation(async (slug: string) => ({ ...course({ slug }), stages: [] }))
  })

  it('puts the tiles of phrases after the usual ones, named «Фразы» with their level; the card of a picked one says what they are', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({
      courses: [phrases(), course({ section: 'courses' }), course({ slug: 'english-b1', title: 'B1', level: 'B1', section: 'courses' })],
    })

    const wrapper = await renderCatalog()

    const tiles = wrapper.findAll('[data-testid=course-grid] > button').map((el) => el.attributes('data-testid'))
    expect(tiles).toEqual(['course-english-a1', 'course-english-b1', 'course-english-phrases-a2b1'])
    const tile = wrapper.get('[data-testid=course-english-phrases-a2b1]')
    expect(tile.get('[data-testid=tile-label]').text()).toBe('Фразы')
    expect(tile.get('[data-testid=tile-caption]').text()).toBe('A2–B1')
    // a usual course has no line about phrases
    expect(wrapper.find('[data-testid=phrases-hint]').exists()).toBe(false)
    const usual = wrapper.get('[data-testid=selected-english-a1]').findAll('[data-testid=meta] .chip').map((c) => c.text())
    expect(usual).toEqual(['A1', 'Общий английский', '5 слов'])

    await tile.trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=phrases-hint]').text()).toContain('идиомы')
    // The size of a course of phrases is in phrases; it has no goal chip (the tile says it).
    const chips = wrapper.get('[data-testid=selected-english-phrases-a2b1]').findAll('[data-testid=meta] .chip').map((c) => c.text())
    expect(chips).toEqual(['A2–B1', '300 фраз'])
    expect(wrapper.get('[data-testid=modules-title]').text()).toBe('Фразы · Модули')
  })

  it('a list without `section` (an older server) is drawn as before: no tile of phrases', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [course(), course({ slug: 'english-b1', title: 'B1' })] })

    const wrapper = await renderCatalog()

    expect(wrapper.findAll('[data-testid=tile-label]').map((el) => el.text())).toEqual(['A1', 'A1'])
    expect(wrapper.find('[data-testid=phrases-hint]').exists()).toBe(false)
  })

  it('a catalog of phrases only: the tile «Iboralar» and the Uzbek words', async () => {
    setLocale('uz')
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [phrases({ words_count: 1, level: null })] })

    const wrapper = await renderCatalog()

    expect(wrapper.get('[data-testid=course-english-phrases-a2b1] [data-testid=tile-label]').text()).toBe('Iboralar')
    expect(wrapper.get('[data-testid=course-english-phrases-a2b1] [data-testid=tile-caption]').text()).toBe('1 ta ibora')
    expect(wrapper.get('[data-testid=selected-english-phrases-a2b1]').text()).toContain('1 ta ibora')
  })
})

describe('CourseView of a course of phrases', () => {
  const detail = (overrides: Partial<CourseDetail> = {}): CourseDetail => ({
    slug: 'english-phrases-a2b1',
    title: 'Разговорные фразы A2–B1',
    description: null,
    level: 'A2–B1',
    exam_tag: 'phrases',
    section: 'phrases',
    words_count: 50,
    is_active: false,
    progress: { done: 3, total: 21 },
    stages: [
      {
        number: 1,
        title: 'Согласие и несогласие',
        progress: { done: 3, total: 21 },
        lessons: [
          { id: 11, number: 1, title: 'Да', progress: { done: 3, total: 10 } },
          { id: 12, number: 2, title: 'Нет', progress: { done: 0, total: 11 } },
        ],
      },
    ],
    ...overrides,
  })

  it('the same lessons, «N фраз» instead of «N слов»', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [] })
    vi.mocked(api.courses.get).mockResolvedValue(detail())

    const wrapper = await renderCourse('english-phrases-a2b1')

    expect(wrapper.get('[data-testid=progress-text]').text()).toBe('3 из 21 фразы')
    expect(wrapper.get('[data-testid=course-level]').text()).toBe('A2–B1')
    expect(wrapper.get('[data-testid=course-line]').text()).toBe('Разговорные фразы')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe('3 из 10 фраз')
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe('11 фраз')
    expect(wrapper.get('[data-testid=choose-hint]').text()).toBe('Новые фразы в ежедневном занятии будут из этого курса')
  })

  it('the current lesson of the active course of phrases: «5 новых фраз»', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [] })
    vi.mocked(api.courses.get).mockResolvedValue(detail({
      is_active: true,
      stages: [{ number: 1, title: null, progress: { done: 0, total: 5 }, lessons: [{ id: 11, number: 1, title: null, progress: { done: 0, total: 5 } }] }],
    }))

    const wrapper = await renderCourse('english-phrases-a2b1')

    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe('5 новых фраз · вы здесь')
  })

  it('a usual course keeps «слов»', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [] })
    vi.mocked(api.courses.get).mockResolvedValue(detail({ slug: 'english-a1', exam_tag: null, section: 'courses' }))

    const wrapper = await renderCourse('english-a1')

    expect(wrapper.get('[data-testid=progress-text]').text()).toBe('3 из 21 слова')
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe('11 слов')
  })
})
