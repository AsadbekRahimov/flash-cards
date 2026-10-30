import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeSessionView from '@/views/PracticeSessionView.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { answerResponse, collocationElement, session } from '@/api/__tests__/practiceFixtures'
import type { CollocationReveal, PracticeAnswerResponse, PracticeSession } from '@/api/types'

/**
 * A solo session of "Pick the collocation" with the REAL components of the mode and of the review (the session view, the
 * store, `CollocationChoice`, `AnswerReveal`), and only the API and Telegram replaced: the whole way of one tap — one request,
 * options locked while it is on its way, the verdict with a symbol and words, the right option, the whole phrase, "Next".
 */

vi.mock('@/api', () => ({
  api: { practice: { get: vi.fn(), open: vi.fn(), answer: vi.fn(), replace: vi.fn(), audioBlob: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const tg = vi.hoisted(() => ({ close: vi.fn(), haptic: vi.fn(), notification: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: tg.close,
    showBackButton: () => () => {},
    haptic: tg.haptic,
    notificationHaptic: tg.notification,
    selectionHaptic: () => {},
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

const REVEAL: CollocationReveal = {
  correct_option_ids: ['oB2'],
  full_text: "Let's have a meeting on Monday.",
  translation: 'Давайте проведём встречу в понедельник.',
  explanation: 'С meeting говорят have.',
}

const second = () =>
  collocationElement({
    id: 'eColl00002',
    text: 'She wants to ___ a break.',
    options: [
      { id: 'oD4', text: 'take' },
      { id: 'oE5', text: 'make' },
      { id: 'oF6', text: 'do' },
    ],
  })

const opened = (): PracticeSession => session({ mode: 'collocation', current: collocationElement(), progress: { done: 0, total: 5 } })

/** The answer of the server: the next phrase is the second one. */
const answered = (overrides: Partial<PracticeAnswerResponse> = {}): PracticeAnswerResponse =>
  answerResponse({
    verdict: 'wrong',
    reveal: REVEAL,
    session: session({ mode: 'collocation', current: second(), progress: { done: 1, total: 5 } }),
    ...overrides,
  })

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

async function render(opening: PracticeSession = opened()) {
  vi.mocked(api.practice.get).mockResolvedValue(opening)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/practice/sessions/:id(\\d+)', name: 'practice-session', component: stub },
    ],
  })
  await router.push({ name: 'practice-session', params: { id: 77 } })
  const wrapper = mount(PracticeSessionView, { props: { id: 77 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof render>>

const option = (wrapper: Wrapper, index: number) => wrapper.get(`[data-testid=option-${index}]`)
const revealOption = (wrapper: Wrapper, id: string) => wrapper.get(`[data-testid=reveal-option-${id}]`)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('a session of collocations — the question', () => {
  it('shows the phrase with its blank and the three options, with the progress above', async () => {
    const wrapper = await render()

    expect(wrapper.find('[data-testid=collocation-choice]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=collocation-text]').text()).toContain("Let's")
    expect(wrapper.get('[data-testid=collocation-text]').text()).toContain('a meeting on Monday.')
    expect(wrapper.get('[data-testid=collocation-blank]').attributes('data-tone')).toBe('empty')
    expect(wrapper.findAll('.option')).toHaveLength(3)
    expect(wrapper.get('[data-testid=progress]').text()).toBe('0 / 5')
    expect(wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('a session of collocations — one tap, one request', () => {
  it('sends the tapped option once, locks the options while the request is on its way, and ignores a second tap', async () => {
    const reply = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValue(reply.promise)
    const wrapper = await render()

    await option(wrapper, 0).trigger('click')
    await option(wrapper, 0).trigger('click')
    await option(wrapper, 2).trigger('click')

    expect(api.practice.answer).toHaveBeenCalledTimes(1)
    const [sessionId, body] = vi.mocked(api.practice.answer).mock.calls[0]!
    expect(sessionId).toBe(77)
    expect(body).toMatchObject({ element_id: 'eColl00001', answer: { option_id: 'oA1' } })
    expect(body.request_id).toMatch(/^[0-9a-f-]{36}$/)
    for (const index of [0, 1, 2]) expect(option(wrapper, index).attributes('disabled')).toBeDefined()
    expect(option(wrapper, 0).attributes('data-state')).toBe('selected')

    reply.resolve(answered())
    await flushPromises()
    wrapper.unmount()
  })
})

describe('a session of collocations — the verdict', () => {
  it('a wrong pick: the verdict in words and a symbol, the pick marked wrong, the right option marked, the whole phrase, the translation, the explanation', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answered())
    const wrapper = await render()

    await option(wrapper, 0).trigger('click') // "make"
    await flushPromises()

    const verdict = wrapper.get('[data-testid=answer-reveal]')
    expect(verdict.attributes('data-verdict')).toBe('wrong')
    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Неверно') // a pick is final: "Needs fixing" is the word of a dictation
    expect(wrapper.get('[data-testid=reveal-icon]').findComponent(AppIcon).props('name')).toBe('x-mark')
    expect(wrapper.get('[data-testid=reveal-icon]').classes()).toContain('bg-danger-soft')

    // The whole collocation, the word the learner needed marked.
    expect(wrapper.get('[data-testid=expected]').text()).toBe("Let's have a meeting on Monday.")
    expect(wrapper.get('[data-testid=expected-fill]').text()).toBe('have')

    // The pick and the right option: colour + symbol + caption.
    const picked = revealOption(wrapper, 'oA1')
    const right = revealOption(wrapper, 'oB2')
    const other = revealOption(wrapper, 'oC3')
    expect(picked.attributes('data-state')).toBe('incorrect')
    expect(picked.text()).toContain('make')
    expect(picked.text()).toContain('ваш ответ')
    expect(picked.findComponent(AppIcon).props('name')).toBe('x-mark')
    expect(right.attributes('data-state')).toBe('reveal')
    expect(right.text()).toContain('верный ответ')
    expect(right.findComponent(AppIcon).props('name')).toBe('check')
    expect(other.attributes('data-state')).toBe('idle')
    expect(other.findComponent(AppIcon).exists()).toBe(false)

    expect(wrapper.get('[data-testid=translation]').text()).toContain('Давайте проведём встречу в понедельник.')
    expect(wrapper.get('[data-testid=explanation]').text()).toContain('С meeting говорят have.')
    expect(wrapper.get('[data-testid=verdict-live]').text()).toBe('Неверно')
    expect(tg.notification).toHaveBeenCalledWith('error')
    expect(wrapper.find('[data-testid=collocation-choice]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a right pick: "Верно!" with a check, the pick marked right, nothing else marked', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answered({ verdict: 'correct' }))
    const wrapper = await render()

    await option(wrapper, 1).trigger('click') // "have"
    await flushPromises()

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Верно!')
    expect(wrapper.get('[data-testid=reveal-icon]').findComponent(AppIcon).props('name')).toBe('check')
    expect(revealOption(wrapper, 'oB2').attributes('data-state')).toBe('correct')
    expect(revealOption(wrapper, 'oB2').findComponent(AppIcon).props('name')).toBe('check')
    expect(revealOption(wrapper, 'oA1').attributes('data-state')).toBe('idle')
    expect(revealOption(wrapper, 'oC3').attributes('data-state')).toBe('idle')
    expect(wrapper.find('[data-testid=your-text]').exists()).toBe(false)
    expect(tg.notification).toHaveBeenCalledWith('success')
    wrapper.unmount()
  })

  it('two right options: both are marked, whichever was picked', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answered({ reveal: { ...REVEAL, correct_option_ids: ['oB2', 'oC3'] } }))
    const wrapper = await render()

    await option(wrapper, 0).trigger('click')
    await flushPromises()

    expect(revealOption(wrapper, 'oA1').attributes('data-state')).toBe('incorrect')
    expect(revealOption(wrapper, 'oB2').attributes('data-state')).toBe('reveal')
    expect(revealOption(wrapper, 'oC3').attributes('data-state')).toBe('reveal')
    wrapper.unmount()
  })

  it('"Next" opens the next phrase in a component of its own: nothing of the pick is left over', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answered())
    const wrapper = await render()
    await option(wrapper, 0).trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid=continue-btn]').trigger('click')

    expect(wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=collocation-text]').text()).toContain('She wants to')
    expect(wrapper.get('[data-testid=collocation-blank]').attributes('data-tone')).toBe('empty')
    expect(wrapper.findAll('.option').map((button) => button.attributes('data-state'))).toEqual(['idle', 'idle', 'idle'])
    expect(wrapper.findAll('.option').every((button) => button.attributes('disabled') === undefined)).toBe(true)
    expect(wrapper.get('[data-testid=progress]').text()).toBe('1 / 5')
    wrapper.unmount()
  })

  it('on the last phrase "Next" leads to the summary', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(
      answered({ session: session({ mode: 'collocation', status: 'finished', current: null, progress: { done: 5, total: 5 }, summary: null }) }),
    )
    const wrapper = await render()
    await option(wrapper, 0).trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=continue-btn]').text()).toBe('К итогам')
    wrapper.unmount()
  })

  it('speaks Uzbek: the verdict, the captions', async () => {
    setLocale('uz')
    vi.mocked(api.practice.answer).mockResolvedValue(answered())
    const wrapper = await render()

    await option(wrapper, 0).trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe("Noto'g'ri")
    expect(revealOption(wrapper, 'oA1').text()).toContain('sizning javobingiz')
    expect(revealOption(wrapper, 'oB2').text()).toContain("to'g'ri javob")
    wrapper.unmount()
  })
})

describe('a session of collocations — when the request does not get through', () => {
  const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')

  it('tells it in a toast, keeps the pick, locks the other options, and "Retry" counts the same tap once (the same request id)', async () => {
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answered())
    const wrapper = await render()

    await option(wrapper, 0).trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    expect(option(wrapper, 0).attributes('data-state')).toBe('selected') // the pick stays
    for (const index of [0, 1, 2]) expect(option(wrapper, index).attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid=collocation-resend-note]').exists()).toBe(true)

    await wrapper.get('[data-testid=resend-btn]').trigger('click') // the same answer again
    await flushPromises()

    expect(api.practice.answer).toHaveBeenCalledTimes(2)
    const [first, again] = vi.mocked(api.practice.answer).mock.calls.map((call) => call[1])
    expect(again?.request_id).toBe(first?.request_id)
    expect(again?.answer).toEqual({ option_id: 'oA1' })
    expect(wrapper.find('[data-testid=answer-reveal]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('a different option cannot be chosen after a lost reply: there is no second answer, whatever is tapped', async () => {
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answered())
    const wrapper = await render()
    await option(wrapper, 0).trigger('click')
    await flushPromises()

    await option(wrapper, 2).trigger('click')
    await option(wrapper, 1).trigger('click')
    await flushPromises()

    expect(api.practice.answer).toHaveBeenCalledTimes(1) // nothing went out for the taps on the others
    expect(wrapper.get('[data-testid=collocation-blank]').text()).toBe('make')
    wrapper.unmount()
  })

  // The case of the review: the answer is counted ("make", wrong) but the reply is lost; the screen used to hand the options back, the
  // learner tapped the right one, and the server's 409 was shown as the verdict of that tap - a cross after the right option, and nothing else.
  it('after a lost reply of an answer that WAS counted, "Retry" brings the review of that answer: the pick it is, the right option marked', async () => {
    vi.mocked(api.practice.answer)
      .mockRejectedValueOnce(offline())
      .mockResolvedValueOnce(answered({ reveal: { ...REVEAL, your_option_id: 'oA1' } })) // the server replays the stored result of the same request id
    const wrapper = await render()
    await option(wrapper, 0).trigger('click') // "make", wrong
    await flushPromises()

    await wrapper.get('[data-testid=resend-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Неверно')
    expect(wrapper.find('[data-testid=reveal-earlier]').exists()).toBe(false)
    expect(revealOption(wrapper, 'oA1').attributes('data-state')).toBe('incorrect') // the pick that was given
    expect(revealOption(wrapper, 'oB2').attributes('data-state')).toBe('reveal') // and the right one
    expect(wrapper.get('[data-testid=expected-fill]').text()).toBe('have')
    wrapper.unmount()
  })

  it('shows the verdict of an answer that was already counted (another tab got there first): says so, no right answer is made up', async () => {
    const conflict = new ApiClientError('conflict', 409, 'answer_already_accepted', 'English message', undefined, { verdict: 'wrong' })
    vi.mocked(api.practice.answer).mockRejectedValue(conflict)
    const wrapper = await render()

    await option(wrapper, 1).trigger('click') // the right option, as it happens; the answer that counted was another one
    await flushPromises()

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe('Неверно')
    expect(wrapper.get('[data-testid=reveal-earlier]').text()).toBe('Засчитан ответ, отправленный раньше.')
    expect(wrapper.get('[data-testid=verdict-live]').text()).toBe('Неверно. Засчитан ответ, отправленный раньше.')
    expect(wrapper.find('[data-testid=expected]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=reveal-options]').exists()).toBe(false) // no pick, no right option: neither is known
    expect(wrapper.find('[data-testid=translation]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=continue-btn]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('"Next" after it opens what the server has now: the re-read session', async () => {
    const conflict = new ApiClientError('conflict', 409, 'answer_already_accepted', 'English message', undefined, { verdict: 'correct' })
    vi.mocked(api.practice.answer).mockRejectedValue(conflict)
    const wrapper = await render()
    vi.mocked(api.practice.get).mockResolvedValue(session({ mode: 'collocation', current: second(), progress: { done: 3, total: 5 } }))

    await option(wrapper, 0).trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid=progress]').text()).toBe('3 / 5')
    await wrapper.get('[data-testid=continue-btn]').trigger('click')

    expect(wrapper.get('[data-testid=collocation-text]').text()).toContain('She wants to')
    expect(wrapper.get('[data-testid=collocation-blank]').attributes('data-tone')).toBe('empty')
    expect(wrapper.find('[data-testid=resend-btn]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('speaks Uzbek when the answer was counted earlier', async () => {
    setLocale('uz')
    const conflict = new ApiClientError('conflict', 409, 'answer_already_accepted', 'English message', undefined, { verdict: 'wrong' })
    vi.mocked(api.practice.answer).mockRejectedValue(conflict)
    const wrapper = await render()

    await option(wrapper, 0).trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=reveal-text]').text()).toBe("Noto'g'ri")
    expect(wrapper.get('[data-testid=reveal-earlier]').text()).toBe('Avval yuborilgan javob hisobga olindi.')
    wrapper.unmount()
  })

  it('the answer of the server that names the pick (your_option_id) wins over the tap of this screen', async () => {
    // e.g. the reply of a repeat that another device gave: the server says which option it counted
    vi.mocked(api.practice.answer).mockResolvedValue(answered({ reveal: { ...REVEAL, your_option_id: 'oC3' } }))
    const wrapper = await render()

    await option(wrapper, 0).trigger('click')
    await flushPromises()

    expect(revealOption(wrapper, 'oC3').attributes('data-state')).toBe('incorrect')
    expect(revealOption(wrapper, 'oC3').text()).toContain('ваш ответ')
    expect(revealOption(wrapper, 'oA1').attributes('data-state')).toBe('idle')
    wrapper.unmount()
  })
})
