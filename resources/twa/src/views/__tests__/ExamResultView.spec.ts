import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ExamResultView from '../ExamResultView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { AnyExamResultResponse } from '@/api/types'

// The result screen of a personal IELTS / SAT mock (task 2D.4): percent + percentile, no leaderboard.
// The group-exam result keeps its leaderboard.

vi.mock('@/api', () => ({
  api: { exam: { result: vi.fn(), join: vi.fn(), question: vi.fn(), answer: vi.fn(), openMock: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ close: vi.fn(), notificationHaptic: vi.fn() }),
}))

async function render(result: AnyExamResultResponse) {
  vi.mocked(api.exam.result).mockResolvedValue(result)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/exam/:sessionId(\\d+)/result', name: 'exam-result', component: { template: '<div />' } },
    ],
  })
  await router.push('/exam/9/result')
  const wrapper = mount(ExamResultView, { props: { sessionId: 9 }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('ExamResultView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('shows percent, unanswered questions and the percentile of a mock', async () => {
    const { wrapper, router } = await render({
      mode: 'personal_mock', correct: 30, total: 40, answered: 38, percent: 75, percentile: 82, attempts: 9, course_id: 3,
    })

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Итоги пробного теста')
    expect(wrapper.get('[data-testid=mock-percent]').text()).toBe('75%')
    expect(wrapper.get('[data-testid=mock-score]').text()).toBe('30 из 40 · 75%')
    expect(wrapper.get('[data-testid=mock-unanswered]').text()).toBe('Без ответа: 2')
    expect(wrapper.get('[data-testid=mock-percentile]').text()).toBe('Лучше или наравне с 82% результатов по этому курсу за 90 дней')
    expect(wrapper.get('[data-testid=mock-attempts]').text()).toBe('Попыток в сравнении: 9')
    expect(wrapper.find('[data-testid=leaderboard]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=final-score]').exists()).toBe(false)

    await wrapper.get('[data-testid=mock-courses-btn]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('courses')
  })

  it('explains a first result without a comparison', async () => {
    const { wrapper } = await render({
      mode: 'personal_mock', correct: 40, total: 40, answered: 40, percent: 100, percentile: 100, attempts: 1, course_id: 3,
    })

    expect(wrapper.get('[data-testid=mock-percentile]').text()).toBe('Первый результат по этому курсу за 90 дней — сравнить пока не с чем')
    expect(wrapper.find('[data-testid=mock-unanswered]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mock-attempts]').exists()).toBe(false)
  })

  it('keeps the leaderboard of a group exam', async () => {
    const { wrapper } = await render({
      learner_score: 42, rank: 2, total_participants: 7, leaderboard: [{ rank: 1, name: 'Anna', score: 50 }],
    })

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Итоги экзамена')
    expect(wrapper.get('[data-testid=final-score]').text()).toBe('42 ★')
    expect(wrapper.get('[data-testid=final-rank]').text()).toBe('Место 2 из 7')
    expect(wrapper.get('[data-testid=leader-1]').text()).toContain('Anna')
    expect(wrapper.find('[data-testid=mock-result]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mock-courses-btn]').exists()).toBe(false)
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    const { wrapper } = await render({
      mode: 'personal_mock', correct: 10, total: 40, answered: 40, percent: 25, percentile: 13, attempts: 8, course_id: 3,
    })

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Sinov testi natijalari')
    expect(wrapper.get('[data-testid=mock-score]').text()).toBe('40 tadan 10 ta · 25%')
    expect(wrapper.get('[data-testid=mock-percentile]').text()).toContain('13%')
  })
})
