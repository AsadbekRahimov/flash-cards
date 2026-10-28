import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper } from '@vue/test-utils'
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

async function render(slug = 'english-a1') {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
      { path: '/exam/:sessionId(\\d+)', name: 'exam', component: stub },
    ],
  })
  const wrapper = mount(CourseView, { props: { slug }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

/** Texts and tone classes of the chips inside a container. */
function chips(container: Pick<DOMWrapper<Element>, 'findAll'>) {
  return container.findAll('.chip').map((chip) => ({
    text: chip.text(),
    tone: chip.classes().find((c) => c.startsWith('chip-')),
  }))
}

function sessionAnswer(id: number) {
  return { session_id: id, mode: 'lesson' as const, lesson: { stage: 1, lesson: 2, title: null }, total_words: 2 }
}

describe('CourseView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
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
    it('loads the course by slug and shows the title, chips, description and progress', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ description: 'Первые слова', exam_tag: 'general' }))

      const { wrapper } = await render()

      expect(api.courses.get).toHaveBeenCalledWith('english-a1')
      expect(wrapper.get('[data-testid=title]').text()).toBe('Английский A1')
      expect(chips(wrapper.get('[data-testid=meta]'))).toEqual([
        { text: 'A1', tone: 'chip-neutral' },
        { text: 'Общий английский', tone: 'chip-neutral' },
        { text: '6 слов', tone: 'chip-neutral' },
      ])
      expect(wrapper.text()).toContain('Первые слова')
      expect(wrapper.get('[data-testid=progress-text]').text()).toBe('Изучено 2 из 5')
      // The first progress bar of the screen is the course's (the modules' follow).
      expect(wrapper.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('40')
      expect(wrapper.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 40%')
    })

    it('shows the exam goal of IELTS / SAT in the accent colour', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', level: 'B2', words_count: 1200 }))

      const { wrapper } = await render()

      expect(chips(wrapper.get('[data-testid=meta]'))).toEqual([
        { text: 'B2', tone: 'chip-neutral' },
        { text: 'IELTS', tone: 'chip-accent' },
        { text: '1200 слов', tone: 'chip-neutral' },
      ])
    })

    it('leaves out the description and the level when the course has none', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ description: null, level: null }))

      const { wrapper } = await render()

      expect(chips(wrapper.get('[data-testid=meta]'))).toEqual([{ text: '6 слов', tone: 'chip-neutral' }])
    })
  })

  describe('main action in a course that is not the main one', () => {
    it('offers "Сделать основным" as the primary button with the explanation', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      const choose = wrapper.get('[data-testid=choose-btn]')
      expect(choose.text()).toBe('Сделать основным')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      expect(wrapper.get('[data-testid=choose-hint]').text()).toBe('Новые слова в ежедневном занятии будут из этого курса')
      expect(wrapper.find('[data-testid=active-badge]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
    })

    it('has exactly one primary button, whichever the course', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))
      const notMain = await render()
      expect(notMain.wrapper.findAll('.btn-primary')).toHaveLength(1)

      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', is_active: true }))
      const main = await render()
      expect(main.wrapper.findAll('.btn-primary')).toHaveLength(1)
    })

    it('selects the course; the screen turns into the main course: chip, "Продолжить", no hint', async () => {
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
      expect(wrapper.find('[data-testid=choose-hint]').exists()).toBe(false)
      const badge = wrapper.get('[data-testid=active-badge]')
      expect(badge.text()).toBe('Основной курс')
      expect(badge.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
      expect(wrapper.get('[data-testid=continue-btn]').text()).toBe('Продолжить: модуль 1, урок 1')
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

      const { wrapper } = await render()
      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      await flushPromises()

      const alert = wrapper.get('[data-testid=select-error]')
      expect(alert.text()).toBe('Курс не найден или недоступен.')
      expect(alert.attributes('role')).toBe('alert')
      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeUndefined()
    })
  })

  describe('main action in the main course', () => {
    it('shows the chip and "Продолжить: модуль M, урок N" for the first unfinished lesson', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3, 'Greetings'), lesson(12, 2, 1, 4, 'Food'), lesson(13, 3, 0, 4)])],
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=active-badge]').text()).toBe('Основной курс')
      const next = wrapper.get('[data-testid=continue-btn]')
      expect(next.text()).toBe('Продолжить: модуль 1, урок 2')
      expect(next.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=choose-hint]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
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

      // The lesson number restarts in every module, so the label names the module too: the target is lesson 22.
      expect(wrapper.get('[data-testid=continue-btn]').text()).toBe('Продолжить: модуль 2, урок 2')
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
    })

    it('shows neither the button nor "all learned" in a course without lessons', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [], progress: { done: 0, total: 0 } }))

      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=all-done]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=no-lessons]').text()).toBe('В курсе пока нет уроков.')
    })

    it('disables "Продолжить" while a session is being opened', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 0, 3)])] }))
      vi.mocked(api.training.open).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=continue-btn]').attributes('disabled')).toBeUndefined()

      await wrapper.get('[data-testid=continue-btn]').trigger('click')

      expect(wrapper.get('[data-testid=continue-btn]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-testid=learn-btn]').attributes('disabled')).toBeDefined()
    })

    it('explains in a toast why a session could not be opened from "Продолжить"', async () => {
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

  describe('modules and lessons', () => {
    it('shows a module as its title with a "Модуль N" chip and a thin progress bar', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      const first = wrapper.get('[data-testid=stage-1]')
      expect(first.get('h2').text()).toBe('Basics')
      expect(chips(first)).toEqual([{ text: 'Модуль 1', tone: 'chip-neutral' }])
      const bar = first.get('[data-testid=progress-bar]')
      expect(bar.classes()).toContain('h-1')
      expect(bar.attributes('aria-valuenow')).toBe('40')
      expect(first.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 40%')
      expect(first.find('.list').exists()).toBe(true)

      // A module without lessons keeps its heading and has no (empty) list.
      const second = wrapper.get('[data-testid=stage-2]')
      expect(second.text()).toContain('Food')
      expect(second.text()).toContain('Модуль 2')
      expect(second.find('.list').exists()).toBe(false)
    })

    it('shows a lesson as "Урок N · title" with the words learned', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      const first = wrapper.get('[data-testid=lesson-11]')
      expect(first.text()).toContain('Урок 1 · Greetings')
      expect(first.get('[data-testid=lesson-progress]').text()).toBe('2 из 3 слов')

      const untitled = wrapper.get('[data-testid=lesson-12]')
      expect(untitled.text()).toContain('Урок 2')
      expect(untitled.text()).not.toContain('·')
      expect(untitled.get('[data-testid=lesson-progress]').text()).toBe('0 из 2 слов')
    })

    it('marks the status of a lesson: learned, started, not started', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 1, 3), lesson(13, 3, 0, 3), lesson(14, 4, 0, 0)])],
      }))

      const { wrapper } = await render()

      const status = (id: number) => wrapper.get(`[data-testid=lesson-${id}] [data-testid=lesson-status]`)

      // Learned: a solid check in the success colour instead of the number.
      expect(status(11).attributes('data-state')).toBe('done')
      expect(status(11).classes()).toContain('text-success')
      expect(status(11).find('svg').exists()).toBe(true)
      expect(status(11).text()).toBe('')

      // Started: the number in an accent circle.
      expect(status(12).attributes('data-state')).toBe('started')
      expect(status(12).classes()).toEqual(expect.arrayContaining(['bg-accent', 'rounded-full']))
      expect(status(12).text()).toBe('2')

      // Not started (and an empty lesson): the number on the quiet fill.
      for (const id of [13, 14]) {
        expect(status(id).attributes('data-state')).toBe('new')
        expect(status(id).classes()).toContain('bg-fill')
        expect(status(id).find('svg').exists()).toBe(false)
      }
      expect(status(13).text()).toBe('3')
      expect(status(14).text()).toBe('4')
    })

    it('offers to learn every lesson that has words, as a small tinted button', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [stage(1, [lesson(11, 1, 2, 3, 'Greetings'), lesson(12, 2, 0, 0)])],
      }))

      const { wrapper } = await render()

      const learn = wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]')
      expect(learn.text()).toBe('Учить')
      expect(learn.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-sm']))
      expect(learn.attributes('disabled')).toBeUndefined()
      // An empty lesson has nothing to learn.
      expect(wrapper.get('[data-testid=lesson-12]').get('[data-testid=learn-btn]').attributes('disabled')).toBeDefined()
    })

    it('opens a personal lesson session from the picker and goes to the training screen', async () => {
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

    it('stays on the course and explains in a toast why a lesson could not be opened: the button is far from the top of the page', async () => {
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
      expect(wrapper.get('[data-testid=title]').text()).toBe('Английский A1')
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

    it('draws the card with a purple flag tile and a tinted block button', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts' }))

      const { wrapper } = await render()

      const tile = wrapper.get('[data-testid=mock-exam] .icon-tile')
      expect(tile.classes()).toContain('tile-purple')
      expect(tile.find('svg').exists()).toBe(true)
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

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 2, 3, 'Greetings'), lesson(12, 2, 0, 3)])] }))

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=stage-1]').text()).toContain('1-modul')
    expect(wrapper.get('[data-testid=lesson-11]').text()).toContain('1-dars · Greetings')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-progress]').text()).toBe("3 ta so'zdan 2 tasi")
    expect(wrapper.get('[data-testid=progress-text]').text()).toBe("5 tadan 2 tasi o'rganildi")
    expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Asosiy qilish')
    expect(wrapper.get('[data-testid=choose-hint]').text()).toBe("Kunlik mashg'ulotdagi yangi so'zlar shu kursdan bo'ladi")
    expect(wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').text()).toBe("O'rganish")

    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', is_active: true }))
    const main = await render()
    expect(main.wrapper.get('[data-testid=mock-exam]').get('h2').text()).toBe('Sinov testi')
    expect(main.wrapper.get('[data-testid=mock-exam-btn]').text()).toBe('Testni boshlash')
    expect(main.wrapper.get('[data-testid=active-badge]').text()).toBe('Asosiy kurs')
    expect(main.wrapper.get('[data-testid=continue-btn]').text()).toBe('Davom etish: 1-modul, 1-dars')

    vi.mocked(api.courses.get).mockResolvedValue(detail({
      is_active: true,
      stages: [stage(1, [lesson(11, 1, 3, 3)])],
    }))
    const done = await render()
    expect(done.wrapper.get('[data-testid=all-done]').text()).toBe('Kursning barcha darslari tugallandi')
  })
})
