import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CourseView from '../CourseView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useToast } from '@/composables/useToast'
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

async function render(slug = 'english-a1', attach = false) {
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
    it('loads the course by slug: the brand row, the name as the heading, the card with the words learned of all and the per cent', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ description: 'Первые слова', exam_tag: 'general' }))

      const { wrapper } = await render()

      expect(api.courses.get).toHaveBeenCalledWith('english-a1')
      expect(wrapper.find('[data-testid=brand-row]').exists()).toBe(true)
      // the one language button of the screen is the pill of the brand row
      expect(wrapper.findAll('[data-testid=lang-btn]')).toHaveLength(1)
      const heading = wrapper.get('[data-testid=title] h1')
      expect(heading.text()).toBe('Английский A1')
      expect(heading.classes()).toContain('text-[30px]')
      // Another course: the heading is its name, the card says its level and goal.
      const card = wrapper.get('[data-testid=course-card]')
      expect(card.classes()).toEqual(expect.arrayContaining(['bg-highlight', 'rounded-hero']))
      expect(card.get('[data-testid=course-line]').text()).toBe('A1 · Общий английский')
      expect(card.get('[data-testid=progress-text]').text()).toBe('2 / 5 слов')
      expect(card.get('[data-testid=progress-percent]').text()).toBe('40%')
      const bar = card.get('[data-testid=progress-bar]')
      expect(bar.attributes('aria-valuenow')).toBe('40')
      expect(bar.attributes('aria-label')).toBe('Изучено 2 из 5')
      expect(bar.classes()).toEqual(expect.arrayContaining(['h-2.5', 'bg-accent-line']))
      expect(bar.get('.progress-fill').classes()).toContain('progress-primary')
      expect(bar.get('.progress-fill').attributes('style')).toContain('width: 40%')
      expect(wrapper.get('[data-testid=description]').text()).toBe('Первые слова')
    })

    it('calls the learner course "Мой курс": the card names it (level · title), the numbers are the server ones', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        description: 'Первые слова',
        title: 'Основные слова',
        level: 'A1–A2',
        progress: { done: 120, total: 1000 },
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=title] h1').text()).toBe('Мой курс')
      expect(wrapper.get('[data-testid=course-line]').text()).toBe('A1–A2 · Основные слова')
      expect(wrapper.get('[data-testid=progress-text]').text()).toBe('120 / 1000 слов')
      expect(wrapper.get('[data-testid=progress-text] .font-extrabold').text()).toBe('120')
      expect(wrapper.get('[data-testid=progress-percent]').text()).toBe('12%')
      expect(wrapper.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('12')
      // the layout of the learner's course has no description and no "you take this course" chip: the heading says it
      expect(wrapper.find('[data-testid=description]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=active-badge]').exists()).toBe(false)
    })

    it('shows the exam goal of IELTS / SAT in the line of the card', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', level: 'B2', words_count: 1200 }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=course-line]').text()).toBe('B2 · IELTS')
    })

    it('leaves out the line, the description and the level when the course has none', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ description: null, level: null }))

      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=course-line]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=description]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=progress-text]').text()).toBe('2 / 5 слов')
    })

    it.each([
      [1, '0 / 1 слова'],
      [3, '0 / 3 слов'],
      [21, '0 / 21 слова'],
      [0, '0 / 0 слов'],
    ])('agrees «слов» with the size of the course: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ progress: { done: 0, total } }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=progress-text]').text()).toBe(expected)
      expect(wrapper.get('[data-testid=progress-percent]').text()).toBe('0%')
    })

    it('leads to the list of all courses (and "Мои колоды" from it) by "Все курсы"', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true }))

      const { wrapper, router } = await render()
      const link = wrapper.get('[data-testid=all-courses]')
      expect(link.text()).toBe('Все курсы')
      expect(link.attributes('href')).toBe('/courses')

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

    it('has exactly one primary button, whichever the course', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
      const notMain = await render()
      expect(notMain.wrapper.findAll('.btn-primary')).toHaveLength(1)

      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', is_active: true }))
      const main = await render()
      expect(main.wrapper.findAll('.btn-primary')).toHaveLength(1)
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
      expect(wrapper.get('[data-testid=lesson-11] [data-testid=continue-btn]').text()).toBe('Продолжить')
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

  describe('main action in the main course', () => {
    it('makes the first unfinished lesson the tinted card "вы здесь" with the one primary button "Продолжить"', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3, 'Greetings'), lesson(12, 2, 1, 4, 'Food'), lesson(13, 3, 0, 4)])],
      }))

      const { wrapper } = await render()

      const current = wrapper.get('[data-testid=lesson-12]')
      expect(current.attributes('data-state')).toBe('current')
      expect(current.classes()).toEqual(expect.arrayContaining(['bg-highlight', 'rounded-hero']))
      expect(current.get('[data-testid=lesson-title]').text()).toBe('Урок 2 · Food')
      expect(current.get('[data-testid=lesson-hint]').text()).toBe('1 из 4 слов')
      expect(current.get('[data-testid=lesson-here]').text()).toBe('вы здесь')
      const next = current.get('[data-testid=continue-btn]')
      expect(next.text()).toBe('Продолжить')
      expect(next.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-block']))
      expect(next.find('svg').exists()).toBe(true)
      // The current lesson has the big button instead of the row button; the others keep theirs.
      expect(current.find('[data-testid=learn-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=lesson-13] [data-testid=lesson-here]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=lesson-13]').attributes('data-state')).toBe('new')
      expect(wrapper.findAll('[data-testid=lesson-here]')).toHaveLength(1)
      expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=choose-hint]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
    })

    it('says "Начать урок" and the number of new words while the current lesson is not begun', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 0, 5, 'Напитки')])],
      }))

      const { wrapper } = await render()

      const current = wrapper.get('[data-testid=lesson-12]')
      expect(current.get('[data-testid=continue-btn]').text()).toBe('Начать урок')
      expect(current.get('[data-testid=lesson-hint]').text()).toBe('5 новых слов')
      expect(current.text()).toContain('5 новых слов · вы здесь')
    })

    it.each([
      [1, '1 новое слово'],
      [3, '3 новых слова'],
      [5, '5 новых слов'],
      [21, '21 новое слово'],
    ])('agrees «новых слов» with the size of the current lesson: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 0, total)])] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe(expected)
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
      expect(wrapper.find('[data-testid=stage-1]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=stage-2] [data-testid=lesson-22] [data-testid=continue-btn]').text()).toBe('Начать урок')
      expect(wrapper.findAll('[data-testid=continue-btn]')).toHaveLength(1)
      await wrapper.get('[data-testid=continue-btn]').trigger('click')
      await flushPromises()
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 22, source: 'picker' })
    })

    it('says that all lessons are learned instead of showing a button', async () => {
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
    })

    it('shows neither the button nor "all learned" in a course without lessons', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [], progress: { done: 0, total: 0 } }))

      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=no-lessons]').text()).toBe('В курсе пока нет уроков.')
    })

    it('disables the button of the current lesson while a session is being opened', async () => {
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

  describe('opens on the current lesson', () => {
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

    it('scrolls the row of the current lesson into the middle of the screen once the course is shown', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(longCourse())

      const { wrapper } = await render()

      expect(scroll).toHaveBeenCalledTimes(1)
      expect(scroll.mock.contexts[0]).toBe(wrapper.get('[data-testid=lesson-31]').element)
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

    it('has no list of modules, no arrows between them and no "К текущему уроку": the list is flat', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(longCourse())

      const { wrapper } = await render()

      for (const id of ['stage-picker-btn', 'stage-sheet', 'stage-prev', 'stage-next', 'to-current-btn']) {
        expect(wrapper.find(`[data-testid=${id}]`).exists()).toBe(false)
      }
      expect(wrapper.findAll('li[data-state]')).toHaveLength(6)
    })
  })

  describe('modules and lessons', () => {
    it('shows every lesson in one list, a thin heading of its module between them: "Модуль N · name" and "N из M уроков"', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [
          { ...stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 1, 3)]), title: 'Basics' },
          { ...stage(2, [lesson(21, 1, 0, 2)]), title: 'Food' },
          { ...stage(3, []), title: 'Later' },
        ],
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lessons] h2').text()).toBe('Уроки')
      const first = wrapper.get('[data-testid=stage-1]')
      const heading = first.get('[data-testid=stage-title]')
      expect(heading.element.tagName).toBe('H3')
      expect(heading.text()).toBe('Модуль 1 · Basics')
      expect(first.get('[data-testid=stage-count]').text()).toBe('1 из 2 уроков')
      // a thin line: no card, no button, no arrow, no line of progress
      expect(heading.element.parentElement?.className).toContain('text-[13px]')
      expect(heading.element.parentElement?.querySelector('button, svg, .progress')).toBeNull()
      expect(first.findAll('li[data-state]').map((row) => row.attributes('data-testid'))).toEqual(['lesson-11', 'lesson-12'])

      expect(wrapper.get('[data-testid=stage-2] [data-testid=stage-title]').text()).toBe('Модуль 2 · Food')
      expect(wrapper.get('[data-testid=stage-2] [data-testid=stage-count]').text()).toBe('0 из 1 урока')
      // a module without lessons is its heading alone
      const empty = wrapper.get('[data-testid=stage-3]')
      expect(empty.find('[data-testid=stage-count]').exists()).toBe(false)
      expect(empty.find('ol').exists()).toBe(false)
      // the modules follow each other in one list
      expect(wrapper.findAll('section[data-testid^=stage-]').map((section) => section.attributes('data-testid'))).toEqual(['stage-1', 'stage-2', 'stage-3'])
    })

    it.each([
      [1, 0, '0 из 1 урока'],
      [2, 1, '1 из 2 уроков'],
      [5, 5, '5 из 5 уроков'],
    ])('agrees «уроков» with the size of the module: %i lessons, %i learned -> %s', async (count, learned, expected) => {
      const lessons = Array.from({ length: count }, (_, i) => lesson(100 + i, i + 1, i < learned ? 2 : 0, 2))
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, lessons)] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=stage-count]').text()).toBe(expected)
    })

    it('names a module without a title by its number, and treats a blank title like none', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [{ ...stage(2, [lesson(21, 1, 0, 3)]), title: null }, { ...stage(3, []), title: '   ' }],
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=stage-2] [data-testid=stage-title]').text()).toBe('Модуль 2')
      expect(wrapper.get('[data-testid=stage-3] [data-testid=stage-title]').text()).toBe('Модуль 3')
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

      // Every row is a white row of the layout: frame, 14 px radius.
      for (const id of [11, 12, 13, 14]) {
        expect(row(id).classes()).toEqual(expect.arrayContaining(['bg-surface', 'border', 'border-hairline', 'rounded-option']))
      }

      // Learned: a green circle with a tick, named for a screen reader; "повторить" at the end.
      expect(row(11).attributes('data-state')).toBe('done')
      const done = row(11).get('[data-testid=lesson-status]')
      expect(done.attributes('role')).toBe('img')
      expect(done.attributes('aria-label')).toBe('Пройден')
      expect(done.classes()).toContain('bg-success')
      expect(done.find('[data-testid=lesson-done]').exists()).toBe(true)
      expect(row(11).get('[data-testid=lesson-hint]').text()).toBe('повторить')

      // Started (not the learner's course: nothing is "current" here): a ring in the accent and the words learned.
      expect(row(12).attributes('data-state')).toBe('started')
      expect(row(12).get('[data-testid=lesson-status]').classes()).toContain('border-accent')
      expect(row(12).get('[data-testid=lesson-hint]').text()).toBe('1 из 3 слов')
      expect(row(12).find('[data-testid=lesson-done]').exists()).toBe(false)
      expect(row(12).find('[data-testid=lesson-here]').exists()).toBe(false)

      // Not started (and an empty lesson): a grey ring.
      for (const id of [13, 14]) {
        expect(row(id).attributes('data-state')).toBe('new')
        expect(row(id).get('[data-testid=lesson-status]').classes()).toContain('border-muted')
        expect(row(id).get('[data-testid=lesson-status]').attributes('aria-hidden')).toBe('true')
        expect(row(id).find('[data-testid=lesson-done]').exists()).toBe(false)
      }
      expect(wrapper.find('[data-state=current]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=lessons] .bg-highlight').exists()).toBe(false)
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
      expect(wrapper.get('[data-testid=mock-exam-btn]').classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
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
    expect(bare.get('[data-testid=stage-title]').text()).toBe('2-modul')
    expect(bare.get('[data-testid=stage-count]').text()).toBe('1 ta darsdan 0 tasi')
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 2, 3, 'Greetings'), lesson(12, 2, 0, 3), lesson(13, 3, 3, 3)])] }))

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=lessons] h2').text()).toBe('Darslar')
    expect(wrapper.get('[data-testid=stage-1] [data-testid=stage-title]').text()).toBe('1-modul · Module 1')
    expect(wrapper.get('[data-testid=stage-1] [data-testid=stage-count]').text()).toBe('3 ta darsdan 1 tasi')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-title]').text()).toBe('1-dars · Greetings')
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-title]').text()).toBe('2-dars')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe("3 ta so'zdan 2 tasi")
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe("3 ta so'z")
    expect(wrapper.get('[data-testid=lesson-13] [data-testid=lesson-hint]').text()).toBe('takrorlash')
    expect(wrapper.get('[data-testid=lesson-13] [data-testid=lesson-status]').attributes('aria-label')).toBe('Tugallangan')
    expect(wrapper.get('[data-testid=progress-text]').text()).toBe("2 / 5 ta so'z")
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
    expect(main.wrapper.get('[data-testid=continue-btn]').text()).toBe('Darsni boshlash')
    expect(main.wrapper.get('[data-testid=lesson-here]').text()).toBe('siz shu yerdasiz')
    expect(main.wrapper.get('[data-testid=lesson-11] [data-testid=lesson-hint]').text()).toBe("3 ta yangi so'z")
    expect(main.wrapper.get('[data-testid=lesson-12] [data-testid=lesson-hint]').text()).toBe('1-darsdan keyin')

    vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 1, 3)])] }))
    const begun = await render()
    expect(begun.wrapper.get('[data-testid=continue-btn]').text()).toBe('Davom etish')

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
