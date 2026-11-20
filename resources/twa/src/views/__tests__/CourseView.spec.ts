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
      { path: '/today', name: 'today', component: stub },
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
      // The first progress bar of the screen is the course's (the module's follows): a thin line of the words learned.
      const bar = wrapper.get('[data-testid=progress-bar]')
      expect(bar.attributes('aria-valuenow')).toBe('40')
      expect(bar.get('.progress-fill').classes()).toContain('progress-success')
      expect(bar.get('.progress-fill').attributes('style')).toContain('width: 40%')
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
    it('offers "Начать курс" as the primary button with the explanation while the learner has no course', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      const choose = wrapper.get('[data-testid=choose-btn]')
      expect(choose.text()).toBe('Начать курс')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      expect(wrapper.get('[data-testid=choose-hint]').text()).toBe('Новые слова в ежедневном занятии будут из этого курса')
      expect(wrapper.find('[data-testid=active-badge]').exists()).toBe(false)
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
      const badge = wrapper.get('[data-testid=active-badge]')
      expect(badge.text()).toBe('Вы учите этот курс')
      expect(badge.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
      expect(wrapper.get('[data-testid=lesson-11] [data-testid=continue-btn]').text()).toBe('Учить урок')
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
    it('highlights the first unfinished lesson with "вы здесь" and the one primary button "Учить урок"', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        is_active: true,
        stages: [stage(1, [lesson(11, 1, 3, 3, 'Greetings'), lesson(12, 2, 1, 4, 'Food'), lesson(13, 3, 0, 4)])],
      }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=active-badge]').text()).toBe('Вы учите этот курс')
      const current = wrapper.get('[data-testid=lesson-12]')
      expect(current.attributes('data-state')).toBe('current')
      expect(current.classes()).toEqual(expect.arrayContaining(['bg-accent-soft', 'shadow-[inset_0_0_0_1.5px_var(--app-primary)]']))
      expect(current.get('[data-testid=lesson-here]').text()).toBe('вы здесь')
      expect(current.get('[data-testid=lesson-here]').classes()).toEqual(expect.arrayContaining(['chip', 'chip-accent']))
      const next = current.get('[data-testid=continue-btn]')
      expect(next.text()).toBe('Учить урок')
      expect(next.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-block']))
      // The current lesson has the big button instead of the small "Учить"; the others keep theirs.
      expect(current.find('[data-testid=learn-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=lesson-13] [data-testid=lesson-here]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=lesson-13]').attributes('data-state')).toBe('new')
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

      // The screen opens on the module of the current lesson: module 2, lesson 22.
      expect(wrapper.find('[data-testid=stage-1]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=stage-2] [data-testid=lesson-22] [data-testid=continue-btn]').text()).toBe('Учить урок')
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

    it('disables "Учить урок" while a session is being opened', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ is_active: true, stages: [stage(1, [lesson(11, 1, 0, 3), lesson(12, 2, 0, 3)])] }))
      vi.mocked(api.training.open).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()
      expect(wrapper.get('[data-testid=continue-btn]').attributes('disabled')).toBeUndefined()

      await wrapper.get('[data-testid=continue-btn]').trigger('click')

      expect(wrapper.get('[data-testid=continue-btn]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-testid=learn-btn]').attributes('disabled')).toBeDefined()
    })

    it('explains in a toast why a session could not be opened from "Учить урок"', async () => {
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
    it('shows one module: "Модуль N из M" above its title, a thin line of the words learned and the numbered lessons', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      const first = wrapper.get('[data-testid=stage-1]')
      expect(first.get('[data-testid=stage-of]').text()).toBe('Модуль 1 из 2')
      expect(first.get('[data-testid=stage-title]').text()).toBe('Basics')
      expect(chips(first)).toEqual([])
      const bar = first.get('[data-testid=progress-bar]')
      expect(bar.attributes('aria-valuenow')).toBe('40')
      expect(bar.get('.progress-fill').classes()).toContain('progress-success')
      expect(bar.get('.progress-fill').attributes('style')).toContain('width: 40%')
      expect(first.get('[data-testid=stage-progress]').text()).toBe('Изучено 2 из 5')
      expect(first.find('ol.list').exists()).toBe(true)
      // One module at a time: the next one is behind the arrow.
      expect(wrapper.find('[data-testid=stage-2]').exists()).toBe(false)
    })

    describe('the list of modules', () => {
      /** The learner's course: module 1 learned, the current lesson in module 2 (lesson 22), module 3 not begun. */
      const threeModules = () => detail({
        is_active: true,
        stages: [
          stage(1, [lesson(11, 1, 3, 3)]),
          stage(2, [lesson(21, 1, 2, 2), lesson(22, 2, 1, 4)]),
          { ...stage(3, [lesson(31, 1, 0, 5)]), title: null },
        ],
      })

      it('opens from the heading of the module, a button that says it opens a dialog', async () => {
        vi.mocked(api.courses.get).mockResolvedValue(threeModules())
        const { wrapper } = await render()

        const button = wrapper.get('[data-testid=stage-2] [data-testid=stage-picker-btn]')
        expect(button.element.tagName).toBe('BUTTON')
        expect(button.element.parentElement?.tagName).toBe('H2')
        expect(button.text()).toContain('Модуль 2 из 3')
        expect(button.text()).toContain('Module 2')
        expect(button.attributes('aria-haspopup')).toBe('dialog')
        expect(button.attributes('aria-expanded')).toBe('false')
        expect(button.classes()).toContain('min-h-11')
        expect(wrapper.find('[data-testid=stage-sheet]').exists()).toBe(false)

        await button.trigger('click')

        expect(button.attributes('aria-expanded')).toBe('true')
        const sheet = wrapper.get('[data-testid=stage-sheet]')
        expect(sheet.attributes('role')).toBe('dialog')
        expect(sheet.attributes('aria-label')).toBe('Модули курса')
        wrapper.unmount()
      })

      it('lists every module: number, name, words or "пройден", "вы здесь" on the current one, the shown one selected', async () => {
        vi.mocked(api.courses.get).mockResolvedValue(threeModules())
        const { wrapper } = await render()
        await wrapper.get('[data-testid=stage-picker-btn]').trigger('click')

        const option = (n: number) => wrapper.get(`[data-testid=stage-option-${n}]`)
        expect(option(1).text()).toContain('01')
        expect(option(1).text()).toContain('Module 1')
        expect(option(1).get('[data-testid=stage-done]').attributes('aria-label')).toBe('Пройден')
        expect(option(1).find('[data-testid=stage-words]').exists()).toBe(false)

        expect(option(2).get('[data-testid=stage-here]').text()).toBe('вы здесь')
        expect(option(2).get('[data-testid=stage-words]').text()).toBe('3 из 6 слов')
        expect(option(2).attributes('aria-current')).toBe('true')
        expect(option(2).classes()).toContain('bg-accent-soft')

        // A module without a name is "Модуль N" here too.
        expect(option(3).text()).toContain('Модуль 3')
        expect(option(3).get('[data-testid=stage-words]').text()).toBe('0 из 5 слов')
        expect(option(3).attributes('aria-current')).toBeUndefined()
        expect(option(3).find('[data-testid=stage-here]').exists()).toBe(false)
        expect(wrapper.findAll('[data-testid=stage-here]')).toHaveLength(1)
        wrapper.unmount()
      })

      it('switches the module on a pick, closes, and gives the focus back to the heading', async () => {
        vi.mocked(api.courses.get).mockResolvedValue(threeModules())
        const { wrapper } = await render()
        const button = wrapper.get('[data-testid=stage-picker-btn]')
        ;(button.element as HTMLElement).focus()
        await button.trigger('click')
        await flushPromises()

        await wrapper.get('[data-testid=stage-option-1]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid=stage-sheet]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=stage-2]').exists()).toBe(false)
        expect(wrapper.get('[data-testid=stage-1] [data-testid=stage-title]').text()).toBe('Module 1')
        const heading = wrapper.get('[data-testid=stage-picker-btn]')
        expect(heading.attributes('aria-expanded')).toBe('false')
        expect(document.activeElement).toBe(heading.element)
        wrapper.unmount()
      })

      it('closes on the cross without changing the module', async () => {
        vi.mocked(api.courses.get).mockResolvedValue(threeModules())
        const { wrapper } = await render()
        await wrapper.get('[data-testid=stage-picker-btn]').trigger('click')

        await wrapper.get('[data-testid=sheet-x]').trigger('click')

        expect(wrapper.find('[data-testid=stage-sheet]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=stage-2]').exists()).toBe(true)
        wrapper.unmount()
      })

      it('is not there, nor are the arrows, in a course of one module', async () => {
        vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 0, 3)])] }))
        const { wrapper } = await render()

        expect(wrapper.find('[data-testid=stage-picker-btn]').exists()).toBe(false)
        expect(wrapper.get('[data-testid=stage-1] h2').text()).toBe('Module 1')
        expect(wrapper.find('[data-testid=stage-prev]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=stage-next]').exists()).toBe(false)
      })
    })

    describe('"К текущему уроку"', () => {
      const twoModules = (active = true) => detail({
        is_active: active,
        stages: [stage(1, [lesson(11, 1, 3, 3)]), stage(2, [lesson(21, 1, 1, 4)])],
      })

      it('is not there on the module of the current lesson', async () => {
        vi.mocked(api.courses.get).mockResolvedValue(twoModules())
        const { wrapper } = await render()

        expect(wrapper.find('[data-testid=stage-2]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=to-current-btn]').exists()).toBe(false)
      })

      it('shows away from it, leads back to the module of the current lesson and scrolls its row into view', async () => {
        const scroll = vi.fn()
        Element.prototype.scrollIntoView = scroll
        vi.mocked(api.courses.get).mockResolvedValue(twoModules())
        const { wrapper } = await render()

        await wrapper.get('[data-testid=stage-prev]').trigger('click')
        const back = wrapper.get('[data-testid=stage-1] [data-testid=to-current-btn]')
        expect(back.text()).toBe('К текущему уроку')
        expect(back.classes()).toContain('min-h-11')
        expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(false)

        await back.trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid=stage-2]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=to-current-btn]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(true)
        expect(scroll).toHaveBeenCalledTimes(1)
        expect(scroll.mock.contexts[0]).toBe(wrapper.get('[data-testid=lesson-21]').element)
        Reflect.deleteProperty(Element.prototype, 'scrollIntoView')
      })

      it('is never there in a course the learner does not take: it has no current lesson', async () => {
        vi.mocked(api.courses.get).mockResolvedValue(twoModules(false))
        const { wrapper } = await render()

        await wrapper.get('[data-testid=stage-prev]').trigger('click')
        expect(wrapper.find('[data-testid=to-current-btn]').exists()).toBe(false)
      })
    })

    it('walks the modules with the arrows under the lessons, a module without lessons has no (empty) list', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()
      expect(wrapper.find('[data-testid=stage-prev]').exists()).toBe(false)
      const next = wrapper.get('[data-testid=stage-next]')
      expect(next.text()).toBe('Модуль 2')
      expect(next.attributes('aria-label')).toBe('Следующий модуль: Food')

      await next.trigger('click')

      const second = wrapper.get('[data-testid=stage-2]')
      expect(second.get('[data-testid=stage-title]').text()).toBe('Food')
      expect(second.get('[data-testid=stage-of]').text()).toBe('Модуль 2 из 2')
      expect(second.find('.list').exists()).toBe(false)
      expect(wrapper.find('[data-testid=stage-next]').exists()).toBe(false)
      const prev = wrapper.get('[data-testid=stage-prev]')
      expect(prev.text()).toBe('Модуль 1')
      expect(prev.attributes('aria-label')).toBe('Предыдущий модуль: Basics')

      await prev.trigger('click')
      expect(wrapper.get('[data-testid=stage-1] [data-testid=stage-title]').text()).toBe('Basics')
    })

    it('names a module without a title by its number: "Модуль N" is the heading, with no line above and a matching bar label', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [
          { ...stage(1, [lesson(11, 1, 2, 2)]), title: 'Образование' },
          { ...stage(2, [lesson(21, 1, 0, 3)]), title: null },
        ],
      }))

      const { wrapper } = await render()

      // The first unfinished lesson is in module 2: the screen opens there.
      const bare = wrapper.get('[data-testid=stage-2]')
      expect(bare.get('[data-testid=stage-title]').text()).toBe('Модуль 2')
      expect(bare.find('[data-testid=stage-of]').exists()).toBe(false)
      expect(bare.get('[data-testid=progress-bar]').attributes('aria-label')).toBe('Модуль 2')
      expect(bare.get('[data-testid=lesson-21]').text()).toContain('Урок 1')
      expect(wrapper.text()).not.toContain('Stage')
      expect(wrapper.text()).not.toContain('null')

      await wrapper.get('[data-testid=stage-prev]').trigger('click')
      const named = wrapper.get('[data-testid=stage-1]')
      expect(named.get('[data-testid=stage-title]').text()).toBe('Образование')
      expect(named.get('[data-testid=progress-bar]').attributes('aria-label')).toBe('Образование')
    })

    it('treats a blank title like no title', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [{ ...stage(3, []), title: '   ' }] }))

      const { wrapper } = await render()

      const bare = wrapper.get('[data-testid=stage-3]')
      expect(bare.get('[data-testid=stage-title]').text()).toBe('Модуль 3')
      expect(bare.find('[data-testid=stage-of]').exists()).toBe(false)
    })

    it('shows a lesson as its number in the column ("01") and its title, with the words', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail())

      const { wrapper } = await render()

      const first = wrapper.get('[data-testid=lesson-11]')
      const number = first.get('[data-testid=lesson-status]')
      expect(number.text()).toBe('01')
      expect(number.classes()).toContain('font-mono')
      expect(first.get('[data-testid=lesson-title]').text()).toBe('Greetings')
      expect(first.get('[data-testid=lesson-progress]').text()).toBe('2 из 3 слов')

      // No title: "Урок N"; nothing learned yet: just the size of the lesson.
      const untitled = wrapper.get('[data-testid=lesson-12]')
      expect(untitled.get('[data-testid=lesson-status]').text()).toBe('02')
      expect(untitled.get('[data-testid=lesson-title]').text()).toBe('Урок 2')
      expect(untitled.get('[data-testid=lesson-progress]').text()).toBe('2 слова')
    })

    it.each([
      [1, '1 из 1 слова'],
      [2, '1 из 2 слов'],
      [5, '1 из 5 слов'],
      [11, '1 из 11 слов'],
      [21, '1 из 21 слова'],
      [22, '1 из 22 слов'],
    ])('agrees «слов» with the size of the lesson: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 1, total)])] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-progress]').text()).toBe(expected)
    })

    it.each([
      [1, '1 слово'],
      [3, '3 слова'],
      [10, '10 слов'],
    ])('names the size of a lesson not begun: %i -> %s', async (total, expected) => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 0, total)])] }))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-progress]').text()).toBe(expected)
    })

    it('marks the status of a lesson: learned, current, started, not started', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 1, 3), lesson(13, 3, 0, 3), lesson(14, 4, 0, 0)])],
      }))

      const { wrapper } = await render()

      const row = (id: number) => wrapper.get(`[data-testid=lesson-${id}]`)

      // Learned: a quiet check on the right, named for a screen reader; the title stays in the text colour.
      expect(row(11).attributes('data-state')).toBe('done')
      const done = row(11).get('[data-testid=lesson-done]')
      expect(done.attributes('role')).toBe('img')
      expect(done.attributes('aria-label')).toBe('Пройден')
      expect(done.classes()).toContain('text-success')
      expect(row(11).get('[data-testid=lesson-title]').classes()).toContain('font-semibold')

      // Started (not the learner's course: nothing is "current" here).
      expect(row(12).attributes('data-state')).toBe('started')
      expect(row(12).find('[data-testid=lesson-done]').exists()).toBe(false)
      expect(row(12).find('[data-testid=lesson-here]').exists()).toBe(false)

      // Not started (and an empty lesson): a quiet title.
      for (const id of [13, 14]) {
        expect(row(id).attributes('data-state')).toBe('new')
        expect(row(id).get('[data-testid=lesson-title]').classes()).toContain('text-muted')
        expect(row(id).find('[data-testid=lesson-done]').exists()).toBe(false)
      }
      expect(wrapper.find('.bg-accent-soft').exists()).toBe(false)
    })

    it('offers to learn every lesson that has words, as a small text button', async () => {
      vi.mocked(api.courses.get).mockResolvedValue(detail({
        stages: [stage(1, [lesson(11, 1, 2, 3, 'Greetings'), lesson(12, 2, 0, 0), lesson(13, 3, 3, 3)])],
      }))

      const { wrapper } = await render()

      const learn = wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]')
      expect(learn.text()).toBe('Учить')
      expect(learn.classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain', 'btn-sm']))
      expect(learn.attributes('disabled')).toBeUndefined()
      // A learned lesson can be taken again.
      expect(wrapper.get('[data-testid=lesson-13]').get('[data-testid=learn-btn]').attributes('disabled')).toBeUndefined()
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
    expect(chips(bare)).toEqual([])
    expect(bare.get('[data-testid=progress-bar]').attributes('aria-label')).toBe('2-modul')
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    vi.mocked(api.courses.get).mockResolvedValue(detail({ stages: [stage(1, [lesson(11, 1, 2, 3, 'Greetings'), lesson(12, 2, 0, 3)])] }))

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=stage-1]').text()).toContain('1-modul')
    expect(wrapper.get('[data-testid=stage-1] [data-testid=stage-of]').text()).toBe('1-modul, jami 1 ta')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-title]').text()).toBe('Greetings')
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-title]').text()).toBe('2-dars')
    expect(wrapper.get('[data-testid=lesson-11] [data-testid=lesson-progress]').text()).toBe("3 ta so'zdan 2 tasi")
    expect(wrapper.get('[data-testid=lesson-12] [data-testid=lesson-progress]').text()).toBe("3 ta so'z")
    expect(wrapper.get('[data-testid=progress-text]').text()).toBe("5 tadan 2 tasi o'rganildi")
    expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Kursni boshlash')
    expect(wrapper.get('[data-testid=choose-hint]').text()).toBe("Kunlik mashg'ulotdagi yangi so'zlar shu kursdan bo'ladi")
    expect(wrapper.get('[data-testid=lesson-11]').get('[data-testid=learn-btn]').text()).toBe("O'rganish")

    vi.mocked(api.courses.get).mockResolvedValue(detail({ exam_tag: 'ielts', is_active: true }))
    const main = await render()
    expect(main.wrapper.get('[data-testid=mock-exam]').get('h2').text()).toBe('Sinov imtihoni')
    expect(main.wrapper.get('[data-testid=mock-exam-btn]').text()).toBe('Testni boshlash')
    expect(main.wrapper.get('[data-testid=active-badge]').text()).toBe("Siz shu kursni o'rganyapsiz")
    expect(main.wrapper.get('[data-testid=continue-btn]').text()).toBe("Darsni o'rganish")
    expect(main.wrapper.get('[data-testid=lesson-here]').text()).toBe('siz shu yerdasiz')

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
