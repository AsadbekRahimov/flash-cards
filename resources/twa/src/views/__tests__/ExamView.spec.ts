import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ExamView from '../ExamView.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { ExamAnswerResponse, ExamJoinResponse, ExamQuestionResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: { exam: { join: vi.fn(), question: vi.fn(), answer: vi.fn(), result: vi.fn(), openMock: vi.fn() } },
}))

// `backHandler` is what the system "Back" button of Telegram would call (useBackButton registers it).
const state = vi.hoisted(() => ({
  close: vi.fn(),
  notificationHaptic: vi.fn(),
  backHandler: null as (() => void) | null,
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: authEnsure }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: state.close,
    showBackButton: (handler: () => void) => {
      state.backHandler = handler
      return () => {
        state.backHandler = null
      }
    },
    haptic: () => {},
    notificationHaptic: state.notificationHaptic,
  }),
}))

const NOW = new Date('2026-10-28T12:00:00Z')

const question = (index: number, word: string, secondsLeft = 7): ExamQuestionResponse => ({
  question_index: index,
  word_id: 600 + index,
  word,
  options: ['окружающая среда', 'правительство', 'развитие', 'оборудование'],
  seconds_left: secondsLeft,
})

const joined = (overrides: Partial<ExamJoinResponse> = {}): ExamJoinResponse => ({
  session_id: 201,
  ends_at: new Date(NOW.getTime() + 272_000).toISOString(), // 04:32 left
  total_questions: 20,
  seconds_per_question: 10,
  question: question(2, 'environment'),
  ...overrides,
})

const answered = (overrides: Partial<ExamAnswerResponse> = {}): ExamAnswerResponse => ({
  is_correct: false,
  correct_option: 0,
  score_earned: 0,
  total_score: 120,
  has_next: true,
  next_question: question(3, 'government', 10),
  ...overrides,
})

const mounted: VueWrapper[] = []

async function render(query: Record<string, string> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/exam/:sessionId(\\d+)', name: 'exam', component: { template: '<div />' } },
      { path: '/exam/:sessionId(\\d+)/result', name: 'exam-result', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'exam', params: { sessionId: 201 }, query })
  const wrapper = mount(ExamView, { props: { sessionId: 201 }, global: { plugins: [router] }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return { wrapper, router }
}

describe('ExamView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useFakeTimers({ now: NOW })
    state.backHandler = null
    vi.mocked(api.exam.join).mockResolvedValue(joined())
  })
  afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount())
    vi.useRealTimers()
    setLocale('ru')
  })

  it('retries the join after a failed initial request instead of finishing an empty exam', async () => {
    vi.mocked(api.exam.join).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'offline'))
    const { wrapper } = await render()
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()
    expect(api.exam.join).toHaveBeenCalledTimes(2)
    expect(api.exam.result).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid=word]').text()).toBe('environment')
  })

  it('does not join an exam after the learner leaves during authentication', async () => {
    let release!: () => void
    authEnsure.mockImplementationOnce(() => new Promise<void>((resolve) => { release = resolve }))
    const { wrapper } = await render()
    wrapper.unmount()
    mounted.splice(mounted.indexOf(wrapper), 1)
    release()
    await flushPromises()
    expect(api.exam.join).not.toHaveBeenCalled()
  })

  it('shows the countdown, the counter "3 / 20" and the score in the header', async () => {
    const { wrapper } = await render()

    const timer = wrapper.get('[data-testid=overall-timer]')
    expect(timer.text()).toBe('04:32')
    expect(timer.classes()).toEqual(expect.arrayContaining(['chip', 'chip-neutral']))
    expect(timer.classes()).not.toContain('chip-danger')
    expect(timer.findComponent(AppIcon).props('name')).toBe('clock')

    expect(wrapper.get('[data-testid=progress]').text()).toBe('3 / 20')

    const score = wrapper.get('[data-testid=score]')
    expect(score.text()).toBe('0')
    expect(score.classes()).toEqual(expect.arrayContaining(['chip', 'chip-warning']))
    expect(score.findComponent(AppIcon).props()).toMatchObject({ name: 'star', solid: true })
    expect(wrapper.find('[data-testid=mock-header]').exists()).toBe(false)
  })

  it('shows the word in a card with the four options below it', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=word]').text()).toBe('environment')
    expect(wrapper.get('article').text()).toBe('environment')
    expect(wrapper.findAll('button[data-testid^=option-]')).toHaveLength(4)
    expect(wrapper.get('article').find('[data-testid=option-0]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=option-0]').classes()).toContain('option')
    expect(wrapper.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
  })

  it('turns the countdown red in the last half minute (below 30 s)', async () => {
    vi.mocked(api.exam.join).mockResolvedValue(joined({ ends_at: new Date(NOW.getTime() + 31_000).toISOString() }))
    const { wrapper } = await render()
    const timer = () => wrapper.get('[data-testid=overall-timer]')
    expect(timer().text()).toBe('00:31')
    expect(timer().classes()).toContain('chip-neutral')

    vi.advanceTimersByTime(1000) // 30 s: not yet
    await flushPromises()
    expect(timer().text()).toBe('00:30')
    expect(timer().classes()).toContain('chip-neutral')

    vi.advanceTimersByTime(1000) // 29 s
    await flushPromises()
    expect(timer().text()).toBe('00:29')
    expect(timer().classes()).toContain('chip-danger')
    expect(timer().classes()).not.toContain('chip-neutral')
  })

  it('has no countdown chip when the server sets no end time', async () => {
    vi.mocked(api.exam.join).mockResolvedValue(joined({ ends_at: null }))
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=overall-timer]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=progress]').exists()).toBe(true)
  })

  it('draws the time left for the question as a bar that turns red in the last 3 seconds', async () => {
    vi.mocked(api.exam.join).mockResolvedValue(joined({ question: question(2, 'environment', 5) }))
    const { wrapper } = await render()
    const bar = () => wrapper.get('[data-testid=question-timer]')

    expect(bar().element.parentElement?.classList.contains('progress')).toBe(true)
    expect(bar().classes()).toContain('progress-fill')
    expect(bar().attributes('style')).toContain('width: 50%')
    expect(bar().classes()).not.toContain('bg-danger')

    vi.advanceTimersByTime(1000) // 4 s left
    await flushPromises()
    expect(bar().classes()).not.toContain('bg-danger')

    vi.advanceTimersByTime(1000) // 3 s left
    await flushPromises()
    expect(bar().attributes('style')).toContain('width: 30%')
    expect(bar().classes()).toContain('bg-danger')
  })

  it('sends "no answer" when the time of the question is up, as before', async () => {
    vi.mocked(api.exam.join).mockResolvedValue(joined({ question: question(2, 'environment', 2) }))
    vi.mocked(api.exam.answer).mockResolvedValue(answered())
    await render()

    vi.advanceTimersByTime(2000)
    await flushPromises()

    expect(api.exam.answer).toHaveBeenCalledTimes(1)
    expect(api.exam.answer).toHaveBeenCalledWith(201, expect.objectContaining({ question_index: 2, selected_option_index: null }))
  })

  it('colours the options by the verdict, then moves on after 800 ms', async () => {
    vi.mocked(api.exam.answer).mockResolvedValue(answered({ is_correct: false, correct_option: 0, total_score: 120 }))
    const { wrapper } = await render()

    await wrapper.get('[data-testid=option-2]').trigger('click')
    await flushPromises()

    expect(api.exam.answer).toHaveBeenCalledWith(201, expect.objectContaining({ question_index: 2, selected_option_index: 2 }))
    const states = [0, 1, 2, 3].map((i) => wrapper.get(`[data-testid=option-${i}]`).attributes('data-state'))
    expect(states).toEqual(['reveal', 'idle', 'incorrect', 'idle'])
    expect(wrapper.get('[data-testid=option-2]').findComponent(AppIcon).props('name')).toBe('x-mark')
    expect(wrapper.get('[data-testid=score]').text()).toBe('120')
    expect(state.notificationHaptic).toHaveBeenCalledWith('error')

    // The question stays for 800 ms, and no option can be picked again meanwhile.
    vi.advanceTimersByTime(799)
    await flushPromises()
    expect(wrapper.get('[data-testid=word]').text()).toBe('environment')
    await wrapper.get('[data-testid=option-1]').trigger('click')
    expect(api.exam.answer).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(1)
    await flushPromises()
    expect(wrapper.get('[data-testid=word]').text()).toBe('government')
    expect(wrapper.get('[data-testid=progress]').text()).toBe('4 / 20')
    expect(wrapper.get('[data-testid=option-0]').attributes('data-state')).toBe('idle')
  })

  it('marks a right answer with a check', async () => {
    vi.mocked(api.exam.answer).mockResolvedValue(answered({ is_correct: true, correct_option: 2, score_earned: 100, total_score: 100 }))
    const { wrapper } = await render()

    await wrapper.get('[data-testid=option-2]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=option-2]').attributes('data-state')).toBe('correct')
    expect(wrapper.get('[data-testid=option-2]').findComponent(AppIcon).props('name')).toBe('check')
    expect(wrapper.get('[data-testid=score]').text()).toBe('100')
    expect(state.notificationHaptic).toHaveBeenCalledWith('success')
  })

  it('goes to the result after the last answer', async () => {
    vi.mocked(api.exam.join).mockResolvedValue(joined({ question: question(19, 'last') }))
    vi.mocked(api.exam.answer).mockResolvedValue(answered({ has_next: false, next_question: null }))
    vi.mocked(api.exam.result).mockResolvedValue({ learner_score: 120, rank: 1, total_participants: 1, leaderboard: [] })
    const { wrapper, router } = await render()

    await wrapper.get('[data-testid=option-0]').trigger('click')
    await flushPromises()
    vi.advanceTimersByTime(800)
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('exam-result')
    expect(router.currentRoute.value.params.sessionId).toBe('201')
  })

  describe('personal mock (?mode=mock)', () => {
    it('shows the "Mock test" chip instead of the score, and no bar for the question', async () => {
      const { wrapper } = await render({ mode: 'mock' })

      const chip = wrapper.get('[data-testid=mock-header]')
      expect(chip.text()).toBe('Пробный тест')
      expect(chip.classes()).toEqual(expect.arrayContaining(['chip', 'chip-accent']))
      expect(wrapper.find('[data-testid=score]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=question-timer]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=overall-timer]').text()).toBe('04:32')
      expect(wrapper.get('[data-testid=progress]').text()).toBe('3 / 20')
    })

    it('renders the chip in Uzbek', async () => {
      setLocale('uz')
      const { wrapper } = await render({ mode: 'mock' })

      expect(wrapper.get('[data-testid=mock-header]').text()).toBe('Sinov testi')
    })
  })

  describe('closed by the server (410)', () => {
    const gone = () => new ApiClientError('gone', 410, 'session_closed', 'closed')

    it('tells a group exam was finished by the teacher, with a lock icon and a way out', async () => {
      vi.mocked(api.exam.join).mockRejectedValue(gone())
      const { wrapper } = await render()

      const closed = wrapper.get('[data-testid=closed]')
      expect(closed.text()).toContain('Экзамен завершён учителем')
      expect(closed.text()).not.toContain('🔒')
      expect(closed.findComponent(AppIcon).props('name')).toBe('lock-closed')
      // The header with the countdown makes no sense over a closed exam.
      expect(wrapper.find('[data-testid=overall-timer]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=mock-results-btn]').exists()).toBe(false)

      await wrapper.get('[data-testid=close-btn]').trigger('click')
      expect(state.close).toHaveBeenCalledTimes(1)
    })

    it('tells a mock that the time is up and leads to its result', async () => {
      vi.mocked(api.exam.join).mockRejectedValue(gone())
      const { wrapper, router } = await render({ mode: 'mock' })

      const closed = wrapper.get('[data-testid=closed]')
      expect(closed.text()).toContain('Время вышло')
      expect(closed.text()).not.toContain('⏱')
      expect(closed.text()).not.toContain('учителем')
      expect(closed.findComponent(AppIcon).props('name')).toBe('clock')
      expect(wrapper.find('[data-testid=close-btn]').exists()).toBe(false)

      const results = wrapper.get('[data-testid=mock-results-btn]')
      expect(results.text()).toBe('Итоги пробного теста')
      await results.trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('exam-result')
    })

    it('says so in Uzbek', async () => {
      setLocale('uz')
      vi.mocked(api.exam.join).mockRejectedValue(gone())
      expect((await render()).wrapper.get('[data-testid=closed]').text()).toContain("Imtihon o'qituvchi tomonidan yakunlandi")
      expect((await render({ mode: 'mock' })).wrapper.get('[data-testid=closed]').text()).toContain('Vaqt tugadi')
    })
  })

  it('shows a failed question as an error with "Retry", which asks for the question again', async () => {
    vi.mocked(api.exam.join).mockResolvedValue(joined({ question: null }))
    vi.mocked(api.exam.question).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'fetch failed'))
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
    expect(wrapper.get('[data-testid=error]').text()).not.toContain('⚠')

    vi.mocked(api.exam.question).mockResolvedValueOnce(question(0, 'resilient', 10))
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(api.exam.question).toHaveBeenLastCalledWith(201, 0)
    expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=word]').text()).toBe('resilient')
  })

  it('shows a spinner while joining', async () => {
    vi.mocked(api.exam.join).mockReturnValue(new Promise(() => {}))
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
    // Nothing to count yet: no header with 00:00 and "1 / 0".
    expect(wrapper.find('[data-testid=overall-timer]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=progress]').exists()).toBe(false)
  })

  it('"Back" closes the Mini App, and is taken away with the screen', async () => {
    const { wrapper } = await render()
    expect(state.backHandler).not.toBeNull()

    state.backHandler?.()
    expect(state.close).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    expect(state.backHandler).toBeNull()
  })

  it('fills the height of the screen', async () => {
    const { wrapper } = await render()

    const root = wrapper.get('[data-testid=exam-view]')
    expect(root.attributes('style')).toContain('min-height: var(--tg-viewport-stable-height, 100dvh)')
    expect(root.classes()).toEqual(expect.arrayContaining(['flex', 'flex-col']))
  })
})
