import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CoursesView from '../CoursesView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useToast } from '@/composables/useToast'
import type { Course, CourseDetail, CourseStage } from '@/api/types'

const tg = vi.hoisted(() => ({ showBack: vi.fn(), hideBack: vi.fn() }))

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
  useTelegram: () => ({ showBackButton: tg.showBack, webApp: { BackButton: { hide: tg.hideBack } } }),
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

async function render(query: Record<string, string> = {}) {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/decks', name: 'decks', component: stub },
    ],
  })
  await router.push({ name: 'courses', query })
  const wrapper = mount(CoursesView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

/** `GET /courses/{slug}` of a course: its modules (the grid under the tiles). */
const detailOf = (row: Course, stages: CourseStage[] = []): CourseDetail => ({
  slug: row.slug,
  title: row.title,
  description: row.description,
  level: row.level,
  exam_tag: row.exam_tag,
  words_count: row.words_count,
  is_active: row.is_active,
  progress: row.progress,
  stages,
})

const module = (number: number, title: string | null, lessons: [number, number][]): CourseStage => ({
  number,
  title,
  progress: { done: lessons.reduce((sum, [done]) => sum + done, 0), total: lessons.reduce((sum, [, total]) => sum + total, 0) },
  lessons: lessons.map(([done, total], index) => ({ id: number * 10 + index, number: index + 1, title: null, progress: { done, total } })),
})

/** Picks the tile of a course. */
async function pick(wrapper: Awaited<ReturnType<typeof render>>['wrapper'], slug: string): Promise<void> {
  await wrapper.get(`[data-testid=course-${slug}]`).trigger('click')
  await flushPromises()
}

/** Texts and tone classes of the chips inside a `meta` container. */
function chips(container: Pick<DOMWrapper<Element>, 'findAll'>) {
  return container.findAll('.chip').map((chip) => ({
    text: chip.text(),
    tone: chip.classes().find((c) => c.startsWith('chip-')),
  }))
}

describe('CoursesView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    useToast().hide()
    vi.mocked(api.courses.get).mockImplementation(async (slug: string) => detailOf(course({ slug })))
  })
  afterEach(() => {
    setLocale('ru')
    useToast().hide()
  })

  describe('heading and switch', () => {
    it('has a large "Курсы" heading, the Courses | My decks switch and the hint about the learner course', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

      const { wrapper } = await render()

      const heading = wrapper.get('h1')
      expect(heading.text()).toBe('Курсы')
      expect(heading.classes()).toContain('t-display-l')

      const courses = wrapper.get('[data-testid=seg-courses]')
      const decks = wrapper.get('[data-testid=seg-decks]')
      expect(courses.text()).toBe('Курсы')
      expect(courses.attributes('aria-current')).toBe('page')
      expect(decks.text()).toBe('Мои колоды')
      expect(decks.attributes('href')).toBe('/decks')
      expect(decks.attributes('aria-current')).toBeUndefined()

      expect(wrapper.get('[data-testid=hint]').text()).toBe('Каждый день новые слова — из вашего курса')
    })

    it('names the switch as a navigation landmark of its own, besides the tab bar', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

      const { wrapper } = await render()

      expect(wrapper.get('nav').attributes('aria-label')).toBe('Курсы')
      expect(wrapper.findAll('nav')).toHaveLength(1)
    })

    it('keeps the heading and the switch while loading, when the list is empty and when it failed', async () => {
      vi.mocked(api.courses.list).mockReturnValue(new Promise(() => {}))
      const loading = await render()
      expect(loading.wrapper.find('[data-testid=spinner]').exists()).toBe(true)
      expect(loading.wrapper.find('[data-testid=seg-decks]').exists()).toBe(true)

      vi.mocked(api.courses.list).mockResolvedValue({ courses: [] })
      const empty = await render()
      expect(empty.wrapper.find('[data-testid=seg-decks]').exists()).toBe(true)

      vi.mocked(api.courses.list).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'offline'))
      const failed = await render()
      expect(failed.wrapper.find('[data-testid=seg-decks]').exists()).toBe(true)
      expect(failed.wrapper.get('h1').text()).toBe('Курсы')
    })
  })

  describe('the grid of the courses (kit 12)', () => {
    it('draws a tile per course, three in a row: the level and its size; the learner course «идёт · N%» and picked (ink) at first', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [
          course({ slug: 'b1', level: 'B1', words_count: 1200 }),
          course({ is_active: true, progress: { done: 120, total: 1000 } }),
          course({ slug: 'plain', title: 'Деловой английский', level: null }),
        ],
      })

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=course-grid]').classes()).toContain('grid-cols-3')
      const tile = (slug: string) => wrapper.get(`[data-testid=course-${slug}]`)
      expect(tile('b1').get('[data-testid=tile-label]').text()).toBe('B1')
      expect(tile('b1').get('[data-testid=tile-caption]').text()).toBe('1200 слов')
      expect(tile('english-a1').get('[data-testid=tile-caption]').text()).toBe('идёт · 12%')
      // the name of a course without a level, smaller and on two lines at most
      expect(tile('plain').get('[data-testid=tile-label]').text()).toBe('Деловой английский')
      expect(tile('plain').get('[data-testid=tile-label]').classes()).toContain('line-clamp-2')
      // the learner course is picked at first: the ink tile, pressed
      expect(tile('english-a1').attributes('aria-pressed')).toBe('true')
      expect(tile('english-a1').classes()).toEqual(expect.arrayContaining(['bg-inverse', 'text-on-inverse']))
      expect(tile('b1').attributes('aria-pressed')).toBe('false')
      expect(tile('b1').classes()).toEqual(expect.arrayContaining(['bg-surface', 'border-hairline']))
    })

    it('picks the first course while the learner has none, and another one by a tap', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course(), course({ slug: 'b1', title: 'English B1', level: 'B1' })] })

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=course-english-a1]').attributes('aria-pressed')).toBe('true')

      await pick(wrapper, 'b1')

      expect(wrapper.get('[data-testid=course-b1]').attributes('aria-pressed')).toBe('true')
      expect(wrapper.get('[data-testid=course-english-a1]').attributes('aria-pressed')).toBe('false')
      expect(wrapper.get('[data-testid=course-title]').text()).toBe('English B1')
      expect(api.courses.get).toHaveBeenLastCalledWith('b1')
    })

    it('keeps to the kit: flat cards, no tg- classes, shadows, gradients or serif', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course({ is_active: true }), course({ slug: 'other', title: 'Other' })] })

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=selected-english-a1]').classes()).toContain('card')
      expect(wrapper.html()).not.toMatch(/tg-|shadow-|gradient|font-serif/)
    })
  })

  describe('the card of the picked course', () => {
    it('says what the course is: its name, chips, description and the words learned in ink', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [course(), course({ slug: 'ielts', title: 'IELTS', description: null, level: null, exam_tag: 'ielts', words_count: 1, progress: { done: 0, total: 1 } })],
      })

      const { wrapper } = await render()

      const first = wrapper.get('[data-testid=selected-english-a1]')
      expect(first.get('[data-testid=course-title]').text()).toBe('Английский A1')
      expect(first.get('[data-testid=course-title]').classes()).toContain('t-title-m')
      expect(first.text()).toContain('Первые слова')
      expect(chips(first.get('[data-testid=meta]'))).toEqual([
        { text: 'A1', tone: 'chip-neutral' },
        { text: 'Общий английский', tone: 'chip-neutral' },
        { text: '5 слов', tone: 'chip-neutral' },
      ])
      expect(first.get('[data-testid=progress-text]').text()).toBe('Изучено 2 из 5')
      expect(first.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('40')
      expect(first.get('[data-testid=progress-bar] .progress-fill').attributes('style')).toContain('width: 40%')

      await pick(wrapper, 'ielts')
      const second = wrapper.get('[data-testid=selected-ielts]')
      // No level, no description; the exam goal is the accent chip.
      expect(chips(second.get('[data-testid=meta]'))).toEqual([
        { text: 'IELTS', tone: 'chip-accent' },
        { text: '1 слово', tone: 'chip-neutral' },
      ])
      expect(second.find('[data-testid=description]').exists()).toBe(false)
    })

    it('shows the SAT goal as an accent chip and an unknown goal as is', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [
          course({ slug: 'sat', exam_tag: 'sat', level: null }),
          course({ slug: 'toefl', exam_tag: 'toefl', level: null }),
          course({ slug: 'plain', exam_tag: null, level: null }),
        ],
      })

      const { wrapper } = await render()

      expect(chips(wrapper.get('[data-testid=selected-sat] [data-testid=meta]'))[0]).toEqual({ text: 'SAT', tone: 'chip-accent' })
      await pick(wrapper, 'toefl')
      expect(chips(wrapper.get('[data-testid=selected-toefl] [data-testid=meta]'))[0]).toEqual({ text: 'toefl', tone: 'chip-neutral' })
      await pick(wrapper, 'plain')
      expect(chips(wrapper.get('[data-testid=selected-plain] [data-testid=meta]'))).toEqual([{ text: '5 слов', tone: 'chip-neutral' }])
    })

    it('clamps a long description to two lines', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course({ description: 'Очень длинное описание. '.repeat(20) })] })

      const { wrapper } = await render()

      const description = wrapper.get('[data-testid=description]')
      expect(description.classes()).toContain('line-clamp-2')
      expect(description.text()).toContain('Очень длинное описание.')
    })

    it('links the top of the card to the course page', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

      const { wrapper } = await render()

      const link = wrapper.get('[data-testid=course-link]')
      expect(link.element.tagName).toBe('A')
      expect(link.attributes('href')).toBe('/courses/english-a1')
      expect(link.text()).toContain('Английский A1')
      // The chevron is part of the link; the action below it is not.
      expect(link.find('svg').exists()).toBe(true)
      expect(link.find('[data-testid=choose-btn]').exists()).toBe(false)
    })
  })

  describe('the main course', () => {
    it('marks the learner course with a chip and "Открыть уроки"; another course offers "Перейти на этот курс" - one primary button', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [course({ is_active: true }), course({ slug: 'other', title: 'Other' })],
      })

      const { wrapper } = await render()

      const active = wrapper.get('[data-testid=selected-english-a1]')
      const badge = active.get('[data-testid=active-badge]')
      expect(badge.text()).toBe('Вы учите этот курс')
      expect(badge.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
      const open = active.get('[data-testid=open-lessons]')
      expect(open.attributes('href')).toBe('/courses/english-a1')
      expect(open.classes()).toContain('btn-primary')
      expect(active.find('[data-testid=choose-btn]').exists()).toBe(false)
      expect(wrapper.findAll('.btn-primary')).toHaveLength(1)

      await pick(wrapper, 'other')
      const choose = wrapper.get('[data-testid=selected-other] [data-testid=choose-btn]')
      expect(choose.text()).toBe('Перейти на этот курс')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-block']))
      expect(wrapper.find('[data-testid=active-badge]').exists()).toBe(false)
      expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    })

    it('offers "Начать курс" while the learner has none', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course(), course({ slug: 'other', title: 'Other' })] })

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Начать курс')
      await pick(wrapper, 'other')
      expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Начать курс')
    })

    it('selects a course, moves the mark to it and leads to Today with "Курс выбран"', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [course({ is_active: true }), course({ slug: 'other', title: 'Other', progress: { done: 1, total: 4 } })],
      })
      vi.mocked(api.courses.select).mockResolvedValue(course({ slug: 'other', title: 'Other', is_active: true, progress: { done: 1, total: 4 } }))

      const { wrapper, router } = await render()
      await pick(wrapper, 'other')
      await wrapper.get('[data-testid=selected-other] [data-testid=choose-btn]').trigger('click')
      await flushPromises()

      expect(api.courses.select).toHaveBeenCalledWith('other')
      expect(router.currentRoute.value.name).toBe('today')
      expect(useToast().toast.value?.text).toBe('Курс выбран')
      expect(wrapper.find('[data-testid=selected-other] [data-testid=active-badge]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=course-other] [data-testid=tile-caption]').text()).toBe('идёт · 25%')
      expect(wrapper.get('[data-testid=course-english-a1] [data-testid=tile-caption]').text()).toBe('5 слов')
    })

    it('disables the choose button while a selection is in flight', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [course({ is_active: true }), course({ slug: 'b', title: 'B' })],
      })
      let finish: (value: Course) => void = () => {}
      vi.mocked(api.courses.select).mockReturnValue(new Promise<Course>((resolve) => (finish = resolve)))

      const { wrapper } = await render()
      await pick(wrapper, 'b')
      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeUndefined()

      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeDefined()

      finish(course({ slug: 'b', title: 'B', is_active: true }))
      await flushPromises()
      expect(wrapper.find('[data-testid=selected-b] [data-testid=active-badge]').exists()).toBe(true)
    })

    it('keeps the list and shows the reason in an alert when selecting fails', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })
      vi.mocked(api.courses.select).mockRejectedValue(
        new ApiClientError('not_found', 404, 'course_not_found', 'Course not found.'),
      )

      const { wrapper, router } = await render()
      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('courses')
      expect(useToast().toast.value).toBeNull()
      const alert = wrapper.get('[data-testid=select-error]')
      expect(alert.text()).toBe('Курс не найден или недоступен.')
      expect(alert.attributes('role')).toBe('alert')
      expect(wrapper.find('[data-testid=course-english-a1]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeUndefined()
    })
  })

  describe('the modules of the picked course (kit 12)', () => {
    it('reads the modules of the picked course and draws them two in a row with the picture of the topic and the state', async () => {
      const a1 = course({ slug: 'english-a1-a2', level: 'A1–A2', is_active: true })
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [a1] })
      vi.mocked(api.courses.get).mockResolvedValue(detailOf(a1, [
        module(1, 'Знакомство и люди', [[5, 5], [5, 5]]),
        module(2, 'Еда и напитки', [[5, 5], [2, 5], [0, 5]]),
        module(3, 'Время, дни и числа', [[0, 25], [0, 25]]),
      ]))

      const { wrapper } = await render()

      expect(api.courses.get).toHaveBeenCalledWith('english-a1-a2')
      expect(wrapper.get('[data-testid=modules-title]').text()).toBe('A1–A2 · Модули')
      expect(wrapper.get('[data-testid=modules-count]').text()).toBe('2 / 3')
      expect(wrapper.get('[data-testid=modules] ol').classes()).toContain('grid-cols-2')

      const card = (n: number) => wrapper.get(`[data-testid=module-${n}]`)
      expect([1, 2, 3].map((n) => card(n).attributes('data-state'))).toEqual(['done', 'current', 'locked'])
      expect(card(1).get('[data-testid=topic-art]').attributes('src')).toBe('/assets/img/m1/topics/english-a1-a2/01-120.webp')
      expect(card(1).get('[data-testid=topic-art]').attributes('width')).toBe('76')
      expect(card(1).get('[data-testid=module-title]').text()).toBe('1. Знакомство и люди')
      expect(card(1).get('[data-testid=module-meta]').text()).toBe('Пройден')
      expect(card(1).get('[data-testid=module-meta]').classes()).toEqual(expect.arrayContaining(['text-success-ink', 'lowercase']))
      expect(card(2).get('a').classes()).toEqual(expect.arrayContaining(['border-2', 'border-strong']))
      expect(card(2).get('[data-testid=module-meta]').text()).toBe('урок 2 из 3')
      expect(card(3).get('[data-testid=topic-art]').classes()).toContain('opacity-45')
      expect(card(3).get('[data-testid=module-meta]').text()).toBe('50 слов')
      // a module opens the course screen
      expect(card(3).get('a').attributes('href')).toBe('/courses/english-a1-a2')
    })

    it('shows a plate with the number of a module in a course without pictures, and no "current" in another course', async () => {
      const centre = course({ slug: 'centre-course' })
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [centre] })
      vi.mocked(api.courses.get).mockResolvedValue(detailOf(centre, [module(1, null, [[1, 5]]), module(2, 'Food', [[0, 5]])]))

      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=topic-art]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=module-1] [data-testid=topic-art-fallback]').text()).toBe('1')
      expect(wrapper.get('[data-testid=module-1] [data-testid=module-title]').text()).toBe('Модуль 1')
      expect(wrapper.findAll('[data-state=current]')).toHaveLength(0)
      expect(wrapper.get('[data-testid=modules-count]').text()).toBe('0 / 2')
    })

    it('reads the modules of a course once, waits with a skeleton and offers a retry when they fail', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course(), course({ slug: 'b1', level: 'B1' })] })
      let fail: (reason: unknown) => void = () => {}
      vi.mocked(api.courses.get).mockReturnValueOnce(new Promise((_, reject) => (fail = reject)))

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=modules-loading]').attributes('role')).toBe('status')

      fail(new ApiClientError('network', 0, 'unknown', 'offline'))
      await flushPromises()
      expect(wrapper.get('[data-testid=modules-error]').text()).toContain('Нет связи с сервером')
      // the list itself stays: a failure of the modules is not a failure of the screen
      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)

      await wrapper.get('[data-testid=modules-retry]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-testid=modules-error]').exists()).toBe(false)

      await pick(wrapper, 'b1')
      await pick(wrapper, 'english-a1')
      expect(vi.mocked(api.courses.get).mock.calls.map(([slug]) => slug)).toEqual(['english-a1', 'english-a1', 'b1'])
    })
  })

  describe('states', () => {
    it('shows a skeleton while the list loads', async () => {
      vi.mocked(api.courses.list).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()

      const skeleton = wrapper.get('[data-testid=spinner]')
      expect(skeleton.attributes('role')).toBe('status')
      expect(skeleton.findAll('.skeleton').length).toBeGreaterThan(6)
      expect(wrapper.find('[data-testid=course-grid]').exists()).toBe(false)
    })

    it('shows an empty state with the mascot reading when there are no courses', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [] })

      const { wrapper } = await render()

      const empty = wrapper.get('[data-testid=empty]')
      expect(empty.text()).toBe('Курсов пока нет. Загляните позже.')
      expect(empty.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('reading')
    })

    it('shows a retryable error when loading fails', async () => {
      vi.mocked(api.courses.list)
        .mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'offline'))
        .mockResolvedValueOnce({ courses: [course()] })

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')

      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=course-english-a1]').exists()).toBe(true)
    })
  })

  describe('navigation', () => {
    it('is a tab root: the system Back button is hidden, never shown', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

      const { wrapper } = await render()

      expect(tg.hideBack).toHaveBeenCalledTimes(1)
      expect(tg.showBack).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid=back-link]').exists()).toBe(false)
    })

    it('opened by "All courses" of a course, the system Back returns to that course', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

      const { router } = await render({ from: 'ielts' })

      expect(tg.hideBack).not.toHaveBeenCalled()
      expect(tg.showBack).toHaveBeenCalledTimes(1)
      const press = tg.showBack.mock.calls[0]![0] as () => void
      press()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('course')
      expect(router.currentRoute.value.params.slug).toBe('ielts')
    })

    it('goes to My decks from the switch', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

      const { wrapper, router } = await render()
      await wrapper.get('[data-testid=seg-decks]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('decks')
    })
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    vi.mocked(api.courses.list).mockResolvedValue({
      courses: [course({ title: 'Ingliz tili A1', description: null }), course({ slug: 'a', is_active: true })],
    })

    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Kurslar')
    expect(wrapper.get('[data-testid=seg-decks]').text()).toBe("Mening to'plamlarim")
    expect(wrapper.get('[data-testid=hint]').text()).toBe("Har kuni yangi so'zlar — sizning kursingizdan")
    expect(wrapper.get('[data-testid=course-a] [data-testid=tile-caption]').text()).toBe("o'qilmoqda · 40%")
    expect(wrapper.get('[data-testid=selected-a] [data-testid=active-badge]').text()).toBe("Siz shu kursni o'rganyapsiz")
    expect(wrapper.get('[data-testid=modules-title]').text()).toBe('A1 · Modullar')
    await pick(wrapper, 'english-a1')
    const first = wrapper.get('[data-testid=selected-english-a1]')
    expect(chips(first.get('[data-testid=meta]')).map((c) => c.text)).toEqual(['A1', 'Umumiy ingliz tili', "5 ta so'z"])
    expect(first.get('[data-testid=progress-text]').text()).toBe("5 tadan 2 tasi o'rganildi")
    expect(first.get('[data-testid=choose-btn]').text()).toBe("Shu kursga o'tish")
  })
})
