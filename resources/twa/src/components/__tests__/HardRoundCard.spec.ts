import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import HardRoundCard from '../HardRoundCard.vue'
import { setLocale } from '@/i18n'
import type { HardRound } from '@/api/types'

const round = (overrides: Partial<HardRound> = {}): HardRound => ({
  available: true,
  reason: null,
  words: 10,
  open_session_id: null,
  resets_at: null,
  ...overrides,
})

const render = (value: HardRound | null | undefined) => mount(HardRoundCard, { props: { round: value } })

describe('HardRoundCard', () => {
  it('invites to review when the round is available', () => {
    const w = render(round({ words: 10 }))

    expect(w.get('[data-testid=hard-round-card]').text()).toContain('Раунд трудных слов')
    expect(w.get('[data-testid=hard-round-words]').text()).toBe('10 трудных слов ждут разбора')
    expect(w.get('[data-testid=hard-round-btn]').text()).toBe('Разобрать')
  })

  it.each([
    [1, '1 трудное слово ждёт разбора'],
    [3, '3 трудных слова ждут разбора'],
    [11, '11 трудных слов ждут разбора'],
  ])('says the count of %i in the right Russian form', (words, text) => {
    expect(render(round({ words })).get('[data-testid=hard-round-words]').text()).toBe(text)
  })

  it('offers to continue an open round', () => {
    const w = render(round({ open_session_id: 41 }))

    expect(w.get('[data-testid=hard-round-btn]').text()).toBe('Продолжить')
  })

  it('continues an open round even when the server calls it not available', () => {
    const w = render(round({ available: false, reason: 'not_enough', open_session_id: 41 }))

    expect(w.get('[data-testid=hard-round-btn]').text()).toBe('Продолжить')
  })

  it.each<[string, HardRound | null | undefined]>([
    ['played today', round({ available: false, reason: 'played_today', resets_at: '2026-11-18T00:00:00Z' })],
    ['not enough words', round({ available: false, reason: 'not_enough', words: 3 })],
    ['no objective exercise', round({ available: false, reason: 'no_objective_exercise' })],
    ['the feature is off', null],
    ['an older server', undefined],
  ])('says nothing when %s', (_name, value) => {
    expect(render(value).find('[data-testid=hard-round-card]').exists()).toBe(false)
  })

  it('emits start on the press and is not pressable while busy', async () => {
    const w = render(round())
    await w.get('[data-testid=hard-round-btn]').trigger('click')
    expect(w.emitted('start')).toHaveLength(1)

    await w.setProps({ busy: true })
    expect(w.get('[data-testid=hard-round-btn]').attributes('disabled')).toBeDefined()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = render(round({ words: 4 }))
    expect(w.get('[data-testid=hard-round-card]').text()).toContain("Qiyin so'zlar raundi")
    expect(w.get('[data-testid=hard-round-btn]').text()).toBe('Tahlil qilish')
    setLocale('ru')
  })
})
