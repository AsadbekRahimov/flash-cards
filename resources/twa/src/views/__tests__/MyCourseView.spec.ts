import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import MyCourseView from '../MyCourseView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { Course } from '@/api/types'

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
    expect(wrapper.find('.bg-highlight').exists()).toBe(true)
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

  it('is a tab root: the system Back button is hidden', async () => {
    vi.mocked(api.courses.list).mockReturnValue(new Promise(() => {}))

    await render()

    expect(tg.hideBack).toHaveBeenCalledTimes(1)
    expect(tg.showBack).not.toHaveBeenCalled()
  })
})
