import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import TrainingView from '../TrainingView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { Card, CardMemory, TrainingStartResponse } from '@/api/types'

/*
 * F1 "Повторение" (docs/40 §2.2, kit F1-povtorenie): under the flash card of a lesson and of the daily review - five dots of the memory
 * strength and «Память: 3 из 5 · повтор через 4 дня». Not in the round of hard words or the review of a lesson, not on an exercise, not
 * from an older server without `card.memory`. The grades stay as they are.
 */

beforeEach(() => window.sessionStorage.clear())

vi.mock('@/api', () => ({
  api: { training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), finish: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: () => {},
    showBackButton: () => () => {},
    haptic: () => {},
    selectionHaptic: () => {},
    notificationHaptic: () => {},
  }),
}))

const card: Card = {
  word_id: 7,
  word: 'kettle',
  translation: 'чайник',
  translation_fallback: false,
  example: 'The kettle is boiling.',
  transcription: '/ˈket.əl/',
  card_kind: 'due',
  serve_token: null,
}

function started(mode: TrainingStartResponse['mode'], memory: CardMemory | null | undefined, extra: Partial<Card> = {}): TrainingStartResponse {
  return {
    session_id: 1,
    mode,
    lesson: mode === 'lesson' ? { stage: 1, lesson: 2, title: null } : null,
    total_words: 12,
    card: { ...card, ...(memory === undefined ? {} : { memory }), ...extra },
    progress: { done: 3, total: 12 },
    done_reason: null,
  }
}

async function render(response: TrainingStartResponse) {
  vi.mocked(api.training.start).mockResolvedValue(response)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
      { path: '/finished', name: 'finished', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'training', params: { sessionId: 1 } })
  const wrapper = mount(TrainingView, { props: { sessionId: 1 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('TrainingView — the memory strength under the card (F1)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => {
    setLocale('ru')
    document.body.innerHTML = ''
  })

  it('shows the dots and the caption in the daily review, before and after the reveal', async () => {
    const wrapper = await render(started('daily_review', { strength: 3, stage: 'learning', next_in_days: 4 }))

    const memory = wrapper.get('[data-testid=memory]')
    expect(memory.findAll('[data-filled=true]')).toHaveLength(3)
    expect(wrapper.get('[data-testid=memory-caption]').text()).toBe('Память: 3 из 5 · повтор через 4 дня')
    // Under the card, inside the flash card (the slot), not in the actions.
    expect(wrapper.get('[data-testid=flashcard]').find('[data-testid=memory]').exists()).toBe(true)

    await wrapper.get('[data-testid=reveal-btn]').trigger('click')
    expect(wrapper.find('[data-testid=memory]').exists()).toBe(true)
    // The grades are the ones they were: three buttons, 2 / 4 / 5.
    expect(wrapper.findAll('button[data-testid^=grade-]').map((b) => b.attributes('data-testid'))).toEqual(['grade-hard', 'grade-medium', 'grade-easy'])
  })

  it('says «повтор сегодня» for a due word and «в этой сессии» in the minute steps, with the plural of the days', async () => {
    expect((await render(started('lesson', { strength: 2, stage: 'learning', next_in_days: 0 }))).get('[data-testid=memory-caption]').text()).toBe(
      'Память: 2 из 5 · повтор сегодня',
    )
    expect((await render(started('lesson', { strength: 0, stage: 'new', next_in_days: null }, { card_kind: 'new' }))).get('[data-testid=memory-caption]').text()).toBe(
      'Память: 0 из 5 · повтор в этой сессии',
    )
    expect((await render(started('lesson', { strength: 4, stage: 'known', next_in_days: 1 }))).get('[data-testid=memory-caption]').text()).toBe(
      'Память: 4 из 5 · повтор через 1 день',
    )
    expect((await render(started('lesson', { strength: 5, stage: 'mastered', next_in_days: 12 }))).get('[data-testid=memory-caption]').text()).toBe(
      'Память: 5 из 5 · повтор через 12 дней',
    )
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const wrapper = await render(started('daily_review', { strength: 3, stage: 'learning', next_in_days: 4 }))
    expect(wrapper.get('[data-testid=memory-caption]').text()).toBe('Xotira: 5 dan 3 · 4 kundan keyin takrorlanadi')
  })

  it('paints a weak word in the danger colour', async () => {
    const wrapper = await render(started('daily_review', { strength: 1, stage: 'learning', next_in_days: null }))
    expect(wrapper.get('[data-testid=memory]').findAll('[data-filled=true]').every((dot) => dot.classes('bg-danger'))).toBe(true)
  })

  it('shows nothing without `card.memory` (an older server, or null)', async () => {
    expect((await render(started('daily_review', undefined))).find('[data-testid=memory]').exists()).toBe(false)
    expect((await render(started('daily_review', null))).find('[data-testid=memory]').exists()).toBe(false)
    expect(document.querySelector('[data-testid=memory-dots]')).toBeNull()
  })

  it('shows nothing in the round of hard words and in the review of a lesson', async () => {
    const memory: CardMemory = { strength: 3, stage: 'learning', next_in_days: 4 }
    expect((await render(started('hard_review', memory))).find('[data-testid=memory]').exists()).toBe(false)
    expect((await render(started('lesson_review', memory))).find('[data-testid=memory]').exists()).toBe(false)
    expect((await render(started('group_lesson', memory))).find('[data-testid=memory]').exists()).toBe(false)
  })

  it('shows nothing on an exercise that is not a flash card', async () => {
    const wrapper = await render(
      started('daily_review', { strength: 3, stage: 'learning', next_in_days: 4 }, { prompt: { type: 'meaning', front: 'kettle', masked_example: null, options: ['a', 'b', 'c', 'd'], answer_length: null } }),
    )
    expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=memory]').exists()).toBe(false)
  })
})
