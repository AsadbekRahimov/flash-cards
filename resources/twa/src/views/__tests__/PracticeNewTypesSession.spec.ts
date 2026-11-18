import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeSessionView from '../PracticeSessionView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import {
  errorHuntElement,
  errorHuntFixElement,
  errorHuntReveal,
  session,
  wordFormElement,
  wordFormReveal,
} from '@/api/__tests__/practiceFixtures'
import { forgetWordFormDrafts } from '@/composables/wordFormDraft'
import { setLocale } from '@/i18n'
import type { PracticeSession } from '@/api/types'

/**
 * "Word form" and "Error hunt" on the REAL screen of a solo session: the real components, the real store, the API mocked by the contract
 * (DESIGN newtypes §7). The two steps of an "Error hunt" are one element: the board stays, turns to its second step, and the review
 * comes after the second step only.
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

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn(), hideErrorToast: vi.fn() }))

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')

async function render(opened: PracticeSession) {
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

const answerCalls = () => vi.mocked(api.practice.answer).mock.calls

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  forgetWordFormDrafts()
})

afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('PracticeSessionView — "Error hunt"', () => {
  const hunt = (overrides: Partial<PracticeSession> = {}) => session({ mode: 'error_hunt', current: errorHuntElement(), ...overrides })

  it('two steps on one board, then the review', async () => {
    const wrapper = await render(hunt())
    vi.mocked(api.practice.answer)
      .mockResolvedValueOnce({
        verdict: 'wrong',
        first_attempt: true,
        reveal: { step: 'locate', located_ok: false, your_token_id: 'tAm1', error_token_id: 'tOn4' },
        pair: null,
        element_done: false,
        session: hunt({ current: errorHuntFixElement('tAm1') }),
      })
      .mockResolvedValueOnce({
        verdict: 'wrong',
        first_attempt: true,
        reveal: errorHuntReveal(),
        pair: null,
        element_done: true,
        session: hunt({ progress: { done: 1, total: 5 }, current: errorHuntElement({ id: 'eHunt00002' }) }),
      })

    const board = wrapper.findComponent({ name: 'ErrorHuntBoard' })
    await wrapper.get('[data-testid=token-tAm1]').trigger('click')
    await wrapper.get('[data-testid=confirm-btn]').trigger('click')
    await flushPromises()

    // The same board (not a new one), at its second step, with the marks of the search.
    expect(wrapper.findComponent({ name: 'ErrorHuntBoard' }).vm.$.uid).toBe(board.vm.$.uid)
    expect(wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=error-hunt-board]').attributes('data-step')).toBe('fix')
    expect(wrapper.get('[data-testid=token-tAm1]').attributes('data-look')).toBe('yours')
    expect(wrapper.get('[data-testid=token-tOn4]').attributes('data-look')).toBe('error')
    expect(wrapper.find('[data-testid=error-hunt-resend-note]').exists()).toBe(false)

    await wrapper.get('[data-testid=option-1]').trigger('click')
    await flushPromises()

    expect(answerCalls()[0]![1]).toMatchObject({ element_id: 'eHunt00001', answer: { token_id: 'tAm1' } })
    expect(answerCalls()[1]![1]).toMatchObject({ element_id: 'eHunt00001', answer: { option_id: 'oIn2' } })
    expect(answerCalls()[1]![1].request_id).not.toBe(answerCalls()[0]![1].request_id)
    expect(wrapper.get('[data-testid=answer-reveal]').attributes('data-kind')).toBe('error_hunt')
    expect(wrapper.get('[data-testid=hunt-found]').attributes('data-ok')).toBe('false')
    expect(wrapper.get('[data-testid=hunt-fixed]').attributes('data-ok')).toBe('true')

    await wrapper.get('[data-testid=continue-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid=error-hunt-board]').attributes('data-step')).toBe('locate')
    expect(wrapper.find('[data-testid=error-hunt-located]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a lost reply of the first step: the word stays, "This is the mistake" sends it again under the same id', async () => {
    const wrapper = await render(hunt())
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(offline())

    await wrapper.get('[data-testid=token-tAm1]').trigger('click')
    await wrapper.get('[data-testid=confirm-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid=error-hunt-resend-note]').exists()).toBe(true)
    await wrapper.get('[data-testid=confirm-btn]').trigger('click')
    await flushPromises()

    expect(answerCalls()).toHaveLength(2)
    expect(answerCalls()[1]![1].request_id).toBe(answerCalls()[0]![1].request_id)
    expect(answerCalls()[1]![1].answer).toEqual({ token_id: 'tAm1' })
    wrapper.unmount()
  })
})

describe('PracticeSessionView — "Word form"', () => {
  it('types, checks, and shows the review with the form in the sentence', async () => {
    const wrapper = await render(session({ mode: 'word_form', current: wordFormElement() }))
    vi.mocked(api.practice.answer).mockResolvedValue({
      verdict: 'wrong',
      first_attempt: true,
      reveal: wordFormReveal({ your_text: 'decide', same_as_base: true }),
      pair: null,
      session: session({ mode: 'word_form', progress: { done: 1, total: 5 }, current: wordFormElement({ id: 'eWord00002' }) }),
    })

    await wrapper.get('[data-testid=word-form-field]').setValue('decide')
    await wrapper.get('[data-testid=word-form-field]').trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(answerCalls()[0]![1]).toMatchObject({ element_id: 'eWord00001', answer: { text: 'decide' } })
    expect(wrapper.get('[data-testid=answer-reveal]').attributes('data-kind')).toBe('word_form')
    expect(wrapper.get('[data-testid=same-as-base]').text()).toBe('Нужна другая форма слова')
    expect(wrapper.get('[data-testid=word-form-fill]').text()).toBe('decision')
    wrapper.unmount()
  })
})
