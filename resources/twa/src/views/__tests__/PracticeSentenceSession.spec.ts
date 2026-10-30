import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeSessionView from '../PracticeSessionView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { answerResponse, sentenceElement, session } from '@/api/__tests__/practiceFixtures'
import { forgetSentenceDrafts } from '@/composables/sentenceDraft'
import { setLocale } from '@/i18n'
import type { PracticeAnswerResponse, PracticeSession } from '@/api/types'

/**
 * "Build the sentence" on the REAL screen of a solo session (docs/15 §3.2): the real component, the real store, the API
 * mocked. What the component promises alone (spec of its own) must also hold with the store around it - the lock while the
 * answer is on its way, the very same `request_id` for a repeat, the line that is still there when the answer did not go
 * through, the line that comes back when the learner comes back.
 */
vi.mock('@/api', () => ({
  api: { practice: { get: vi.fn(), open: vi.fn(), answer: vi.fn(), replace: vi.fn(), audioBlob: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: vi.fn(),
    showBackButton: () => () => {},
    haptic: () => {},
    notificationHaptic: () => {},
    selectionHaptic: () => {},
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ props: { reason: { type: String, default: 'info' } }, emits: ['close', 'review'], setup: () => () => h('div', { 'data-testid': 'paywall' }) }) }
})

const RIGHT = ['fW2', 'fT5', 'fR4', 'fE3', 'fQ1']

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

async function render(opened: PracticeSession = session()) {
  vi.mocked(api.practice.get).mockResolvedValue(opened)
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

const fragment = (w: Wrapper, id: string) => w.get(`[data-testid=fragment-${id}]`)
const lineIds = (w: Wrapper): string[] =>
  w.findAll('[data-testid=sentence-answer] button').map((button) => (button.attributes('data-testid') ?? '').replace('line-', ''))
const check = (w: Wrapper) => w.get('[data-testid=check-btn]')
const answerCalls = () => vi.mocked(api.practice.answer).mock.calls

async function put(w: Wrapper, ids: string[]): Promise<void> {
  for (const id of ids) await fragment(w, id).trigger('click')
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  forgetSentenceDrafts()
  URL.createObjectURL = vi.fn(() => 'blob:clip')
  URL.revokeObjectURL = vi.fn()
})

afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('"Build the sentence" on the screen of a session', () => {
  it('draws the sentence component for a sentence element, as a solo session', async () => {
    const w = await render()

    expect(w.find('[data-testid=sentence-builder]').exists()).toBe(true)
    expect(w.get('[data-testid=sentence-builder]').attributes('data-mode')).toBe('solo')
    expect(w.get('[data-testid=sentence-prompt]').text()).toBe('Моя мама — учитель.')
    w.unmount()
  })

  it('sends the line, keeps the component locked while the answer is on its way, and shows the review with the other right orders', async () => {
    const reply = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValue(reply.promise)
    const w = await render()
    await put(w, RIGHT)

    await check(w).trigger('click')
    await flushPromises()

    expect(answerCalls()).toHaveLength(1)
    expect(answerCalls()[0]![0]).toBe(77)
    expect(answerCalls()[0]![1]).toMatchObject({ element_id: 'eSent00001', answer: { order: RIGHT } })
    expect(answerCalls()[0]![1].request_id).toMatch(/^[0-9a-f-]{36}$/)
    expect(w.findAll('button').filter((button) => button.attributes('disabled') === undefined && button.element.closest('[data-testid=sentence-builder]') !== null)).toHaveLength(0)

    reply.resolve(
      answerResponse({
        verdict: 'wrong',
        reveal: { correct_text: 'I usually drink tea.', alternatives: ['Usually I drink tea.'], your_text: 'Tea drink I usually', explanation: 'Порядок слов.' },
      }),
    )
    await flushPromises()

    expect(w.find('[data-testid=sentence-builder]').exists()).toBe(false)
    expect(w.get('[data-testid=reveal-text]').text()).toBe('Нужно исправить')
    expect(w.get('[data-testid=expected]').text()).toBe('I usually drink tea.')
    expect(w.get('[data-testid=alternatives]').text()).toContain('Можно и так')
    expect(w.get('[data-testid=alternatives]').text()).toContain('Usually I drink tea.')
    expect(w.get('[data-testid=your-text]').text()).toBe('Tea drink I usually')
    w.unmount()
  })

  it('sends once when "Check" is pressed twice at once', async () => {
    vi.mocked(api.practice.answer).mockReturnValue(pending<PracticeAnswerResponse>().promise)
    const w = await render()
    await put(w, RIGHT)
    const button = check(w).element as HTMLButtonElement

    button.click()
    button.click()
    await flushPromises()

    expect(answerCalls()).toHaveLength(1)
    w.unmount()
  })

  it('"I don\'t know" sends skip once, with no line', async () => {
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ verdict: 'skipped' }))
    const w = await render()
    await put(w, ['fW2'])

    await w.get('[data-testid=skip-btn]').trigger('click')
    await flushPromises()

    expect(answerCalls()).toHaveLength(1)
    expect(answerCalls()[0]![1].answer).toEqual({ skip: true })
    w.unmount()
  })

  it('keeps the line when there is no network, and sends the SAME line under the SAME request id when it is pressed again', async () => {
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerResponse())
    const w = await render()
    await put(w, RIGHT)

    await check(w).trigger('click')
    await flushPromises()

    // the answer did not go through: the screen is the same, the line is whole, "Check" is on again
    expect(w.find('[data-testid=sentence-builder]').exists()).toBe(true)
    expect(lineIds(w)).toEqual(RIGHT)
    expect(check(w).attributes('disabled')).toBeUndefined()

    await check(w).trigger('click')
    await flushPromises()

    expect(answerCalls()).toHaveLength(2)
    expect(answerCalls()[1]![1].request_id).toBe(answerCalls()[0]![1].request_id)
    expect(answerCalls()[1]![1].answer).toEqual({ order: RIGHT })
    expect(w.get('[data-testid=reveal-text]').text()).toBe('Верно!')
    w.unmount()
  })

  it('takes a different line under a new request id: the learner changed their mind after the failure', async () => {
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerResponse())
    const w = await render()
    await put(w, RIGHT)
    await check(w).trigger('click')
    await flushPromises()

    await w.get('[data-testid=line-fQ1]').trigger('click')
    await fragment(w, 'fQ1').trigger('click')
    await w.get('[data-testid=line-fW2]').trigger('click')
    await fragment(w, 'fW2').trigger('click') // the same fragments, another order: fW2 is last now
    await check(w).trigger('click')
    await flushPromises()

    expect(answerCalls()).toHaveLength(2)
    expect(answerCalls()[1]![1].answer).toEqual({ order: ['fT5', 'fR4', 'fE3', 'fQ1', 'fW2'] })
    expect(answerCalls()[1]![1].request_id).not.toBe(answerCalls()[0]![1].request_id)
    w.unmount()
  })

  it('keeps the line when the screen is told the element is not the current one but it is read again as the same element', async () => {
    vi.mocked(api.practice.answer).mockRejectedValueOnce(new ApiClientError('conflict', 409, 'element_not_current', 'x', undefined, { current: 'eSent00001' }))
    const w = await render()
    await put(w, ['fW2', 'fT5'])
    vi.mocked(api.practice.get).mockResolvedValue(session()) // the same element, a new object
    await put(w, ['fR4', 'fE3', 'fQ1'])

    await check(w).trigger('click')
    await flushPromises()

    expect(api.practice.get).toHaveBeenCalledTimes(2)
    expect(lineIds(w)).toEqual(RIGHT)
    w.unmount()
  })

  it('brings the half-built line back when the learner leaves the screen and returns to the same element', async () => {
    const first = await render()
    await put(first, ['fT5', 'fW2'])
    first.unmount()

    const back = await render()

    expect(lineIds(back)).toEqual(['fT5', 'fW2'])
    expect(back.get('[data-testid=sentence-progress]').text()).toBe('Собрано 2 из 5')
    back.unmount()
  })

  it('starts the next element with an empty line, with nothing of the previous one left', async () => {
    const next = session({ progress: { done: 3, total: 5 }, current: sentenceElement({ id: 'eSent00002' }) })
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: next }))
    const w = await render()
    await put(w, RIGHT)
    await check(w).trigger('click')
    await flushPromises()

    await w.get('[data-testid=continue-btn]').trigger('click')

    expect(lineIds(w)).toEqual([])
    expect(w.get('[data-testid=sentence-progress]').text()).toBe('Собрано 0 из 5')
    expect(w.get('[data-testid=check-btn]').attributes('disabled')).toBeDefined()
    w.unmount()
  })
})
