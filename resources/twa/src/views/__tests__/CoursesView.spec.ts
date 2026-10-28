import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CoursesView from '../CoursesView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { Course } from '@/api/types'

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

async function render() {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/decks', name: 'decks', component: stub },
    ],
  })
  await router.push('/courses')
  const wrapper = mount(CoursesView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
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
  })
  afterEach(() => setLocale('ru'))

  describe('heading and switch', () => {
    it('has a large "Курсы" heading, the Courses | My decks switch and the hint about the main course', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })

      const { wrapper } = await render()

      const heading = wrapper.get('h1')
      expect(heading.text()).toBe('Курсы')
      expect(heading.classes()).toContain('text-[28px]')

      const courses = wrapper.get('[data-testid=seg-courses]')
      const decks = wrapper.get('[data-testid=seg-decks]')
      expect(courses.text()).toBe('Курсы')
      expect(courses.attributes('aria-current')).toBe('page')
      expect(decks.text()).toBe('Мои колоды')
      expect(decks.attributes('href')).toBe('/decks')
      expect(decks.attributes('aria-current')).toBeUndefined()

      expect(wrapper.get('[data-testid=hint]').text()).toBe('Новые слова для ежедневного занятия берутся из основного курса')
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

  describe('cards', () => {
    it('renders a card per course with title, chips, description and progress', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [course(), course({ slug: 'ielts', title: 'IELTS', description: null, level: null, exam_tag: 'ielts', words_count: 1, progress: { done: 0, total: 1 } })],
      })

      const { wrapper } = await render()

      const first = wrapper.get('[data-testid=course-english-a1]')
      expect(first.get('h2').text()).toBe('Английский A1')
      expect(first.text()).toContain('Первые слова')
      expect(chips(first.get('[data-testid=meta]'))).toEqual([
        { text: 'A1', tone: 'chip-neutral' },
        { text: 'Общий английский', tone: 'chip-neutral' },
        { text: '5 слов', tone: 'chip-neutral' },
      ])
      expect(first.get('[data-testid=progress-text]').text()).toBe('Изучено 2 из 5')
      expect(first.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('40')
      expect(first.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 40%')

      const second = wrapper.get('[data-testid=course-ielts]')
      // No level, no description; the exam goal is the accent chip.
      expect(chips(second.get('[data-testid=meta]'))).toEqual([
        { text: 'IELTS', tone: 'chip-accent' },
        { text: '1 слово', tone: 'chip-neutral' },
      ])
      expect(second.find('p.line-clamp-2').exists()).toBe(false)
      expect(wrapper.findAll('[data-testid=course-link]')).toHaveLength(2)
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

      expect(chips(wrapper.get('[data-testid=course-sat] [data-testid=meta]'))[0]).toEqual({ text: 'SAT', tone: 'chip-accent' })
      expect(chips(wrapper.get('[data-testid=course-toefl] [data-testid=meta]'))[0]).toEqual({ text: 'toefl', tone: 'chip-neutral' })
      expect(chips(wrapper.get('[data-testid=course-plain] [data-testid=meta]'))).toEqual([{ text: '5 слов', tone: 'chip-neutral' }])
    })

    it('clamps a long description to two lines', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course({ description: 'Очень длинное описание. '.repeat(20) })] })

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=course-english-a1] p.line-clamp-2').text()).toContain('Очень длинное описание.')
    })

    it('links the top of a card to the course page', async () => {
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
    it('marks the main course with a chip and offers "Сделать основным" on the others', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [course({ is_active: true }), course({ slug: 'other', title: 'Other' })],
      })

      const { wrapper } = await render()

      const active = wrapper.get('[data-testid=course-english-a1]')
      const badge = active.get('[data-testid=active-badge]')
      expect(badge.text()).toBe('Основной курс')
      expect(badge.element.tagName).toBe('SPAN')
      expect(badge.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
      expect(badge.find('svg').exists()).toBe(true)
      expect(active.find('[data-testid=choose-btn]').exists()).toBe(false)

      const choose = wrapper.get('[data-testid=course-other] [data-testid=choose-btn]')
      expect(choose.text()).toBe('Сделать основным')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
      expect(wrapper.find('[data-testid=course-other] [data-testid=active-badge]').exists()).toBe(false)
    })

    it('selects a course and moves the main mark to it', async () => {
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

    it('disables every "Сделать основным" while a selection is in flight', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({
        courses: [course({ is_active: true }), course({ slug: 'b', title: 'B' }), course({ slug: 'c', title: 'C' })],
      })
      let finish: (value: Course) => void = () => {}
      vi.mocked(api.courses.select).mockReturnValue(new Promise<Course>((resolve) => (finish = resolve)))

      const { wrapper } = await render()
      const buttons = () => wrapper.findAll('[data-testid=choose-btn]')
      expect(buttons().map((b) => b.attributes('disabled'))).toEqual([undefined, undefined])

      await buttons()[0]?.trigger('click')
      expect(buttons().map((b) => b.attributes('disabled') !== undefined)).toEqual([true, true])

      finish(course({ slug: 'b', title: 'B', is_active: true }))
      await flushPromises()
      expect(wrapper.find('[data-testid=course-b] [data-testid=active-badge]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=course-english-a1] [data-testid=choose-btn]').attributes('disabled')).toBeUndefined()
    })

    it('keeps the list and shows the reason in an alert when selecting fails', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [course()] })
      vi.mocked(api.courses.select).mockRejectedValue(
        new ApiClientError('not_found', 404, 'course_not_found', 'Course not found.'),
      )

      const { wrapper } = await render()
      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      await flushPromises()

      const alert = wrapper.get('[data-testid=select-error]')
      expect(alert.text()).toBe('Курс не найден или недоступен.')
      expect(alert.attributes('role')).toBe('alert')
      expect(wrapper.find('[data-testid=course-english-a1]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeUndefined()
    })
  })

  describe('states', () => {
    it('shows a skeleton while the list loads', async () => {
      vi.mocked(api.courses.list).mockReturnValue(new Promise(() => {}))

      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=spinner]').attributes('role')).toBe('status')
      expect(wrapper.find('[data-testid^=course-]').exists()).toBe(false)
    })

    it('shows an empty state with an icon when there are no courses', async () => {
      vi.mocked(api.courses.list).mockResolvedValue({ courses: [] })

      const { wrapper } = await render()

      const empty = wrapper.get('[data-testid=empty]')
      expect(empty.text()).toBe('Курсов пока нет. Загляните позже.')
      expect(empty.find('svg').exists()).toBe(true)
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
    expect(wrapper.get('[data-testid=hint]').text()).toBe("Kunlik mashg'ulot uchun yangi so'zlar asosiy kursdan olinadi")
    const first = wrapper.get('[data-testid=course-english-a1]')
    expect(chips(first.get('[data-testid=meta]')).map((c) => c.text)).toEqual(['A1', 'Umumiy ingliz tili', "5 ta so'z"])
    expect(first.get('[data-testid=progress-text]').text()).toBe("5 tadan 2 tasi o'rganildi")
    expect(first.get('[data-testid=choose-btn]').text()).toBe('Asosiy qilish')
    expect(wrapper.get('[data-testid=course-a] [data-testid=active-badge]').text()).toBe('Asosiy kurs')
  })
})
