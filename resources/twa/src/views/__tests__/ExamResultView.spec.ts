import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ExamResultView from '../ExamResultView.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { AnyExamResultResponse, ExamResultResponse } from '@/api/types'

// The result screen of a personal IELTS / SAT mock (task 2D.4): percent + percentile, no leaderboard.
// The group-exam result keeps its leaderboard.

vi.mock('@/api', () => ({
  api: { exam: { result: vi.fn(), join: vi.fn(), question: vi.fn(), answer: vi.fn(), openMock: vi.fn() } },
}))

const state = vi.hoisted(() => ({ close: vi.fn() }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ close: state.close, notificationHaptic: vi.fn() }),
}))

async function render(result: AnyExamResultResponse | Error) {
  if (result instanceof Error) vi.mocked(api.exam.result).mockRejectedValue(result)
  else vi.mocked(api.exam.result).mockResolvedValue(result)
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

const group = (overrides: Partial<ExamResultResponse> = {}): ExamResultResponse => ({
  learner_score: 42,
  rank: 2,
  total_participants: 7,
  leaderboard: [{ rank: 1, name: 'Anna', score: 50 }],
  ...overrides,
})

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
    expect(wrapper.get('[data-testid=mock-result]').text()).toContain('Ваш результат')
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
    const { wrapper } = await render(group())

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Итоги экзамена')
    expect(wrapper.get('[data-testid=exam-result]').text()).toContain('Ваш результат')
    expect(wrapper.get('[data-testid=final-score]').text()).toBe('42')
    expect(wrapper.get('[data-testid=final-rank]').text()).toBe('Место 2 из 7')
    expect(wrapper.get('[data-testid=leader-1]').text()).toContain('Anna')
    expect(wrapper.find('[data-testid=mock-result]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mock-courses-btn]').exists()).toBe(false)
  })

  it('says "Your result" in the polite form, never the informal "Твой"', async () => {
    const { wrapper } = await render(group())

    expect(wrapper.text()).toContain('Ваш результат')
    expect(wrapper.text()).not.toContain('Твой')
  })

  it('has a flag in a circle, the place as a chip and the score next to a star', async () => {
    const { wrapper } = await render(group())

    expect(wrapper.findAllComponents(AppIcon).map((icon) => icon.props('name'))).toEqual(['flag', 'star'])
    expect(wrapper.get('[data-testid=final-rank]').classes()).toEqual(expect.arrayContaining(['chip', 'chip-accent']))
    expect(wrapper.get('[data-testid=exam-result]').text()).not.toContain('★')
  })

  it('lists the top as a grouped list: medals for the podium, the place for the rest, the score on the right', async () => {
    const { wrapper } = await render(group({
      learner_score: 1340,
      rank: 3,
      total_participants: 14,
      leaderboard: [
        { rank: 1, name: 'Anna', score: 1720 },
        { rank: 2, name: 'Timur', score: 1610 },
        { rank: 3, name: 'Asadbek', score: 1340 },
        { rank: 4, name: 'Kamila', score: 1200 },
      ],
    }))

    const board = wrapper.get('[data-testid=leaderboard]')
    expect(board.find('.list').exists()).toBe(true)
    expect(board.text()).toContain('Топ участников')
    expect(board.findAll('.list-row')).toHaveLength(4)

    const row = (rank: number) => wrapper.get(`[data-testid=leader-${rank}]`)
    expect(row(1).text()).toContain('🥇')
    expect(row(2).text()).toContain('🥈')
    expect(row(3).text()).toContain('🥉')
    expect(row(4).text()).not.toMatch(/[🥇🥈🥉]/u)
    expect(row(4).text()).toContain('4')
    expect(row(4).text()).toContain('Kamila')
    // Scores are grouped like every number of the app («1 200»: a no-break space inside).
    expect(row(4).text().replace(/\s/g, '')).toContain('Kamila1200')
    expect(row(4).text()).not.toContain('1200')
    expect(wrapper.get('[data-testid=final-score]').text().replace(/\s/g, '')).toBe('1340')
    expect(wrapper.get('[data-testid=final-score]').text()).not.toBe('1340')
  })

  it('has no table when nobody has a result yet, and says the places are still being counted', async () => {
    const { wrapper } = await render(group({ rank: null, leaderboard: [] }))

    expect(wrapper.find('[data-testid=leaderboard]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=final-rank]').text()).toBe('Результаты ещё считаются…')
  })

  it('has "Close" as the only primary button, at the bottom', async () => {
    const { wrapper } = await render(group())

    const close = wrapper.get('[data-testid=close-btn]')
    expect(close.text()).toBe('Закрыть')
    expect(close.classes()).toEqual(expect.arrayContaining(['btn-primary', 'btn-lg', 'btn-block']))
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    expect(wrapper.get('[data-testid=actions]').find('[data-testid=close-btn]').exists()).toBe(true)

    await close.trigger('click')
    expect(state.close).toHaveBeenCalledTimes(1)
  })

  it('offers a mock "To courses" as a tinted button above "Close"', async () => {
    const { wrapper } = await render({
      mode: 'personal_mock', correct: 10, total: 40, answered: 40, percent: 25, percentile: 13, attempts: 8, course_id: 3,
    })

    const courses = wrapper.get('[data-testid=mock-courses-btn]')
    expect(courses.text()).toBe('К курсам')
    expect(courses.classes()).toContain('btn-tinted')
    expect(courses.classes()).not.toContain('btn-primary')
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    const buttons = wrapper.get('[data-testid=actions]').findAll('button').map((b) => b.attributes('data-testid'))
    expect(buttons).toEqual(['mock-courses-btn', 'close-btn'])
  })

  it('shows a failed load as an alert', async () => {
    const { wrapper } = await render(new ApiClientError('server', 500, 'internal', 'boom'))

    const alert = wrapper.get('[data-testid=result-error]')
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.text()).toBe('Сервер временно недоступен. Попробуйте позже.')
  })

  it('shows no alert when the result loaded', async () => {
    const { wrapper } = await render(group())

    expect(wrapper.find('[data-testid=result-error]').exists()).toBe(false)
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    const { wrapper } = await render({
      mode: 'personal_mock', correct: 10, total: 40, answered: 40, percent: 25, percentile: 13, attempts: 8, course_id: 3,
    })

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Sinov testi natijalari')
    expect(wrapper.get('[data-testid=mock-score]').text()).toBe('40 tadan 10 ta · 25%')
    expect(wrapper.get('[data-testid=mock-percentile]').text()).toContain('13%')
    expect(wrapper.get('[data-testid=mock-result]').text()).toContain('Sizning natijangiz')
  })
})
