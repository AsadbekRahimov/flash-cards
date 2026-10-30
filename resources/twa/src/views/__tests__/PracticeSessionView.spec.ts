import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, createWebHistory } from 'vue-router'
import PracticeSessionView from '../PracticeSessionView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { usePracticeStore } from '@/stores/practice'
import {
  answerResponse,
  collocationElement,
  dictationElement,
  finishedSession,
  pairsElement,
  sentenceElement,
  session,
  summary,
} from '@/api/__tests__/practiceFixtures'
import type { PracticeAnswerResponse, PracticeSession } from '@/api/types'

vi.mock('@/api', () => ({
  api: { practice: { get: vi.fn(), open: vi.fn(), answer: vi.fn(), replace: vi.fn(), audioBlob: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const tg = vi.hoisted(() => ({
  backHandler: null as (() => void) | null,
  close: vi.fn(),
  haptic: vi.fn(),
  notification: vi.fn(),
}))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: tg.close,
    showBackButton: (handler: () => void) => {
      tg.backHandler = handler
      return () => {
        tg.backHandler = null
      }
    },
    haptic: tg.haptic,
    notificationHaptic: tg.notification,
    selectionHaptic: () => {},
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

// The component of a mode is the business of its own task; here a probe that answers when it is told to.
vi.mock('@/components/practice/modeComponents', async () => {
  const { defineComponent, h } = await import('vue')
  const Probe = defineComponent({
    props: { element: { type: Object, required: true }, mode: { type: String, default: 'solo' }, disabled: { type: Boolean, default: false } },
    emits: ['answer', 'skip', 'silent'],
    setup: (props, { emit }) => () =>
      h('div', { 'data-testid': 'probe', 'data-element': (props.element as { id: string }).id, 'data-mode': props.mode, 'data-disabled': String(props.disabled) }, [
        h('button', { 'data-testid': 'probe-answer', onClick: () => emit('answer', { order: ['fW2', 'fT5'] }) }),
        h('button', { 'data-testid': 'probe-skip', onClick: () => emit('skip') }),
        h('button', { 'data-testid': 'probe-silent', onClick: () => emit('silent') }),
      ]),
  })
  return { MODE_COMPONENTS: {}, modeComponentFor: () => Probe }
})

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

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')
const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

type Rendered = Awaited<ReturnType<typeof render>>

async function render(opened: PracticeSession | Error | Promise<PracticeSession> = session(), id = 77) {
  if (opened instanceof Error) vi.mocked(api.practice.get).mockRejectedValue(opened)
  else vi.mocked(api.practice.get).mockReturnValue(Promise.resolve(opened))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/practice/sessions/:id(\\d+)', name: 'practice-session', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
  await router.push({ name: 'practice-session', params: { id } })
  const wrapper = mount(PracticeSessionView, { props: { id }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const probe = (r: Rendered) => r.wrapper.get('[data-testid=probe]')
const answerProbe = async (r: Rendered) => {
  await r.wrapper.get('[data-testid=probe-answer]').trigger('click')
  await flushPromises()
}
const continueBtn = (r: Rendered) => r.wrapper.get('[data-testid=continue-btn]')

const clip = () => new Blob(['mp3'], { type: 'audio/mpeg' })

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  tg.backHandler = null
  URL.createObjectURL = vi.fn(() => 'blob:clip')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('PracticeSessionView — entering', () => {
  it('is a spinner while the session is read, then the element, after the login', async () => {
    const reply = pending<PracticeSession>()
    const { wrapper } = await render(reply.promise)

    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=probe]').exists()).toBe(false)

    reply.resolve(session())
    await flushPromises()
    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=probe]').exists()).toBe(true)
    expect(authEnsure).toHaveBeenCalled()
    expect(api.practice.get).toHaveBeenCalledWith(77)
    wrapper.unmount()
  })

  it('draws the component of the mode for the element, as a solo session', async () => {
    const r = await render(session({ current: sentenceElement({ id: 'eSent00007' }) }))

    expect(probe(r).attributes('data-element')).toBe('eSent00007')
    expect(probe(r).attributes('data-mode')).toBe('solo')
    expect(probe(r).attributes('data-disabled')).toBe('false')
    r.wrapper.unmount()
  })

  it('has the header of an immersive screen: ✕, the progress, "2 / 5" and the module and lesson under it', async () => {
    const r = await render(session({ progress: { done: 2, total: 5 } }))

    expect(r.wrapper.get('[data-testid=close-btn]').attributes('aria-label')).toBe('Закрыть')
    expect(r.wrapper.get('[data-testid=progress]').text()).toBe('2 / 5')
    const bar = r.wrapper.get('[data-testid=progress-bar]')
    expect(bar.attributes('role')).toBe('progressbar')
    expect(bar.attributes('aria-valuenow')).toBe('40')
    expect(bar.attributes('aria-label')).toBe('Выполнено 2 из 5')
    expect(r.wrapper.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 40%')
    expect(r.wrapper.get('[data-testid=caption]').text()).toBe('Модуль 2 · Урок 4 · Еда')
    r.wrapper.unmount()
  })

  it('has no tab bar of its own and nothing but the session: one column to the full height', async () => {
    const r = await render()

    expect(r.wrapper.get('[data-testid=practice-session-view]').attributes('style')).toContain('min-height')
    expect(r.wrapper.find('nav').exists()).toBe(false)
    r.wrapper.unmount()
  })

  it('under the header a lesson outside a module is "Lesson 4", and one with no number falls back to the course - never "Module null"', async () => {
    const outside = await render(session({ lesson: { id: 41, stage: null, number: 4, title: 'Еда' } }))
    expect(outside.wrapper.get('[data-testid=caption]').text()).toBe('Урок 4 · Еда')
    outside.wrapper.unmount()

    const unnumbered = await render(session({ lesson: { id: 41, stage: null, number: null, title: null } }))
    expect(unnumbered.wrapper.get('[data-testid=caption]').text()).toBe('Английский A1')
    unnumbered.wrapper.unmount()
  })

  it('under the header a course without a lesson shows the course', async () => {
    const r = await render(session({ lesson: null }))

    expect(r.wrapper.get('[data-testid=caption]').text()).toBe('Английский A1')
    r.wrapper.unmount()
  })

  it('marks a repeat of mistakes', async () => {
    const r = await render(session({ retry_of: 70 }))

    expect(r.wrapper.get('[data-testid=retry-chip]').text()).toBe('Повтор ошибок')
    r.wrapper.unmount()
  })

  it('does not ask the server again for the session that the hub has just opened', async () => {
    const store = usePracticeStore()
    store.preloaded = session({ id: 77 })
    vi.mocked(api.practice.get).mockResolvedValue(session())
    const stub = { template: '<div />' }
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: stub }] })

    const wrapper = mount(PracticeSessionView, { props: { id: 77 }, global: { plugins: [router] } })
    await flushPromises()

    expect(api.practice.get).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid=probe]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('goes to the other session when its id changes (Repeat mistakes, Again)', async () => {
    const r = await render(session({ id: 77 }))
    vi.mocked(api.practice.get).mockResolvedValue(session({ id: 78, current: sentenceElement({ id: 'eSent00099' }) }))

    await r.wrapper.setProps({ id: 78 })
    await flushPromises()

    expect(api.practice.get).toHaveBeenLastCalledWith(78)
    expect(probe(r).attributes('data-element')).toBe('eSent00099')
    r.wrapper.unmount()
  })
})

describe('PracticeSessionView — answering', () => {
  it('sends the answer of the component, keeps it disabled while the answer is on its way, and shows the verdict', async () => {
    const reply = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValue(reply.promise)
    const r = await render()

    await r.wrapper.get('[data-testid=probe-answer]').trigger('click')
    await flushPromises()
    expect(api.practice.answer).toHaveBeenCalledWith(77, expect.objectContaining({ element_id: 'eSent00001', answer: { order: ['fW2', 'fT5'] } }))
    expect(probe(r).attributes('data-disabled')).toBe('true')

    reply.resolve(answerResponse())
    await flushPromises()
    expect(r.wrapper.find('[data-testid=probe]').exists()).toBe(false)
    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe('Верно!')
    r.wrapper.unmount()
  })

  it('the next element comes with "Next": a new component for the new element, the progress moved', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: session({ progress: { done: 3, total: 5 }, current: sentenceElement({ id: 'eSent00002' }) }) }))
    const r = await render()

    await answerProbe(r)
    expect(r.wrapper.get('[data-testid=progress]').text()).toBe('3 / 5')
    expect(continueBtn(r).text()).toBe('Дальше')

    await continueBtn(r).trigger('click')
    expect(r.wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    expect(probe(r).attributes('data-element')).toBe('eSent00002')
    r.wrapper.unmount()
  })

  it('"I do not know" sends skip', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ verdict: 'skipped' }))
    const r = await render()

    await r.wrapper.get('[data-testid=probe-skip]').trigger('click')
    await flushPromises()

    expect(vi.mocked(api.practice.answer).mock.calls[0]![1].answer).toEqual({ skip: true })
    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe('Подсказка')
    r.wrapper.unmount()
  })

  it.each([
    ['correct', 'Верно!', 'success'],
    ['typo', 'Есть опечатка', 'warning'],
    ['wrong', 'Неверно', 'error'],
    ['skipped', 'Подсказка', 'error'],
  ] as const)('%s: the words on screen, the same words to a screen reader, a tap of the right kind to the hand', async (verdict, words, haptic) => {
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ verdict }))
    const r = await render()

    await answerProbe(r)

    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe(words)
    const live = r.wrapper.get('[data-testid=verdict-live]')
    expect(live.text()).toBe(words)
    expect(live.attributes('aria-live')).toBe('polite')
    expect(live.classes()).toContain('sr-only')
    expect(tg.notification).toHaveBeenCalledWith(haptic)
    r.wrapper.unmount()
  })

  it('the region of the screen reader is there from the start and empty until there is a verdict', async () => {
    const r = await render()

    expect(r.wrapper.get('[data-testid=verdict-live]').text()).toBe('')
    r.wrapper.unmount()
  })

  it('the last answer leads to the summary, and "Next" says so', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: finishedSession() }))
    const r = await render()

    await answerProbe(r)
    expect(continueBtn(r).text()).toBe('К итогам')

    await continueBtn(r).trigger('click')
    expect(r.wrapper.find('[data-testid=practice-summary]').exists()).toBe(true)
    expect(r.wrapper.get('[data-testid=stat-done]').text()).toBe('5 / 5')
    r.wrapper.unmount()
  })

  it('pairs: a match inside a round goes on in the same board, without a verdict screen and without a tap of ours', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({
        verdict: 'wrong',
        reveal: null,
        pair: { left_id: 'lM1', right_id: 'rT1' },
        round_done: false,
        session: session({ mode: 'pairs', current: pairsElement({ mismatches: 1 }) }),
      }),
    )
    const r = await render(session({ mode: 'pairs', current: pairsElement() }))

    await answerProbe(r)

    expect(r.wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    expect(probe(r).attributes('data-element')).toBe('ePair00001')
    expect(tg.notification).not.toHaveBeenCalled()
    r.wrapper.unmount()
  })

  it('pairs: the end of a round is a pause with "Next", announced and tapped', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({
        verdict: 'correct',
        reveal: null,
        pair: { left_id: 'lB4', right_id: 'rV3' },
        round_done: true,
        session: session({ mode: 'pairs', progress: { done: 1, total: 3 }, current: pairsElement({ id: 'ePair00002', round: 2 }) }),
      }),
    )
    const r = await render(session({ mode: 'pairs', current: pairsElement() }))

    await answerProbe(r)

    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe('Раунд 1 из 3 пройден')
    expect(r.wrapper.get('[data-testid=verdict-live]').text()).toBe('Раунд 1 из 3 пройден')
    expect(tg.notification).toHaveBeenCalledWith('success')
    await continueBtn(r).trigger('click')
    expect(probe(r).attributes('data-element')).toBe('ePair00002')
    r.wrapper.unmount()
  })

  it('the verdict has one primary button, at the bottom', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse())
    const r = await render()

    await answerProbe(r)

    expect(r.wrapper.findAll('.btn-primary')).toHaveLength(1)
    expect(r.wrapper.get('[data-testid=actions]').find('[data-testid=continue-btn]').exists()).toBe(true)
    r.wrapper.unmount()
  })
})

describe('PracticeSessionView — an answer that did not get through', () => {
  it('no network: a toast, the learner stays on the element, and the same answer is repeated with the same request id', async () => {
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerResponse())
    const r = await render()

    await answerProbe(r)
    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(probe(r).attributes('data-disabled')).toBe('false')
    expect(r.wrapper.find('[data-testid=error]').exists()).toBe(false)

    await answerProbe(r)
    const [first, second] = vi.mocked(api.practice.answer).mock.calls.map((call) => call[1].request_id)
    expect(second).toBe(first)
    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe('Верно!')
    r.wrapper.unmount()
  })

  it('another tab got ahead (409 element_not_current): the screen follows the server, with a toast', async () => {
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'element_not_current', { current: 'eSent00002' }))
    const r = await render()
    vi.mocked(api.practice.get).mockResolvedValue(session({ progress: { done: 3, total: 5 }, current: sentenceElement({ id: 'eSent00002' }) }))

    await answerProbe(r)

    expect(probe(r).attributes('data-element')).toBe('eSent00002')
    expect(r.wrapper.get('[data-testid=progress]').text()).toBe('3 / 5')
    expect(showErrorToast).toHaveBeenCalledWith('Задание уже сменилось. Экран обновлён.')
    r.wrapper.unmount()
  })

  it('the server has the answer already (409 answer_already_accepted): shows the verdict it counted, and says that it was sent earlier', async () => {
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'answer_already_accepted', { verdict: 'wrong' }))
    const r = await render()
    vi.mocked(api.practice.get).mockResolvedValue(session({ progress: { done: 3, total: 5 }, current: sentenceElement({ id: 'eSent00002' }) }))

    await answerProbe(r)

    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe('Неверно')
    expect(r.wrapper.get('[data-testid=reveal-earlier]').text()).toBe('Засчитан ответ, отправленный раньше.')
    expect(r.wrapper.get('[data-testid=verdict-live]').text()).toBe('Неверно. Засчитан ответ, отправленный раньше.')
    expect(r.wrapper.find('[data-testid=expected]').exists()).toBe(false) // no right answer is made up
    expect(showErrorToast).not.toHaveBeenCalled()
    await continueBtn(r).trigger('click')
    expect(probe(r).attributes('data-element')).toBe('eSent00002')
    r.wrapper.unmount()
  })

  it('a shape the server refuses (422): a toast, and the learner can answer again', async () => {
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('validation', 422, 'invalid_answer_shape', { expected: 'order_of_all_fragments' }))
    const r = await render()

    await answerProbe(r)

    expect(showErrorToast).toHaveBeenCalledWith('Не получилось отправить ответ. Обновите экран и попробуйте ещё раз.')
    expect(probe(r).attributes('data-disabled')).toBe('false')
    r.wrapper.unmount()
  })

  it('the paywall opens over the session when the plan has become Pro-only (403), and closes', async () => {
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))
    const r = await render()

    await answerProbe(r)

    expect(r.wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice')
    expect(r.wrapper.find('[data-testid=probe]').exists()).toBe(true)
    await r.wrapper.get('[data-testid=paywall-close]').trigger('click')
    expect(r.wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    r.wrapper.unmount()
  })
})

describe('PracticeSessionView — a session that cannot go on', () => {
  it('410: "Practice is over" with the way to the hub, and nothing else', async () => {
    const r = await render(apiError('gone', 410, 'practice_session_closed', { finish_reason: 'expired' }))

    const closed = r.wrapper.get('[data-testid=closed]')
    expect(closed.text()).toContain('Практика завершена')
    expect(closed.text()).toContain('Эта практика уже завершена или её время вышло.')
    expect(r.wrapper.find('[data-testid=progress]').exists()).toBe(false)
    expect(r.wrapper.find('[data-testid=probe]').exists()).toBe(false)
    expect(r.wrapper.findAll('.btn-primary')).toHaveLength(1)
    r.wrapper.unmount()
  })

  it('the session closes under the learner (410 on an answer): the same screen', async () => {
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('gone', 410, 'practice_session_closed'))
    const r = await render()

    await answerProbe(r)

    expect(r.wrapper.get('[data-testid=closed]').text()).toContain('Практика завершена')
    r.wrapper.unmount()
  })

  it('an error: the words of its code, "Retry" that enters again, and the way to the hub', async () => {
    const r = await render(apiError('not_found', 404, 'practice_session_not_found'))

    expect(r.wrapper.get('[data-testid=error]').text()).toContain('Эта практика не найдена.')
    expect(r.wrapper.find('[data-testid=hub-btn]').exists()).toBe(true)

    vi.mocked(api.practice.get).mockResolvedValue(session())
    await r.wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()
    expect(r.wrapper.find('[data-testid=error]').exists()).toBe(false)
    expect(r.wrapper.find('[data-testid=probe]').exists()).toBe(true)
    r.wrapper.unmount()
  })

  it('a login that fails is an error too, and the session is not asked for', async () => {
    authEnsure.mockRejectedValue(offline())
    const r = await render()

    expect(r.wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
    expect(api.practice.get).not.toHaveBeenCalled()
    r.wrapper.unmount()
  })

  it('a finished session that came without a summary is closed, not blank', async () => {
    const r = await render(finishedSession({ summary: null }))

    expect(r.wrapper.get('[data-testid=closed]').text()).toContain('Практика завершена')
    r.wrapper.unmount()
  })
})

describe('PracticeSessionView — leaving', () => {
  it('✕ leads to the hub of the lesson, and so does the system "Back"', async () => {
    const r = await render()

    await r.wrapper.get('[data-testid=close-btn]').trigger('click')
    await flushPromises()
    expect(r.router.currentRoute.value.name).toBe('practice-hub')
    expect(r.router.currentRoute.value.params.slug).toBe('english-a1')
    expect(r.router.currentRoute.value.query).toEqual({ lesson: '41' })
    r.wrapper.unmount()

    const again = await render()
    expect(tg.backHandler).not.toBeNull()
    tg.backHandler?.()
    await flushPromises()
    expect(again.router.currentRoute.value.name).toBe('practice-hub')
    again.wrapper.unmount()
  })

  it('the hub of a session of a whole course has no lesson', async () => {
    const r = await render(session({ lesson: null }))

    await r.wrapper.get('[data-testid=close-btn]').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.query).toEqual({})
    r.wrapper.unmount()
  })

  it('asks nothing: the answers are saved as they go', async () => {
    const r = await render()

    await r.wrapper.get('[data-testid=close-btn]').trigger('click')

    expect(r.wrapper.find('[role=dialog]').exists()).toBe(false)
    r.wrapper.unmount()
  })

  it('goes one step back when that is the hub, so that the hub keeps its place and the history does not grow', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(session())
    const stub = { template: '<div />' }
    // the history of the app: every entry knows the one before it (`history.state.back`)
    const router = createRouter({
      history: createWebHistory('/practice-back-test/'),
      routes: [
        { path: '/', name: 'home', component: stub },
        { path: '/practice/:slug', name: 'practice-hub', component: stub },
        { path: '/practice/sessions/:id(\\d+)', name: 'practice-session', component: stub },
        { path: '/courses', name: 'courses', component: stub },
      ],
    })
    await router.push({ name: 'practice-hub', params: { slug: 'english-a1' }, query: { lesson: '41' } })
    await router.push({ name: 'practice-session', params: { id: 77 } })
    const wrapper = mount(PracticeSessionView, { props: { id: 77 }, global: { plugins: [router] } })
    await flushPromises()
    const replace = vi.spyOn(router, 'replace')

    await wrapper.get('[data-testid=close-btn]').trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 50))
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('practice-hub')
    expect(router.currentRoute.value.query).toEqual({ lesson: '41' })
    expect(replace).not.toHaveBeenCalled()
    expect(router.options.history.state.forward).toBe('/practice/sessions/77')
    wrapper.unmount()
  })

  it('a session that is not known yet leads to the courses', async () => {
    const r = await render(offline())

    await r.wrapper.get('[data-testid=close-btn]').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.name).toBe('courses')
    r.wrapper.unmount()
  })

  it('the way back of the closed screen is the same', async () => {
    const r = await render(apiError('gone', 410, 'practice_session_closed'))

    await r.wrapper.get('[data-testid=hub-btn]').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.name).toBe('courses')
    r.wrapper.unmount()
  })

  it('gives the clip back when the screen is left', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const r = await render(session({ mode: 'dictation', current: dictationElement() }))
    expect(usePracticeStore().audio.state).toBe('ready')

    r.wrapper.unmount()

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:clip')
    expect(usePracticeStore().audio).toEqual({ state: 'idle', blobUrl: null })
  })

  it('stops the request of a clip that is still coming when the screen is left, instead of keeping a connection for it', async () => {
    vi.mocked(api.practice.audioBlob).mockReturnValue(new Promise<Blob>(() => {}))
    const r = await render(session({ mode: 'dictation', current: dictationElement() }))
    const signal = vi.mocked(api.practice.audioBlob).mock.calls[0]![1] as AbortSignal
    expect(usePracticeStore().audio.state).toBe('loading')
    expect(signal.aborted).toBe(false)

    r.wrapper.unmount()

    expect(signal.aborted).toBe(true)
    expect(usePracticeStore().audio).toEqual({ state: 'idle', blobUrl: null })
  })
})

describe('PracticeSessionView — the summary', () => {
  const finished = (overrides = {}) => finishedSession(overrides)

  it('"Repeat mistakes" opens a session out of them and goes into it', async () => {
    const r = await render(finished())
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 91, retry_of: 77 }))

    await r.wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(api.practice.open).toHaveBeenCalledWith({ mode: 'sentence', course: 'english-a1', lesson_id: 41, source: 'retry', retry_of: 77 })
    expect(r.router.currentRoute.value.params.id).toBe('91')
    // the screen is the same component with a new id; the answer of the open is used, the session is not read again
    await r.wrapper.setProps({ id: 91 })
    await flushPromises()
    expect(api.practice.get).toHaveBeenCalledTimes(1)
    expect(r.wrapper.find('[data-testid=retry-chip]').exists()).toBe(true)
    expect(r.wrapper.find('[data-testid=probe]').exists()).toBe(true)
    r.wrapper.unmount()
  })

  it('"Again" opens another of the same mode', async () => {
    const r = await render(finished({ summary: summary({ retry_available: false }) }))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 92 }))

    await r.wrapper.get('[data-testid=again-btn]').trigger('click')
    await flushPromises()

    expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ source: 'hub', mode: 'sentence' })
    expect(r.router.currentRoute.value.params.id).toBe('92')
    r.wrapper.unmount()
  })

  it('a repeat that the server refuses is a toast, and "Again" takes the place of the button', async () => {
    const r = await render(finished())
    vi.mocked(api.practice.open).mockRejectedValue(apiError('conflict', 409, 'retry_not_available'))

    await r.wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Повторить ошибки не получится: их слишком мало или нет совсем.')
    expect(r.wrapper.find('[data-testid=retry-btn]').exists()).toBe(false)
    expect(r.wrapper.find('[data-testid=again-btn]').exists()).toBe(true)
    expect(r.router.currentRoute.value.params.id).toBe('77')
    r.wrapper.unmount()
  })

  it('does not carry the learner into the repeat after they have left', async () => {
    const r = await render(finished())
    const reply = pending<PracticeSession>()
    vi.mocked(api.practice.open).mockReturnValue(reply.promise)
    await r.wrapper.get('[data-testid=retry-btn]').trigger('click')
    r.wrapper.unmount()
    await r.router.push({ name: 'courses' })

    reply.resolve(session({ id: 93 }))
    await flushPromises()

    expect(r.router.currentRoute.value.name).toBe('courses')
  })

  it('does not tell the next screen a refusal of the repeat that comes after the learner has left', async () => {
    const r = await render(finished())
    const reply = pending<PracticeSession>()
    vi.mocked(api.practice.open).mockReturnValue(reply.promise)
    await r.wrapper.get('[data-testid=retry-btn]').trigger('click')
    r.wrapper.unmount()
    await r.router.push({ name: 'courses' })

    reply.reject(apiError('conflict', 409, 'retry_not_available'))
    await flushPromises()

    const store = usePracticeStore()
    expect(store.openError).toBe('') // the hub would show it as a toast of its own
    expect(store.retryUnavailable).toBe(false)
    expect(showErrorToast).not.toHaveBeenCalled()
  })

  it('"To the course" opens the course', async () => {
    const r = await render(finished())

    await r.wrapper.get('[data-testid=course-btn]').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.name).toBe('course')
    expect(r.router.currentRoute.value.params.slug).toBe('english-a1')
    r.wrapper.unmount()
  })

  it('"Close" closes the Mini App', async () => {
    const r = await render(finished())

    await r.wrapper.get('[data-testid=practice-summary] [data-testid=close-btn]').trigger('click')

    expect(tg.close).toHaveBeenCalledTimes(1)
    r.wrapper.unmount()
  })
})

describe('PracticeSessionView — the clip of a dictation', () => {
  const dictation = (overrides: Partial<ReturnType<typeof dictationElement>> = {}) =>
    session({ mode: 'dictation', current: dictationElement(overrides) })

  it('fetches the clip of the element when it appears, and has no trouble when it comes', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const r = await render(dictation())

    expect(api.practice.audioBlob).toHaveBeenCalledWith('/api/twa/practice/sessions/77/audio/eDict00001', expect.any(AbortSignal))
    expect(usePracticeStore().audio.state).toBe('ready')
    expect(r.wrapper.find('[data-testid=audio-trouble]').exists()).toBe(false)
    r.wrapper.unmount()
  })

  it('fetches nothing for the other modes', async () => {
    const r = await render(session({ mode: 'collocation', current: collocationElement() }))

    expect(api.practice.audioBlob).not.toHaveBeenCalled()
    r.wrapper.unmount()
  })

  it('a clip that does not come opens "Sound is not available" with the three ways on', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(apiError('server', 503, 'practice_audio_unavailable'))
    const r = await render(dictation())

    const trouble = r.wrapper.get('[data-testid=audio-trouble]')
    expect(trouble.text()).toContain('Звук недоступен')
    expect(trouble.text()).toContain('Замен осталось: 2')
    expect(trouble.find('[data-testid=trouble-retry]').exists()).toBe(true)
    expect(trouble.find('[data-testid=trouble-replace]').exists()).toBe(true)
    expect(trouble.find('[data-testid=trouble-silent]').exists()).toBe(true)
    r.wrapper.unmount()
  })

  it('"Retry" fetches the clip again, and the sheet closes when it comes', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValueOnce(offline()).mockResolvedValueOnce(clip())
    const r = await render(dictation())

    await r.wrapper.get('[data-testid=trouble-retry]').trigger('click')
    await flushPromises()

    expect(api.practice.audioBlob).toHaveBeenCalledTimes(2)
    expect(r.wrapper.find('[data-testid=audio-trouble]').exists()).toBe(false)
    r.wrapper.unmount()
  })

  it('"Retry" that fails again asks the question again', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(offline())
    const r = await render(dictation())

    await r.wrapper.get('[data-testid=trouble-retry]').trigger('click')
    await flushPromises()

    expect(api.practice.audioBlob).toHaveBeenCalledTimes(2)
    expect(r.wrapper.find('[data-testid=audio-trouble]').exists()).toBe(true)
    r.wrapper.unmount()
  })

  it('"Replace the task" swaps the element, with no penalty, and the clip of the new one is fetched', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValueOnce(offline()).mockResolvedValueOnce(clip())
    const r = await render(dictation())
    vi.mocked(api.practice.replace).mockResolvedValue(dictation({ id: 'eDict00002', audio_url: '/api/twa/practice/sessions/77/audio/eDict00002', replacements_left: 1 }))

    await r.wrapper.get('[data-testid=trouble-replace]').trigger('click')
    await flushPromises()

    expect(vi.mocked(api.practice.replace).mock.calls[0]![1]).toMatchObject({ element_id: 'eDict00001', reason: 'audio_unavailable' })
    expect(probe(r).attributes('data-element')).toBe('eDict00002')
    expect(api.practice.audioBlob).toHaveBeenLastCalledWith('/api/twa/practice/sessions/77/audio/eDict00002', expect.any(AbortSignal))
    expect(r.wrapper.find('[data-testid=audio-trouble]').exists()).toBe(false)
    r.wrapper.unmount()
  })

  it('a swap that the server refuses (no replacements left) is a toast', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(offline())
    const r = await render(dictation())
    vi.mocked(api.practice.replace).mockRejectedValue(apiError('conflict', 409, 'replace_not_allowed', { reason: 'limit' }))

    await r.wrapper.get('[data-testid=trouble-replace]').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Заменить задание больше нельзя.')
    expect(probe(r).attributes('data-element')).toBe('eDict00001')
    r.wrapper.unmount()
  })

  it('with no replacements left the sheet does not offer one', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(offline())
    const r = await render(dictation({ replacements_left: 0 }))

    expect(r.wrapper.find('[data-testid=trouble-replace]').exists()).toBe(false)
    expect(r.wrapper.find('[data-testid=trouble-silent]').exists()).toBe(true)
    r.wrapper.unmount()
  })

  it('"No sound for now" leaves the dictation for the hub, and drops it on purpose: the hub will not ask about it', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(offline())
    const r = await render(dictation())
    expect(usePracticeStore().abandoned).toBeNull()

    await r.wrapper.get('[data-testid=trouble-silent]').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.name).toBe('practice-hub')
    expect(usePracticeStore().abandoned).toBe(77)
    r.wrapper.unmount()
  })

  it('the button the dictation always has does the same: to the hub of the lesson, the session dropped on purpose', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip()) // the sound is fine: this is for a learner who cannot listen now
    const r = await render(dictation())
    expect(r.wrapper.find('[data-testid=audio-trouble]').exists()).toBe(false)

    await r.wrapper.get('[data-testid=probe-silent]').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.name).toBe('practice-hub')
    expect(r.router.currentRoute.value.query).toEqual({ lesson: '41' })
    expect(usePracticeStore().abandoned).toBe(77)
    r.wrapper.unmount()
  })

  it('the ✕ and the system "Back" are not "dropped on purpose": the hub asks about the unfinished session as always', async () => {
    const r = await render(dictation())

    await r.wrapper.get('[data-testid=close-btn]').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.name).toBe('practice-hub')
    expect(usePracticeStore().abandoned).toBeNull()
    r.wrapper.unmount()
  })

  it('a replacement that the server refuses because the learner is not on this element (another tab went on) is no toast: the screen follows', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(offline())
    const r = await render(dictation())
    vi.mocked(api.practice.replace).mockRejectedValue(apiError('conflict', 409, 'replace_not_allowed', { reason: 'not_current' }))
    vi.mocked(api.practice.get).mockResolvedValue(session({ mode: 'dictation', progress: { done: 3, total: 5 }, current: dictationElement({ id: 'eDict00007', audio_url: '/api/twa/practice/sessions/77/audio/eDict00007' }) }))

    await r.wrapper.get('[data-testid=trouble-replace]').trigger('click')
    await flushPromises()

    expect(showErrorToast).not.toHaveBeenCalled()
    expect(probe(r).attributes('data-element')).toBe('eDict00007')
    expect(r.wrapper.get('[data-testid=progress]').text()).toBe('3 / 5')
    expect(api.practice.audioBlob).toHaveBeenLastCalledWith('/api/twa/practice/sessions/77/audio/eDict00007', expect.any(AbortSignal))
    r.wrapper.unmount()
  })

  it('the ✕ of the sheet only closes it: the dictation stays, with its own way to try again', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(offline())
    const r = await render(dictation())

    await r.wrapper.get('[data-testid=sheet-x]').trigger('click')

    expect(r.wrapper.find('[data-testid=audio-trouble]').exists()).toBe(false)
    expect(r.wrapper.find('[data-testid=probe]').exists()).toBe(true)
    expect(r.router.currentRoute.value.name).toBe('practice-session')
    r.wrapper.unmount()
  })

  it('the clip of the next element is fetched while the verdict is read', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({ session: dictation({ id: 'eDict00002', audio_url: '/api/twa/practice/sessions/77/audio/eDict00002' }) }),
    )
    const r = await render(dictation())

    await answerProbe(r)

    expect(r.wrapper.find('[data-testid=answer-reveal]').exists()).toBe(true)
    // The shared slot of the screen takes the NEXT clip; the review (18.5) fetches the clip of the answered element into a slot of its
    // own, for "Listen again" - so the next clip is not the last request any more, but it is asked for, and only once.
    const asked = vi.mocked(api.practice.audioBlob).mock.calls.map(([url]) => url)
    expect(asked).toEqual([
      '/api/twa/practice/sessions/77/audio/eDict00001',
      '/api/twa/practice/sessions/77/audio/eDict00002',
      '/api/twa/practice/sessions/77/audio/eDict00001',
    ])
    r.wrapper.unmount()
  })
})

describe('PracticeSessionView — Uzbek', () => {
  it('speaks it from the header to the verdict', async () => {
    setLocale('uz')
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ verdict: 'wrong' }))
    const r = await render()

    expect(r.wrapper.get('[data-testid=caption]').text()).toContain('2-modul')
    await answerProbe(r)
    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe("Noto'g'ri")
    r.wrapper.unmount()
  })
})
