import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import MyCourseView from '../MyCourseView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useTodayStore } from '@/stores/today'
import type { Course, TodayResponse } from '@/api/types'

const tg = vi.hoisted(() => ({ showBack: vi.fn(), hideBack: vi.fn() }))

vi.mock('@/api', () => ({
  api: { courses: { list: vi.fn(), get: vi.fn(), select: vi.fn() } },
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
  description: null,
  level: 'A1',
  exam_tag: null,
  words_count: 5,
  is_featured: false,
  is_active: false,
  progress: { done: 0, total: 5 },
  ...overrides,
})

async function render() {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/course', name: 'my-course', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
    ],
  })
  await router.push('/today')
  await router.push('/course')
  const wrapper = mount(MyCourseView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('MyCourseView: the "Course" tab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('shows the skeleton of the course screen while it looks the course up', async () => {
    vi.mocked(api.courses.list).mockReturnValue(new Promise(() => {}))

    const { wrapper, router } = await render()

    expect(wrapper.get('[data-testid=spinner]').attributes('role')).toBe('status')
    // the shapes of the course screen: the card of the course, the rows of the path with their pictures
    expect(wrapper.find('.skeleton.size-16').exists()).toBe(true)
    expect(router.currentRoute.value.name).toBe('my-course')
  })

  it('puts the learner course in its place: "Back" from the course does not land here', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [course(), course({ slug: 'ielts', is_active: true })] })

    const { router } = await render()

    expect(api.courses.list).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('course')
    expect(router.currentRoute.value.params.slug).toBe('ielts')
    // replaced, not pushed: the screen before it in the history is the one before the tab
    router.back()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('today')
  })

  it('opens the catalog when the learner has no course yet', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [course(), course({ slug: 'b' })] })

    const { router } = await render()

    expect(router.currentRoute.value.name).toBe('courses')
  })

  it('explains a failed lookup and retries', async () => {
    vi.mocked(api.courses.list)
      .mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'offline'))
      .mockResolvedValueOnce({ courses: [course({ is_active: true })] })

    const { wrapper, router } = await render()
    expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
    expect(router.currentRoute.value.name).toBe('my-course')

    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('course')
    expect(router.currentRoute.value.params.slug).toBe('english-a1')
  })

  it('takes the active course from a fresh /today without asking for the catalog', async () => {
    const today = useTodayStore()
    today.data = { active_course: { slug: 'ielts' } } as unknown as TodayResponse
    today.loadedAt = Date.now()

    const { router } = await render()

    expect(api.courses.list).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('course')
    expect(router.currentRoute.value.params.slug).toBe('ielts')
  })

  it('asks for the catalog when /today is stale or names no course', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [course({ slug: 'b', is_active: true })] })
    const today = useTodayStore()
    today.data = { active_course: { slug: 'ielts' } } as unknown as TodayResponse
    today.loadedAt = Date.now() - 60_000

    const { router } = await render()
    expect(api.courses.list).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.params.slug).toBe('b')

    today.data = { active_course: null } as unknown as TodayResponse
    today.loadedAt = Date.now()
    const again = await render()
    expect(api.courses.list).toHaveBeenCalledTimes(2)
    expect(again.router.currentRoute.value.params.slug).toBe('b')
  })

  it('does not pull the learner back when they left the tab before the lookup answered', async () => {
    let answer: (value: { courses: Course[] }) => void = () => {}
    vi.mocked(api.courses.list).mockReturnValue(new Promise((resolve) => { answer = resolve }))

    const { wrapper, router } = await render()
    const replace = vi.spyOn(router, 'replace')
    await router.push('/today')
    wrapper.unmount()
    answer({ courses: [course({ is_active: true })] })
    await flushPromises()

    // (the router forgets its route once its last app is unmounted: the spy is the witness)
    expect(replace).not.toHaveBeenCalled()
  })

  it('does not replace another screen the learner moved to while the view is still mounted', async () => {
    let answer: (value: { courses: Course[] }) => void = () => {}
    vi.mocked(api.courses.list).mockReturnValue(new Promise((resolve) => { answer = resolve }))

    const { router } = await render()
    const replace = vi.spyOn(router, 'replace')
    await router.push('/courses')
    answer({ courses: [course({ is_active: true })] })
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('courses')
  })

  it('is a tab root: the system Back button is hidden', async () => {
    vi.mocked(api.courses.list).mockReturnValue(new Promise(() => {}))

    await render()

    expect(tg.hideBack).toHaveBeenCalledTimes(1)
    expect(tg.showBack).not.toHaveBeenCalled()
  })
})
