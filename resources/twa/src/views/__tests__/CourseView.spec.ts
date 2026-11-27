import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CourseView from '../CourseView.vue'
import CoursesView from '../CoursesView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useToast } from '@/composables/useToast'
import { useCoursesStore } from '@/stores/courses'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import type { Course, CourseDetail, CourseLesson, CourseStage } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

const tg = vi.hoisted(() => ({
  handler: null as (() => void) | null,
  release: vi.fn(),
  showBack: vi.fn(),
  hideBack: vi.fn(),
}))

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
  useTelegram: () => ({ showBackButton: tg.showBack, webApp: { BackButton: { hide: tg.hideBack } } }),
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

const lesson = (id: number, number: number, done: number, total: number, title: string | null = null): CourseLesson => ({
  id,
  number,
  title,
  progress: { done, total },
})

const stage = (number: number, lessons: CourseLesson[]): CourseStage => ({
  number,
  title: `Module ${number}`,
  progress: {
    done: lessons.reduce((sum, l) => sum + l.progress.done, 0),
    total: lessons.reduce((sum, l) => sum + l.progress.total, 0),
  },
  lessons,
})

/** `expand`: every module opened (the lessons of a module are under its disclosure, kit Muhrla 03); off - the screen as it opens. */
async function render(slug = 'english-a1', attach = false, expand = true) {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
      { path: '/exam/:sessionId(\\d+)', name: 'exam', component: stub },
    ],
  })
  // `attach`: in the document, for what needs real focus.
  const wrapper = mount(CourseView, { props: { slug }, global: { plugins: [router] }, ...(attach ? { attachTo: document.body } : {}) })
  await flushPromises()
  if (expand) {
    for (const toggle of wrapper.findAll('[data-testid=module-toggle]')) {
      if (toggle.attributes('aria-expanded') !== 'true') await toggle.trigger('click')
    }
  }
  return { wrapper, router }
}

function sessionAnswer(id: number) {
  return { session_id: id, mode: 'lesson' as const, lesson: { stage: 1, lesson: 2, title: null }, total_words: 2 }
}

describe('CourseView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(api.courses.list).mockReset()
    useToast().hide()
    tg.handler = null
    tg.showBack.mockImplementation((handler: () => void) => {
      tg.handler = handler
      return tg.release
    })
  })
  afterEach(() => {
    useToast().hide()
    setLocale('ru')
  })

  describe('heading', () => {
    it('loads the course by slug: the name as the heading (no brand row, kit 03), the card with the level, the goal and the words', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ description: 'Первые слова', exam_tag: 'general' }))

      const { wrapper } = await render()

      expect(api.courses.get).toHaveBeenCalledWith('english-a1')
      expect(wrapper.find('[data-testid=brand-row]').exists()).toBe(false)
      const heading = wrapper.get('[data-testid=title] h1')
      expect(heading.text()).toBe('Английский A1')
      // the name of another course may be long: Display/M
      expect(heading.classes()).toContain('t-display-m')
      // Another course: the heading is its name, the card says its level (an ink chip) and its goal.
      const card = wrapper.get('[data-testid=course-card]')
      expect(card.classes()).toEqual(expect.arrayContaining(['rounded-lg', 'border-hairline', 'bg-surface']))
      const level = card.get('[data-testid=course-level]')
      expect(level.text()).toBe('A1')
      expect(level.attributes('data-tone')).toBe('ink')
      expect(card.get('[data-testid=course-line]').text()).toBe('Общий английский')
      expect(card.get('[data-testid=progress-text]').text()).toBe('2 из 5 слов')
      expect(card.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('reading')
      const bar = card.get('[data-testid=progress-bar]')
      expect(bar.attributes('aria-valuenow')).toBe('40')
      expect(bar.attributes('aria-label')).toBe('Изучено 2 из 5')
      // the line of the kit: the fill in ink
      expect(bar.get('.progress-fill').classes()).toContain('progress-primary')
      expect(bar.get('.progress-fill').attributes('style')).toContain('width: 40%')
      expect(wrapper.get('[data-testid=description]').text()).toBe('Первые слова')
    })

    it('calls the learner course "Мой курс": the card names it, «Модуль N из M · X из Y слов», the numbers are the server ones', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        description: 'Первые слова',
        title: 'Основные слова',
        level: 'A1–A2',
        progress: { done: 120, total: 1000 },
      }))

      const { wrapper } = await render()

      const heading = wrapper.get('[data-testid=title] h1')
      expect(heading.text()).toBe('Мой курс')
      expect(heading.classes()).toContain('t-display-l')
      expect(wrapper.get('[data-testid=course-level]').text()).toBe('A1–A2')
      expect(wrapper.get('[data-testid=course-line]').text()).toBe('Основные слова')
      // the current lesson is in module 1 of 2
      expect(wrapper.get('[data-testid=progress-text]').text()).toBe('Модуль 1 из 2 · 120 из 1000 слов')
      expect(wrapper.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('12')
      // the layout of the learner's course has no description and no "you take this course" chip: the heading says it
      expect(wrapper.find('[data-testid=description]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=active-badge]').exists()).toBe(false)
    })

    it('shows the exam goal of IELTS / SAT on the card', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', level: 'B2', words_count: 1200 }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=course-level]').text()).toBe('B2')
      expect(wrapper.get('[data-testid=course-line]').text()).toBe('IELTS')
    })

    it('leaves out the line, the description and the level when the course has none', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ description: null, level: null }))

      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=course-line]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=course-level]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=description]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=progress-text]').text()).toBe('2 из 5 слов')
    })

    it.each([
      [1, '0 из 1 слова'],
      [3, '0 из 3 слов'],
      [21, '0 из 21 слова'],
      [0, '0 из 0 слов'],
    ])('agrees «слов» with the size of the course: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ progress: { done: 0, total } }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=progress-text]').text()).toBe(expected)
      expect(wrapper.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('0')
    })

    it('leads to the list of all courses (and "Мои колоды" from it) by "Все курсы"', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true }))

      const { wrapper, router } = await render()
      const link = wrapper.get('[data-testid=all-courses]')
      expect(link.text()).toBe('Все курсы')
      // the accent of text with a chevron (kit 03)
      expect(link.classes()).toContain('text-accent')
      expect(link.find('svg').exists()).toBe(true)
      // the catalog then leads "Back" to this course
      expect(link.attributes('href')).toBe('/courses?from=english-a1')

      await link.trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('courses')
    })
  })
  describe('main action in a course that is not the main one', () => {
    it('offers "Начать курс" as the primary button with the explanation while the learner has no course', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      const choose = wrapper.get('[data-testid=choose-btn]')
      expect(choose.text()).toBe('Начать курс')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      expect(wrapper.get('[data-testid=choose-hint]').text()).toBe('Новые слова в ежедневном занятии будут из этого курса')
      expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
    })

    it('offers "Перейти на этот курс" when another course is active', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [{
          slug: 'other', title: 'Other', description: null, level: null, exam_tag: null,
          words_count: 1, is_featured: false, is_active: true, progress: { done: 0, total: 1 },
        } satisfies Course],
      })

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Перейти на этот курс')
    })

    it('has at most one primary button, whichever the course: "choose" in another one, the card of the current module in the own one', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
      const notMain = await render()
      expect(notMain.wrapper.findAll('.btn-primary')).toHaveLength(1)

      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', is_active: true }))
      const main = await render()
      expect(main.wrapper.findAll('.btn-primary')).toHaveLength(0)
      expect(main.wrapper.findAll('[data-testid=continue-btn]')).toHaveLength(1)
    })

    it('selects the course and leads to Today with "Курс выбран"', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.courses.select).mockResolvedValue({
        slug: 'english-a1', title: 'Английский A1', description: null, level: 'A1', exam_tag: null,
        words_count: 6, is_featured: false, is_active: true, progress: { done: 2, total: 5 },
      } satisfies Course)

      const { wrapper, router } = await render()
      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      await flushPromises()

      expect(api.courses.select).toHaveBeenCalledWith('english-a1')
      expect(router.currentRoute.value.name).toBe('today')
      expect(useToast().toast.value?.text).toBe('Курс выбран')
      expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
      // the course is the learner's now: "Мой курс", and its current lesson has the primary button
      expect(wrapper.get('[data-testid=title] h1').text()).toBe('Мой курс')
      expect(wrapper.get('[data-testid=stage-1] [data-testid=continue-btn]').text()).toContain('Продолжить')
    })

    it('disables the button while the selection is in flight', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.courses.select).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeUndefined()

      await wrapper.get('[data-testid=choose-btn]').trigger('click')

      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeDefined()
    })

    it('explains in an alert why the course could not be made the main one', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.courses.select).mockRejectedValue(
        new ApiClientError('not_found', 404, 'course_not_found', 'Course not found.'),
      )

      const { wrapper, router } = await render()
      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      await flushPromises()

      const alert = wrapper.get('[data-testid=select-error]')
      expect(alert.text()).toBe('Курс не найден или недоступен.')
      expect(alert.attributes('role')).toBe('alert')
      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeUndefined()
      expect(router.currentRoute.value.name).not.toBe('today')
      expect(useToast().toast.value).toBeNull()
    })
  })

  describe('the current module of the main course (kit 03)', () => {
    it('makes the module of the first unfinished lesson the card in an ink frame: «урок N из M · вы здесь», an arrow, the lesson on a press', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3, 'Greetings'), lesson(12, 2, 1, 4, 'Food'), lesson(13, 3, 0, 4)])],
      }))

      const { wrapper } = await render('english-a1', false, false)

      const module = wrapper.get('[data-testid=stage-1]')
      expect(module.attributes('data-state')).toBe('current')
      const card = module.get('[data-testid=module-current]')
      expect(card.classes()).toEqual(expect.arrayContaining(['border-2', 'border-strong', 'rounded-lg']))
      expect(card.get('[data-testid=module-title]').text()).toBe('1. Module 1')
      expect(card.get('[data-testid=module-meta]').text()).toBe('урок 2 из 3 · вы здесь')
      // the ink circle with the number of the current lesson on the picture
      const badge = card.get('[data-testid=module-badge]')
      expect(badge.text()).toBe('2')
      expect(badge.classes()).toEqual(expect.arrayContaining(['bg-inverse', 'text-on-inverse']))
      // the card is the button of the current lesson: it says what it does to a screen reader, an arrow for the eye
      const next = card.get('[data-testid=continue-btn]')
      expect(next.element.tagName).toBe('BUTTON')
      expect(next.get('[data-testid=continue-label]').text()).toBe('Продолжить')
      expect(next.get('[data-testid=continue-label]').classes()).not.toContain('sr-only')
      expect(next.find('svg').exists()).toBe(true)
      // the mock-up of the screen has no ink button: the card is the main action
      expect(wrapper.find('.btn-primary').exists()).toBe(false)
      expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=choose-hint]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
      // its lessons are under the disclosure of the card, open at first: the current lesson and its words are seen without a tap
      const toggle = card.get('[data-testid=module-toggle]')
      expect(toggle.attributes('aria-expanded')).toBe('true')
      expect(toggle.attributes('aria-controls')).toBe('module-lessons-1')
      expect(wrapper.find('#module-lessons-1').exists()).toBe(true)
      expect(toggle.text()).toBe('Пройдено 1 из 3 уроков')
      expect(wrapper.get('[data-testid=lesson-12]').attributes('data-state')).toBe('current')
      expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe('1 из 4 слов · вы здесь')
      expect(wrapper.get('[data-testid=lesson-13]').attributes('data-state')).toBe('new')
      // the learner may close it; then nothing points at the list that is gone
      await toggle.trigger('click')
      expect(toggle.attributes('aria-expanded')).toBe('false')
      expect(toggle.attributes('aria-controls')).toBeUndefined()
      expect(wrapper.find('[data-testid=lesson-12]').exists()).toBe(false)
    })

    it('takes the number on the badge and «урок N из M» from one place: the order of the lesson in its module', async () => {
      // a module whose lessons go on numbering from the module before: the third lesson in it is «Урок 13»
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 11, 3, 3), lesson(12, 12, 4, 4), lesson(13, 13, 0, 4)])],
      }))

      const { wrapper } = await render('english-a1', false, false)

      const card = wrapper.get('[data-testid=module-current]')
      expect(card.get('[data-testid=module-meta]').text()).toBe('урок 3 из 3 · вы здесь')
      expect(card.get('[data-testid=module-badge]').text()).toBe('3')
    })

    it('shows a course already in the store at once and still reads it once, as before (no skeleton, no second request)', async () => {
      const fresh = detail({ is_active: true, title: 'Свежий', stages: [stage(1, [lesson(11, 1, 1, 3)])] })
      let answer: (value: CourseDetail) => void = () => {}
      vi.mocked(api.courses.get).mockReturnValue(new Promise((resolve) => { answer = resolve }))
      useCoursesStore().remember(detail({ is_active: true, title: 'Из кеша', stages: [stage(1, [lesson(11, 1, 0, 3)])] }))

      const { wrapper } = await render('english-a1', false, false)

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(false)
      expect(wrapper.text()).toContain('Из кеша')
      expect(wrapper.get('[data-testid=continue-label]').text()).toBe('Начать урок')
      answer(fresh)
      await flushPromises()
      expect(wrapper.get('[data-testid=continue-label]').text()).toBe('Продолжить')
      expect(api.courses.get).toHaveBeenCalledTimes(1)
      expect(useCoursesStore().cached['english-a1']?.title).toBe('Свежий')
    })

    it('All courses -> the course: the course screen adds its one read as always, shown at once; back to All courses adds none', async () => {
      const row: Course = { slug: 'english-a1', title: 'Английский A1', description: null, level: 'A1', exam_tag: null, words_count: 6, is_featured: false, is_active: true, progress: { done: 2, total: 5 } }
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [row] })
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true }))
      const stub = { template: '<div />' }
      const router = createRouter({
        history: createMemoryHistory(),
        routes: ['today', 'courses', 'course', 'decks', 'training'].map((name) => ({ path: name === 'course' ? '/courses/:slug' : `/${name}`, name, component: stub })),
      })
      await router.push('/courses')

      const catalog = mount(CoursesView, { global: { plugins: [router] } })
      await flushPromises()
      expect(api.courses.get).toHaveBeenCalledTimes(1)
      catalog.unmount()

      const course = mount(CourseView, { props: { slug: 'english-a1' }, global: { plugins: [router] } })
      expect(course.find('[data-testid=spinner]').exists()).toBe(false)
      await flushPromises()
      expect(api.courses.get).toHaveBeenCalledTimes(2)
      course.unmount()

      mount(CoursesView, { global: { plugins: [router] } })
      await flushPromises()
      expect(api.courses.get).toHaveBeenCalledTimes(2)
    })

    it('keeps the course of the store on the screen when its refresh fails, without the error screen', async () => {
      vi.mocked(api.courses.get).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      useCoursesStore().remember(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 0, 3)])] }))

      const { wrapper } = await render('english-a1', false, false)

      expect(wrapper.find('[data-testid=module-current]').exists()).toBe(true)
      expect(useCoursesStore().detailStatus).toBe('ready')
    })

    it('says "Начать урок" to a screen reader while the current lesson is not begun', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 0, 5, 'Напитки')])],
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=continue-label]').text()).toBe('Начать урок')
      expect(wrapper.get('[data-testid=continue-label]').isVisible()).toBe(true)
      expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe('5 новых слов · вы здесь')
    })

    it.each([
      [1, '1 новое слово'],
      [3, '3 новых слова'],
      [5, '5 новых слов'],
      [21, '21 новое слово'],
    ])('agrees «новых слов» with the size of the current lesson: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 0, total)])] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe(`${expected} · вы здесь`)
    })

    it('opens that lesson as a personal session and goes to the training screen', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 1, 4)])],
      }))
      vi.mocked(api.training.open).mockResolvedValue(sessionAnswer(78))

      const { wrapper, router } = await render()
      await wrapper.get('[data-testid=continue-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 12, source: 'picker' })
      expect(router.currentRoute.value.name).toBe('training')
      expect(router.currentRoute.value.params.sessionId).toBe('78')
    })

    it('goes through the modules in order and skips learned and empty lessons', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [
          stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 0, 0)]),
          stage(2, [lesson(21, 1, 5, 5), lesson(22, 2, 0, 5), lesson(23, 3, 1, 5)]),
        ],
      }))
      vi.mocked(api.training.open).mockResolvedValue(sessionAnswer(79))

      const { wrapper } = await render()

      // Every module is on the screen; the current lesson is lesson 22 of module 2.
      expect(wrapper.get('[data-testid=stage-1]').attributes('data-state')).toBe('done')
      expect(wrapper.get('[data-testid=stage-2]').attributes('data-state')).toBe('current')
      expect(wrapper.findAll('[data-testid=continue-btn]')).toHaveLength(1)
      expect(wrapper.get('[data-testid=progress-text]').text()).toContain('Модуль 2 из 2')
      await wrapper.get('[data-testid=continue-btn]').trigger('click')
      await flushPromises()
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 22, source: 'picker' })
    })

    it('says that all lessons are learned instead of a current module', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 0, 0)]), stage(2, [lesson(21, 1, 5, 5)])],
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=all-done]').text()).toBe('Все уроки курса пройдены')
      expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
      expect(wrapper.find('.btn-primary').exists()).toBe(false)
      expect(wrapper.find('[data-state=current]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=progress-text]').text()).toContain('Модуль 2 из 2')
    })

    it('shows neither the button nor "all learned" in a course without lessons: the mascot reading and a line', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [], progress: { done: 0, total: 0 } }))

      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
      const empty = wrapper.get('[data-testid=no-lessons]')
      expect(empty.text()).toBe('В курсе пока нет уроков.')
      expect(empty.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('reading')
    })

    it('disables the card of the current lesson while a session is being opened', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 0, 3), lesson(12, 2, 0, 3)])] }))
      vi.mocked(api.training.open).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=continue-btn]').attributes('disabled')).toBeUndefined()

      await wrapper.get('[data-testid=continue-btn]').trigger('click')

      expect(wrapper.get('[data-testid=continue-btn]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-testid=learn-btn]').attributes('disabled')).toBeDefined()
    })

    it('explains in a toast why a session could not be opened from the current lesson', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true }))
      vi.mocked(api.training.open).mockRejectedValue(
        new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
      )

      const { wrapper } = await render()
      await wrapper.get('[data-testid=continue-btn]').trigger('click')
      await flushPromises()

      expect(useToast().toast.value?.text).toBe('Урок не найден.')
      expect(wrapper.find('[data-testid=open-error]').exists()).toBe(false)
    })
  })

  describe('opens on the current module', () => {
    /** The learner's course of three modules; the current lesson (31) is in the last one, far down a long list. */
    const longCourse = (active = true) => detail({
      is_active: active,
      stages: [
        stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 3, 3)]),
        stage(2, [lesson(21, 1, 2, 2), lesson(22, 2, 4, 4)]),
        stage(3, [lesson(31, 1, 1, 4), lesson(32, 2, 0, 4)]),
      ],
    })

    let scroll: ReturnType<typeof vi.fn>
    beforeEach(() => {
      scroll = vi.fn()
      Element.prototype.scrollIntoView = scroll as unknown as Element['scrollIntoView']
    })
    afterEach(() => {
      Reflect.deleteProperty(Element.prototype, 'scrollIntoView')
    })

    it('scrolls the card of the current module into the middle of the screen once the course is shown', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(longCourse())

      const { wrapper } = await render('english-a1', false, false)

      expect(scroll).toHaveBeenCalledTimes(1)
      expect(scroll.mock.contexts[0]).toBe(wrapper.get('[data-testid=stage-3] [data-testid=module-current]').element)
      expect(scroll).toHaveBeenCalledWith({ block: 'center' })
    })

    it('does not scroll in a course the learner does not take, nor when every lesson is learned', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(longCourse(false))
      await render()

      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 3, 3)])] }))
      await render()

      expect(scroll).not.toHaveBeenCalled()
    })

    it('leaves the scroll to the router when the learner comes back to the screen', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(longCourse())
      const stub = { template: '<div />' }
      const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/courses', name: 'courses', component: stub }] })
      // "Back" from a screen after this one: the history knows where the learner went from here
      Object.defineProperty(router.options.history, 'state', { value: { forward: '/practice/english-a1' } })

      mount(CourseView, { props: { slug: 'english-a1' }, global: { plugins: [router] } })
      await flushPromises()

      expect(scroll).not.toHaveBeenCalled()
    })
  })

  describe('the path of the modules (kit 03)', () => {
    it('draws every module as a row with the picture of its topic and its state: done, current, after it locked', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        slug: 'english-a1-a2',
        is_active: true,
        stages: [
          { ...stage(1, [lesson(11, 1, 25, 25), lesson(12, 2, 25, 25)]), title: 'Знакомство и люди' },
          { ...stage(2, [lesson(21, 1, 5, 5), lesson(22, 2, 0, 5), lesson(23, 3, 0, 5)]), title: 'Еда и напитки' },
          { ...stage(3, [lesson(31, 1, 0, 50)]), title: 'Время, дни и числа' },
          { ...stage(4, [lesson(41, 1, 0, 50)]), title: 'Город' },
        ],
      }))

      const { wrapper } = await render('english-a1-a2', false, false)

      expect(wrapper.get('[data-testid=lessons] h2').text()).toBe('Модули')
      const row = (n: number) => wrapper.get(`[data-testid=stage-${n}]`)
      expect([1, 2, 3, 4].map((n) => row(n).attributes('data-state'))).toEqual(['done', 'current', 'locked', 'locked'])

      // the picture of the topic of each module, 64 px
      const art = row(1).get('[data-testid=topic-art]')
      expect(art.attributes('src')).toBe('/assets/img/m1/topics/english-a1-a2/01-120.webp')
      expect(art.attributes('width')).toBe('64')

      // done: the muhr circle with a check, «50 слов · повторить» in the accent of text
      expect(row(1).get('[data-testid=module-title]').text()).toBe('1. Знакомство и люди')
      expect(row(1).get('[data-testid=module-badge]').classes()).toContain('bg-muhr')
      expect(row(1).get('[data-testid=module-meta]').text()).toBe('50 слов · повторить')
      expect(row(1).get('[data-testid=module-meta]').classes()).toContain('text-accent')

      // current: «урок 2 из 3 · вы здесь»
      expect(row(2).get('[data-testid=module-meta]').text()).toBe('урок 2 из 3 · вы здесь')

      // after it: a lock, the picture dimmed, «после модуля 2» for the next one, its size for the rest
      expect(row(3).find('[data-testid=module-badge] svg').exists()).toBe(true)
      expect(row(3).get('[data-testid=topic-art]').classes()).toContain('opacity-45')
      expect(row(3).get('[data-testid=module-meta]').text()).toBe('после модуля 2')
      expect(row(4).get('[data-testid=module-meta]').text()).toBe('50 слов')
    })

    it('shows a plate with the number of the module in a course without pictures', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render('english-a1', false, false)

      expect(wrapper.find('[data-testid=topic-art]').exists()).toBe(false)
      const plate = wrapper.get('[data-testid=stage-1] [data-testid=topic-art-fallback] .icon-tile')
      expect(plate.text()).toBe('1')
    })

    it('opens the lessons of a module under it and closes them again (a disclosure); the lock does not shut them', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 1, 3)]), stage(2, [lesson(21, 1, 0, 3), lesson(22, 2, 0, 3)])],
      }))
      vi.mocked(api.training.open).mockResolvedValue(sessionAnswer(81))

      const { wrapper } = await render('english-a1', false, false)

      const toggle = wrapper.get('[data-testid=stage-2] [data-testid=module-toggle]')
      expect(toggle.attributes('aria-expanded')).toBe('false')
      // closed: no list on the page, so nothing to point at
      expect(toggle.attributes('aria-controls')).toBeUndefined()
      expect(wrapper.find('#module-lessons-2').exists()).toBe(false)
      await toggle.trigger('click')
      expect(toggle.attributes('aria-expanded')).toBe('true')
      expect(toggle.attributes('aria-controls')).toBe('module-lessons-2')
      expect(wrapper.get('#module-lessons-2').findAll('li[data-state]').map((row) => row.attributes('data-testid'))).toEqual(['lesson-21', 'lesson-22'])

      await wrapper.get('[data-testid=lesson-22] [data-testid=learn-btn]').trigger('click')
      await flushPromises()
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 22, source: 'picker' })

      await toggle.trigger('click')
      expect(wrapper.find('#module-lessons-2').exists()).toBe(false)
    })

    it.each([
      [1, 0, '0 из 1 урока'],
      [2, 1, '1 из 2 уроков'],
    ])('says how many lessons of a begun module are learned: %i lessons, %i learned -> %s', async (count, learned, expected) => {
      const lessons = Array.from({ length: count }, (_, i) => lesson(100 + i, i + 1, i < learned ? 2 : 1, 2))
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, lessons)] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=module-meta]').text()).toBe(expected)
    })

    it('names a module without a title by its number, and treats a blank title like none', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [{ ...stage(2, [lesson(21, 1, 0, 3)]), title: null }, { ...stage(3, []), title: '   ' }],
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=stage-2] [data-testid=module-title]').text()).toBe('Модуль 2')
      expect(wrapper.get('[data-testid=stage-3] [data-testid=module-title]').text()).toBe('Модуль 3')
      expect(wrapper.text()).not.toContain('Stage')
      expect(wrapper.text()).not.toContain('null')
    })

    it('names a lesson "Урок N · title", and "Урок N" without a title', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-title]').text()).toBe('Урок 1 · Greetings')
      expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-title]').text()).toBe('Урок 2')
    })

    it.each([
      [2, '1 из 2 слов'],
      [3, '1 из 3 слов'],
      [5, '1 из 5 слов'],
      [11, '1 из 11 слов'],
      [21, '1 из 21 слова'],
      [22, '1 из 22 слов'],
    ])('agrees «слов» with the size of a begun lesson: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 1, total)])] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe(expected)
    })

    it.each([
      [1, '1 слово'],
      [3, '3 слова'],
      [10, '10 слов'],
    ])('names the size of a lesson not begun: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 0, total)])] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe(expected)
    })

    it('marks the status of a lesson: learned ("повторить"), started, not started', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 1, 3), lesson(13, 3, 0, 3), lesson(14, 4, 0, 0)])],
      }))

      const { wrapper } = await render()

      const row = (id: number) => wrapper.get(`[data-testid=lesson-${id}]`)

      // The lessons of a module are the rows of one list of the kit (min 60 px).
      expect(wrapper.get('[data-testid=module-lessons]').classes()).toContain('list')
      for (const id of [11, 12, 13, 14]) expect(row(id).classes()).toContain('min-h-[60px]')

      // Learned: a green circle with a tick, named for a screen reader; "повторить" at the end.
      expect(row(11).attributes('data-state')).toBe('done')
      const done = row(11).get('[data-testid=lesson-status]')
      expect(done.attributes('role')).toBe('img')
      expect(done.attributes('aria-label')).toBe('Пройден')
      expect(done.classes()).toContain('bg-success')
      expect(done.find('[data-testid=lesson-done]').exists()).toBe(true)
      expect(row(11).get('[data-testid=lesson-hint]').text()).toBe('повторить')

      // Started (not the learner's course: nothing is "current" here): a ring in ink and the words learned.
      expect(row(12).attributes('data-state')).toBe('started')
      expect(row(12).get('[data-testid=lesson-status]').classes()).toContain('border-strong')
      expect(row(12).get('[data-testid=lesson-hint]').text()).toBe('1 из 3 слов')
      expect(row(12).find('[data-testid=lesson-done]').exists()).toBe(false)

      // Not started (and an empty lesson): a quiet ring.
      for (const id of [13, 14]) {
        expect(row(id).attributes('data-state')).toBe('new')
        expect(row(id).get('[data-testid=lesson-status]').classes()).toContain('border-hairline')
        expect(row(id).get('[data-testid=lesson-status]').attributes('aria-hidden')).toBe('true')
        expect(row(id).find('[data-testid=lesson-done]').exists()).toBe(false)
      }
      expect(wrapper.find('[data-state=current]').exists()).toBe(false)
    })

    it('says "после урока N" on the lesson right after the current one, also across a module, and opens it as before', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [
          stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 0, 3), lesson(13, 3, 0, 3)]),
          stage(2, [lesson(21, 1, 0, 3)]),
        ],
      }))
      vi.mocked(api.training.open).mockResolvedValue(sessionAnswer(80))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-13] [data-testid=lesson-hint]').text()).toBe('после урока 2')
      expect(wrapper.get('[data-testid=lesson-21] [data-testid=lesson-hint]').text()).toBe('3 слова')
      // The order of the lessons is a hint, not a lock: the server lets any lesson be learned.
      const next = wrapper.get('[data-testid=lesson-13] [data-testid=learn-btn]')
      expect(next.attributes('disabled')).toBeUndefined()
      await next.trigger('click')
      await flushPromises()
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 13, source: 'picker' })

      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 0, 3)]), stage(2, [lesson(21, 1, 0, 3)])],
      }))
      const across = await render()
      expect(across.wrapper.get('[data-testid=lesson-21] [data-testid=lesson-hint]').text()).toBe('после урока 2')
    })

    it('makes the whole row one button that opens the lesson: its title and its caption are its name', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [stage(1, [lesson(11, 1, 2, 3, 'Greetings'), lesson(12, 2, 0, 0), lesson(13, 3, 3, 3)])],
      }))

      const { wrapper } = await render()

      const learn = wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]')
      expect(learn.text()).toBe('Урок 1 · Greetings2 из 3 слов')
      expect(learn.find('[data-testid=lesson-title]').exists()).toBe(true)
      expect(learn.find('[data-testid=lesson-hint]').exists()).toBe(true)
      // its layer covers the row (the row is `relative`)
      expect(learn.classes()).toEqual(expect.arrayContaining(['after:absolute', 'after:inset-0']))
      expect(wrapper.get('[data-testid=lesson-11]').classes()).toContain('relative')
      expect(learn.attributes('disabled')).toBeUndefined()
      // A learned lesson can be taken again ("повторить").
      expect(wrapper.get('[data-testid=lesson-13]').get('[data-testid=learn-btn]').attributes('disabled')).toBeUndefined()
      // An empty lesson has nothing to learn.
      expect(wrapper.get('[data-testid=lesson-12]').get('[data-testid=learn-btn]').attributes('disabled')).toBeDefined()
    })

    it('repeats a learned lesson as a personal lesson session, as before', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 3, 3)])] }))
      vi.mocked(api.training.open).mockResolvedValue(sessionAnswer(81))

      const { wrapper, router } = await render()
      await wrapper.get('[data-testid=lesson-11] [data-testid=learn-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 11, source: 'picker' })
      expect(router.currentRoute.value.name).toBe('training')
    })

    it('opens a personal lesson session from the list and goes to the training screen', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.training.open).mockResolvedValue(sessionAnswer(77))

      const { wrapper, router } = await render()
      await wrapper.get('[data-testid=lesson-12]').get('[data-testid=learn-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 12, source: 'picker' })
      expect(router.currentRoute.value.name).toBe('training')
      expect(router.currentRoute.value.params.sessionId).toBe('77')
    })

    it('disables the buttons of all lessons while a session is being opened', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.training.open).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()
      await wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').trigger('click')

      expect(wrapper.findAll('[data-testid=learn-btn]').map((b) => b.attributes('disabled') !== undefined)).toEqual([true, true])
    })

    it('stays on the course and explains in a toast why a lesson could not be opened: the row is far from the top of the page', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.training.open).mockRejectedValue(
        new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
      )

      const { wrapper, router } = await render()
      await router.push('/courses')
      await wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('courses')
      expect(useToast().toast.value?.text).toBe('Урок не найден.')
      expect(wrapper.find('[data-testid=open-error]').exists()).toBe(false)
      expect(wrapper.find('[role=alert]').exists()).toBe(false)
    })

    it('tells the refusal once and leaves nothing in the training store for the next screen', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      vi.mocked(api.training.open).mockRejectedValue(
        new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
      )

      const { wrapper } = await render()
      await wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').trigger('click')
      await flushPromises()
      expect(useTrainingStore().openError).toBe('')

      // the same refusal again is news again
      useToast().hide()
      await wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').trigger('click')
      await flushPromises()
      expect(useToast().toast.value?.text).toBe('Урок не найден.')

      wrapper.unmount()
      expect(useTrainingStore().openError).toBe('')
    })

    it('forgets an open error that was left by another screen when the learner leaves this one', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())
      const { wrapper } = await render()
      useTrainingStore().openError = 'left over'
      wrapper.unmount()

      expect(useTrainingStore().openError).toBe('')
    })

    it('keeps to the new style: no legacy tg- classes, shadows or gradients, no serif', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, exam_tag: 'ielts' }))

      const { wrapper } = await render()

      expect(wrapper.html()).not.toMatch(/tg-|shadow-|gradient|font-serif/)
    })
  })
  describe('states', () => {
    it('shows a skeleton while the course loads', async () => {
      vi.mocked(api.courses.get).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=spinner]').attributes('role')).toBe('status')
      expect(wrapper.find('[data-testid=title]').exists()).toBe(false)
    })

    it('shows a placeholder for a course without lessons', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [], progress: { done: 0, total: 0 } }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=no-lessons]').text()).toBe('В курсе пока нет уроков.')
      expect(wrapper.find('[data-testid=stage-1]').exists()).toBe(false)
    })

    it('explains an unavailable course and retries', async () => {
      vi.mocked(api.courses.get)
        .mockRejectedValueOnce(new ApiClientError('not_found', 404, 'course_not_found', 'Course not found.'))
        .mockResolvedValueOnce(detail())

      const { wrapper } = await render('gone')

      expect(wrapper.get('[data-testid=error]').text()).toContain('Курс не найден или недоступен.')
      expect(wrapper.find('[data-testid=title]').exists()).toBe(false)

      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(api.courses.get).toHaveBeenCalledTimes(2)
      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=title] h1').text()).toBe('Английский A1')
    })
  })

  describe('navigation', () => {
    it('has no text link back: "Назад" is the system button, and it leads to the catalog', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper, router } = await render()

      expect(wrapper.find('[data-testid=back-link]').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('←')
      expect(tg.showBack).toHaveBeenCalledTimes(1)
      expect(tg.hideBack).not.toHaveBeenCalled()

      tg.handler?.()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('courses')
    })

    it('releases the system button when the screen is left', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()
      expect(tg.release).not.toHaveBeenCalled()

      wrapper.unmount()

      expect(tg.release).toHaveBeenCalledTimes(1)
    })
  })

  // --- IELTS / SAT mock (task 2D.4, docs/13 §8.4) ---------------------------------

  describe('mock exam', () => {
    it('offers the mock exam only on IELTS / SAT courses', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', words_count: 120 }))
      const { wrapper } = await render()
      const card = wrapper.get('[data-testid=mock-exam]')
      expect(card.get('h2').text()).toBe('Пробный тест')
      expect(wrapper.get('[data-testid=mock-exam-btn]').text()).toBe('Начать тест')
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

    it.each([
      [1, '1 слово курса'],
      [3, '3 слова курса'],
      [12, '12 слов курса'],
      [21, '21 слово курса'],
      [500, '40 слов курса'], // the mock takes at most 40
    ])('agrees «слов» in the hint with the size of the test: %i words in the course -> %s', async (words, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'sat', words_count: words }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=mock-exam-hint]').text()).toBe(`${expected} · 20 мин · результат в процентах и сравнение с другими`)
    })

    it('draws a plain card (no tile, no icon) with a tinted block button', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))

      const { wrapper } = await render()

      const card = wrapper.get('[data-testid=mock-exam]')
      expect(card.classes()).toContain('card')
      expect(card.find('.icon-tile').exists()).toBe(false)
      expect(card.find('svg').exists()).toBe(false)
      expect(wrapper.get('[data-testid=mock-exam-btn]').classes()).toEqual(expect.arrayContaining(['btn', 'btn-secondary', 'btn-block']))
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

    it('disables the button while the mock is being opened', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
      vi.mocked(api.exam.openMock).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=mock-exam-btn]').attributes('disabled')).toBeUndefined()

      await wrapper.get('[data-testid=mock-exam-btn]').trigger('click')

      expect(wrapper.get('[data-testid=mock-exam-btn]').attributes('disabled')).toBeDefined()
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

    describe('"Повторить слова" in the paywall', () => {
      /** A Free learner is refused the mock while the Home tab knows of due cards: the sheet offers the review. */
      async function openPaywall() {
        useTodayStore().data = { due_count: 12 } as never
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
        const context = await render()
        await context.router.push('/courses')
        await context.wrapper.get('[data-testid=mock-exam-btn]').trigger('click')
        await flushPromises()
        return context
      }

      it('starts the daily review: the sheet closes and the training screen opens', async () => {
        vi.mocked(api.training.open).mockResolvedValue({ session_id: 64, mode: 'daily_review', lesson: null, total_words: 5 } as never)
        const { wrapper, router } = await openPaywall()
        expect(wrapper.get('[data-testid=paywall-review]').text()).toBe('Повторить слова')

        await wrapper.get('[data-testid=paywall-review]').trigger('click')
        await flushPromises()

        expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
        expect(router.currentRoute.value.name).toBe('training')
        expect(router.currentRoute.value.params.sessionId).toBe('64')
      })

      it('tells in a toast why the review did not open, as for any other session of this screen, and stays on the course', async () => {
        vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'x'))
        const { wrapper, router } = await openPaywall()

        await wrapper.get('[data-testid=paywall-review]').trigger('click')
        await flushPromises()

        expect(router.currentRoute.value.name).toBe('courses')
        expect(useToast().toast.value?.text).toBe('Нет связи с сервером. Попробуйте ещё раз.')
      })
    })

    it('explains any other refusal of the mock in an alert on the card', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
      vi.mocked(api.exam.openMock).mockRejectedValue(
        new ApiClientError('validation', 422, 'not_enough_words', 'Too few words.'),
      )

      const { wrapper } = await render()
      await wrapper.get('[data-testid=mock-exam-btn]').trigger('click')
      await flushPromises()

      const alert = wrapper.get('[data-testid=mock-exam] [data-testid=mock-exam-error]')
      expect(alert.text()).toBe('В курсе пока слишком мало слов для теста.')
      expect(alert.attributes('role')).toBe('alert')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    })
  })

  it('names a module without a title "N-modul" in Uzbek: the server sends null, never an English "Stage N"', async () => {
    setLocale('uz')
    vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [{ ...stage(2, [lesson(21, 1, 0, 1)]), title: null }] }))

    const { wrapper } = await render()

    const bare = wrapper.get('[data-testid=stage-2]')
    expect(bare.get('[data-testid=module-title]').text()).toBe('2-modul')
    expect(bare.get('[data-testid=module-meta]').text()).toBe("1 ta so'z")
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 2, 3, 'Greetings'), lesson(12, 2, 0, 3), lesson(13, 3, 3, 3)])] }))

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=lessons] h2').text()).toBe('Modullar')
    expect(wrapper.get('[data-testid=stage-1] [data-testid=module-title]').text()).toBe('1. Module 1')
    expect(wrapper.get('[data-testid=stage-1] [data-testid=module-meta]').text()).toBe('3 ta darsdan 1 tasi')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-title]').text()).toBe('1-dars · Greetings')
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-title]').text()).toBe('2-dars')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe("3 ta so'zdan 2 tasi")
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe("3 ta so'z")
    expect(wrapper.get('[data-testid=lesson-13] [data-testid=lesson-hint]').text()).toBe('takrorlash')
    expect(wrapper.get('[data-testid=lesson-13] [data-testid=lesson-status]').attributes('aria-label')).toBe('Tugallangan')
    expect(wrapper.get('[data-testid=progress-text]').text()).toBe("5 ta so'zdan 2 tasi")
    expect(wrapper.get('[data-testid=progress-bar]').attributes('aria-label')).toBe("5 tadan 2 tasi o'rganildi")
    expect(wrapper.get('[data-testid=all-courses]').text()).toBe('Barcha kurslar')
    expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Kursni boshlash')
    expect(wrapper.get('[data-testid=choose-hint]').text()).toBe("Kunlik mashg'ulotdagi yangi so'zlar shu kursdan bo'ladi")

    vi.mocked(api.courses.get).mockResolvedValue(detail({
      exam_tag: 'ielts',
      is_active: true,
      stages: [stage(1, [lesson(11, 1, 0, 3), lesson(12, 2, 0, 3)])],
    }))
    const main = await render()
    expect(main.wrapper.get('[data-testid=title] h1').text()).toBe('Mening kursim')
    expect(main.wrapper.get('[data-testid=mock-exam]').get('h2').text()).toBe('Sinov imtihoni')
    expect(main.wrapper.get('[data-testid=mock-exam-btn]').text()).toBe('Testni boshlash')
    expect(main.wrapper.get('[data-testid=continue-label]').text()).toBe('Darsni boshlash')
    expect(main.wrapper.get('[data-testid=module-meta]').text()).toBe('2 ta darsdan 1-dars · siz shu yerdasiz')
    expect(main.wrapper.get('[data-testid=progress-text]').text()).toBe("1 ta moduldan 1-modul · 5 ta so'zdan 2 tasi")
    expect(main.wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe("3 ta yangi so'z · siz shu yerdasiz")
    expect(main.wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe('1-darsdan keyin')

    vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 1, 3)])] }))
    const begun = await render()
    expect(begun.wrapper.get('[data-testid=continue-label]').text()).toBe('Davom etish')

    vi.mocked(api.courses.get).mockResolvedValue(detail({
      is_active: true,
      stages: [stage(1, [lesson(11, 1, 3, 3)])],
    }))
    const done = await render()
    expect(done.wrapper.get('[data-testid=all-done]').text()).toBe('Kursning barcha darslari tugallandi')
  })

  it('leads to the readiness screen of the course from a row', async () => {
    vi.mocked(api.courses.get).mockResolvedValue(detail({ slug: 'english-a1', words_count: 100 }))
    const { wrapper } = await render()

    const row = wrapper.get('[data-testid=readiness-row]')
    expect(row.text()).toContain('Готовность и тест недели')
    expect(row.attributes('href')).toBe('/courses/english-a1/readiness?source=course')
  })
})
