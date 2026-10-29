import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CourseView from '../CourseView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { CourseDetail, MeResponse, PracticeAccess } from '@/api/types'

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

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {}, webApp: { BackButton: { hide: () => {} } } }),
}))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' } },
      emits: ['close', 'review'],
      setup: (props, { emit }) => () =>
        h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }, [h('button', { 'data-testid': 'paywall-close', onClick: () => emit('close') })]),
    }),
  }
})

const detail = (overrides: Partial<CourseDetail> = {}): CourseDetail => ({
  slug: 'english-a1',
  title: 'Английский A1',
  description: null,
  level: 'A1',
  exam_tag: null,
  words_count: 6,
  is_active: true,
  progress: { done: 2, total: 5 },
  stages: [{ number: 1, title: 'Basics', progress: { done: 2, total: 5 }, lessons: [{ id: 11, number: 1, title: 'Greetings', progress: { done: 2, total: 3 } }] }],
  ...overrides,
})

const me = (practice?: PracticeAccess): MeResponse => ({
  learner: { id: 1, first_name: 'Асадбек', locale: 'ru', onboarding_completed: true, username: null },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
  ...(practice ? { practice } : {}),
})

const access = (overrides: Partial<PracticeAccess> = {}): PracticeAccess => ({
  available: true,
  pro_required: false,
  modes: ['pairs', 'collocation', 'sentence', 'dictation'],
  battle: true,
  ...overrides,
})

async function render(practice: PracticeAccess | undefined, course: CourseDetail = detail()) {
  vi.mocked(api.courses.get).mockResolvedValue(course)
  vi.mocked(api.me.get).mockResolvedValue(me(practice))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
  await router.push({ name: 'course', params: { slug: course.slug } })
  const wrapper = mount(CourseView, { props: { slug: course.slug }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('CourseView — the entry to practice (docs/16 §11.1)', () => {
  it('is a tinted, full-width "Practice" under the main button, when practice is available', async () => {
    const { wrapper } = await render(access())

    const button = wrapper.get('[data-testid=practice-btn]')
    expect(button.text()).toBe('Практика')
    expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
    expect(button.classes()).not.toContain('btn-primary')
    expect(wrapper.find('[data-testid=practice-pro]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('stands right after the main button of the screen', async () => {
    const { wrapper } = await render(access())

    const html = wrapper.html()
    expect(html.indexOf('data-testid="continue-btn"')).toBeGreaterThan(-1)
    expect(html.indexOf('data-testid="practice-btn"')).toBeGreaterThan(html.indexOf('data-testid="continue-btn"'))
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    wrapper.unmount()
  })

  it('stands under "Make main" of a course that is not the main one', async () => {
    const { wrapper } = await render(access(), detail({ is_active: false }))

    const html = wrapper.html()
    expect(html.indexOf('data-testid="choose-btn"')).toBeGreaterThan(-1)
    expect(html.indexOf('data-testid="practice-btn"')).toBeGreaterThan(html.indexOf('data-testid="choose-btn"'))
    wrapper.unmount()
  })

  it('leads to the hub of the course', async () => {
    const { wrapper, router } = await render(access())

    await wrapper.get('[data-testid=practice-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('practice-hub')
    expect(router.currentRoute.value.params.slug).toBe('english-a1')
    expect(router.currentRoute.value.query).toEqual({})
    wrapper.unmount()
  })

  it('is not there when practice is not available to the learner', async () => {
    const { wrapper } = await render(access({ available: false, modes: [], battle: false }))

    expect(wrapper.find('[data-testid=practice-btn]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('is not there while `/me` says nothing of practice (an older server, a failed request)', async () => {
    const without = await render(undefined)
    expect(without.wrapper.find('[data-testid=practice-btn]').exists()).toBe(false)
    without.wrapper.unmount()

    vi.mocked(api.me.get).mockRejectedValue(new Error('offline'))
    const failed = await render(access())
    expect(failed.wrapper.find('[data-testid=practice-btn]').exists()).toBe(false)
    expect(failed.wrapper.find('[data-testid=continue-btn]').exists()).toBe(true)
    failed.wrapper.unmount()
  })

  it('asks `/me` once (the store keeps it for half a minute)', async () => {
    const { wrapper } = await render(access())

    expect(api.me.get).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('leaves the rows of the lessons as they were (the practice of a lesson is a later task)', async () => {
    const { wrapper } = await render(access())

    const row = wrapper.get('[data-testid=lesson-11]')
    expect(row.findAll('button')).toHaveLength(1)
    expect(row.find('[data-testid=learn-btn]').exists()).toBe(true)
    wrapper.unmount()
  })

  describe('behind Pro (`PRACTICE_PLAN=pro`, a Free learner)', () => {
    it('wears a Pro chip', async () => {
      const { wrapper } = await render(access({ pro_required: true }))

      expect(wrapper.get('[data-testid=practice-btn]').text()).toContain('Практика')
      expect(wrapper.get('[data-testid=practice-pro]').text()).toBe('Pro')
      wrapper.unmount()
    })

    it('opens the paywall for practice instead of the hub, and the paywall closes', async () => {
      const { wrapper, router } = await render(access({ pro_required: true }))

      await wrapper.get('[data-testid=practice-btn]').trigger('click')

      expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice')
      expect(router.currentRoute.value.name).toBe('course')

      await wrapper.get('[data-testid=paywall-close]').trigger('click')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      wrapper.unmount()
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(access())

    expect(wrapper.get('[data-testid=practice-btn]').text()).toBe('Amaliyot')
    wrapper.unmount()
  })
})
