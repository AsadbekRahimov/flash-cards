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
  api: { exam: { result: vi.fn(), join: vi.fn(), question: vi.fn(), answer: vi.fn(), openMock: vi.fn() }, today: { get: vi.fn() } },
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
      { path: '/courses/:slug/readiness', name: 'readiness', component: { template: '<div />' } },
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

  /** The number and the caption of one cell of the card of numbers. */
  function stat(wrapper: Awaited<ReturnType<typeof render>>['wrapper'], testid: string) {
    const cell = wrapper.get(`[data-testid=${testid}]`)
    return { value: cell.get('[data-testid=stat-value]').text(), label: cell.get('[data-testid=stat-label]').text() }
  }

  it('shows percent, unanswered questions and the percentile of a mock', async () => {
    const { wrapper, router } = await render({
      mode: 'personal_mock', correct: 30, total: 40, answered: 38, percent: 75, percentile: 82, attempts: 9, course_id: 3,
    })

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Итоги пробного теста')
    expect(wrapper.get('[data-testid=result-eyebrow]').text()).toBe('Ваш результат')
    expect(stat(wrapper, 'mock-percent')).toEqual({ value: '75%', label: 'результат' })
    expect(stat(wrapper, 'mock-score')).toEqual({ value: '30', label: 'верно из 40' })
    expect(stat(wrapper, 'mock-unanswered')).toEqual({ value: '2', label: 'без ответа' })
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
    // Three numbers always: nothing unanswered is a 0, not a missing cell.
    expect(stat(wrapper, 'mock-unanswered').value).toBe('0')
    expect(wrapper.find('[data-testid=mock-attempts]').exists()).toBe(false)
  })

  it('keeps the leaderboard of a group exam', async () => {
    const { wrapper } = await render(group())

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Итоги экзамена')
    expect(wrapper.get('[data-testid=result-eyebrow]').text()).toBe('Ваш результат')
    expect(wrapper.get('[data-testid=final-score]').text()).toBe('42')
    expect(stat(wrapper, 'final-rank')).toEqual({ value: '2', label: 'место из 7' })
    expect(wrapper.get('[data-testid=leader-1]').text()).toContain('Anna')
    expect(wrapper.find('[data-testid=mock-result]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mock-courses-btn]').exists()).toBe(false)
  })

  it('says "Your result" in the polite form, never the informal "Твой"', async () => {
    const { wrapper } = await render(group())

    expect(wrapper.text()).toContain('Ваш результат')
    expect(wrapper.text()).not.toContain('Твой')
  })

  it('does not celebrate: no flag, no star, the numbers monospace in one card split by thin lines', async () => {
    const { wrapper } = await render(group())

    expect(wrapper.findAllComponents(AppIcon)).toHaveLength(0)
    expect(wrapper.get('[data-testid=final-score]').classes()).toContain('font-mono')
    expect(wrapper.get('[data-testid=final-rank] [data-testid=stat-value]').classes()).toContain('font-mono')
    expect(wrapper.get('[data-testid=final-rank]').classes()).toEqual(expect.arrayContaining(['border-l', 'border-divider']))
    expect(wrapper.get('[data-testid=exam-result]').text()).not.toContain('★')
  })

  it('draws the three numbers of a test in one card with lines between them, like the end of a session', async () => {
    const { wrapper } = await render({
      mode: 'personal_mock', correct: 30, total: 40, answered: 38, percent: 75, percentile: 82, attempts: 9, course_id: 3,
    })

    const cells = ['mock-percent', 'mock-score', 'mock-unanswered'].map((id) => wrapper.get(`[data-testid=${id}]`))
    expect(cells[0]?.element.parentElement?.classList.contains('grid-cols-3')).toBe(true)
    expect(cells[0]?.classes()).not.toContain('border-l')
    for (const cell of cells.slice(1)) expect(cell.classes()).toEqual(expect.arrayContaining(['border-l', 'border-divider']))
    for (const cell of cells) expect(cell.get('[data-testid=stat-value]').classes()).toContain('font-mono')
  })

  it('lists the top as a grouped list: the place (no medals), the name, the score on the right', async () => {
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
    expect(board.text()).not.toMatch(/[🥇🥈🥉]/u)
    for (const rank of [1, 2, 3, 4]) {
      expect(row(rank).get('[data-testid=leader-place]').text()).toBe(String(rank))
      expect(row(rank).get('[data-testid=leader-place]').classes()).toContain('font-mono')
    }
    expect(row(4).text()).toContain('Kamila')
    // Scores are grouped like every number of the app («1 200»: a no-break space inside).
    expect(row(4).text().replace(/\s/g, '')).toContain('Kamila1200')
    expect(row(4).text()).not.toContain('1200')
    expect(wrapper.get('[data-testid=final-score]').text().replace(/\s/g, '')).toBe('1340')
    expect(wrapper.get('[data-testid=final-score]').text()).not.toBe('1340')
  })

  it('keeps every row of a tie: equal scores share a place, and a row is told by its position', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { wrapper } = await render(group({
      rank: 2,
      total_participants: 5,
      leaderboard: [
        { rank: 1, name: 'Anna', score: 1720 },
        { rank: 2, name: 'Timur', score: 1610 },
        { rank: 2, name: 'Dilnoza', score: 1610 },
        { rank: 4, name: 'Kamila', score: 1200 },
      ],
    }))

    const ids = wrapper.get('[data-testid=leaderboard]').findAll('.list-row').map((row) => row.attributes('data-testid'))
    expect(ids).toEqual(['leader-1', 'leader-2', 'leader-3', 'leader-4'])
    // Both tied rows are drawn, with the place they share.
    expect(wrapper.get('[data-testid=leader-2]').text()).toContain('Timur')
    expect(wrapper.get('[data-testid=leader-2] [data-testid=leader-place]').text()).toBe('2')
    expect(wrapper.get('[data-testid=leader-3]').text()).toContain('Dilnoza')
    expect(wrapper.get('[data-testid=leader-3] [data-testid=leader-place]').text()).toBe('2')
    expect(wrapper.get('[data-testid=leader-4]').text()).toContain('Kamila')
    // A second row with the same key would be a Vue warning (and a glitch on the next update).
    expect(warn.mock.calls.flat().join('\n')).not.toContain('Duplicate keys')
    warn.mockRestore()
  })

  it('has no table when nobody has a result yet, and says the places are still being counted', async () => {
    const { wrapper } = await render(group({ rank: null, leaderboard: [] }))

    expect(wrapper.find('[data-testid=leaderboard]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=final-rank]').text()).toBe('Результаты ещё считаются…')
    expect(wrapper.find('[data-testid=final-rank] [data-testid=stat-value]').exists()).toBe(false)
  })

  it('has "Close" as the only primary button, at the bottom', async () => {
    const { wrapper } = await render(group())

    const close = wrapper.get('[data-testid=close-btn]')
    expect(close.text()).toBe('Выйти в Telegram')
    expect(close.classes()).toEqual(expect.arrayContaining(['btn-primary', 'btn-lg', 'btn-block']))
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    expect(wrapper.get('[data-testid=actions]').find('[data-testid=close-btn]').exists()).toBe(true)

    await close.trigger('click')
    expect(state.close).toHaveBeenCalledTimes(1)
  })

  it('offers a mock "To courses" as a text button under "Close"', async () => {
    const { wrapper } = await render({
      mode: 'personal_mock', correct: 10, total: 40, answered: 40, percent: 25, percentile: 13, attempts: 8, course_id: 3,
    })

    const courses = wrapper.get('[data-testid=mock-courses-btn]')
    expect(courses.text()).toBe('К курсам')
    expect(courses.classes()).toContain('btn-plain')
    expect(courses.classes()).not.toContain('btn-primary')
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    const buttons = wrapper.get('[data-testid=actions]').findAll('button').map((b) => b.attributes('data-testid'))
    expect(buttons).toEqual(['close-btn', 'mock-courses-btn'])
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

  describe('test of the week', () => {
    const quiz = (overrides: Record<string, unknown> = {}): AnyExamResultResponse => ({
      mode: 'level_quiz', correct: 8, total: 12, answered: 11, percent: 66, scored: true,
      week_start: '2026-11-16', course_id: 3, course_slug: 'english-a1-a2', ...overrides,
    }) as AnyExamResultResponse

    it('shows "N of M" and that the scored test of the week was recorded', async () => {
      const { wrapper } = await render(quiz())

      expect(wrapper.get('[data-testid=result-title]').text()).toBe('Итоги теста недели')
      expect(stat(wrapper, 'quiz-score')).toEqual({ value: '8', label: 'верно из 12' })
      expect(stat(wrapper, 'quiz-percent')).toEqual({ value: '66%', label: 'результат' })
      expect(stat(wrapper, 'quiz-unanswered')).toEqual({ value: '1', label: 'без ответа' })
      expect(wrapper.get('[data-testid=quiz-scored]').text()).toContain('Зачётный тест недели')
      expect(wrapper.find('[data-testid=leaderboard]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=mock-result]').exists()).toBe(false)
    })

    it('says a practice attempt was not recorded', async () => {
      const { wrapper } = await render(quiz({ scored: false, answered: 12 }))

      expect(wrapper.get('[data-testid=quiz-practice]').text()).toBe('Тренировочная попытка — не идёт в зачёт недели')
      expect(stat(wrapper, 'quiz-unanswered').value).toBe('0')
    })

    it('leads back to the readiness screen of the course and refreshes Home after a scored test', async () => {
      vi.mocked(api.today.get).mockResolvedValue({} as never)
      const { wrapper, router } = await render(quiz())

      await wrapper.get('[data-testid=quiz-readiness-btn]').trigger('click')
      await flushPromises()

      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(router.currentRoute.value.name).toBe('readiness')
      expect(router.currentRoute.value.params.slug).toBe('english-a1-a2')
    })

    it('does not refresh Home after a practice attempt', async () => {
      const { wrapper, router } = await render(quiz({ scored: false }))

      await wrapper.get('[data-testid=quiz-readiness-btn]').trigger('click')
      await flushPromises()

      expect(api.today.get).not.toHaveBeenCalled()
      expect(router.currentRoute.value.name).toBe('readiness')
    })

    it('has "К готовности" as the one primary button and "Close" as a text button under it', async () => {
      const { wrapper } = await render(quiz())

      expect(wrapper.findAll('.btn-primary').map((b) => b.attributes('data-testid'))).toEqual(['quiz-readiness-btn'])
      expect(wrapper.get('[data-testid=close-btn]').classes()).toContain('btn-plain')
      const buttons = wrapper.get('[data-testid=actions]').findAll('button').map((b) => b.attributes('data-testid'))
      expect(buttons).toEqual(['quiz-readiness-btn', 'close-btn'])
    })

    it('renders Uzbek texts', async () => {
      setLocale('uz')
      const { wrapper } = await render(quiz())

      expect(stat(wrapper, 'quiz-score')).toEqual({ value: '8', label: "12 tadan to'g'ri" })
      expect(wrapper.get('[data-testid=quiz-readiness-btn]').text()).toBe('Tayyorlikka')
    })
  })

  it('renders Uzbek texts', async () => {
    setLocale('uz')
    const { wrapper } = await render({
      mode: 'personal_mock', correct: 10, total: 40, answered: 40, percent: 25, percentile: 13, attempts: 8, course_id: 3,
    })

    expect(wrapper.get('[data-testid=result-title]').text()).toBe('Sinov imtihoni natijasi')
    expect(stat(wrapper, 'mock-score')).toEqual({ value: '10', label: "40 tadan to'g'ri" })
    expect(stat(wrapper, 'mock-unanswered').label).toBe('javobsiz')
    expect(wrapper.get('[data-testid=mock-percentile]').text()).toContain('13 foizi')
    expect(wrapper.get('[data-testid=result-eyebrow]').text()).toBe('Sizning natijangiz')
  })
})
