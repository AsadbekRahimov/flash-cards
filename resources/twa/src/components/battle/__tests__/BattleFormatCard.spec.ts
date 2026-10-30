import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BattleFormatCard from '../BattleFormatCard.vue'
import { setLocale } from '@/i18n'
import type { BattleModeOption } from '@/api/types'

afterEach(() => {
  setLocale('ru')
})

const option = (overrides: Partial<BattleModeOption> = {}): BattleModeOption => ({
  mode: 'pairs',
  state: 'available',
  available: 26,
  required: 20,
  reason: null,
  round_seconds: 45,
  ...overrides,
})

const render = (o: BattleModeOption = option(), selected = false) => mount(BattleFormatCard, { props: { option: o, selected } })

describe('BattleFormatCard — a format that can be played', () => {
  it('is one radio button: the name, an example, “45 s per round”', async () => {
    const w = render()
    const card = w.get('[data-testid=format-pairs]')

    expect(card.element.tagName).toBe('BUTTON')
    expect(card.attributes('role')).toBe('radio')
    expect(card.attributes('aria-checked')).toBe('false')
    expect(card.attributes('aria-label')).toBe('Найди пары. 45 с на раунд')
    expect(w.get('[data-testid=format-title]').text()).toBe('Найди пары')
    expect(w.get('[data-testid=format-seconds]').text()).toBe('45 с на раунд')
    expect(card.text()).toContain('mother ↔ мама')

    await card.trigger('click')
    expect(w.emitted('select')).toEqual([['pairs']])
  })

  it('the length of a round differs by format', () => {
    expect(render(option({ mode: 'collocation', round_seconds: 30 })).get('[data-testid=format-seconds]').text()).toBe('30 с на раунд')
    expect(render(option({ mode: 'dictation', round_seconds: 60 })).get('[data-testid=format-seconds]').text()).toBe('60 с на раунд')
  })

  it('the chosen one has a ring and a check mark (not the colour alone), and says so', () => {
    const w = render(option(), true)
    const card = w.get('[data-testid=format-pairs]')

    expect(card.attributes('aria-checked')).toBe('true')
    expect(card.attributes('data-state')).toBe('selected')
    expect(card.classes()).toContain('ring-accent')
    expect(w.find('[data-testid=format-check]').exists()).toBe(true)
  })

  it('a format that is not chosen has an empty circle and no check', () => {
    const w = render(option(), false)

    expect(w.find('[data-testid=format-check]').exists()).toBe(false)
    expect(w.get('[data-testid=format-pairs]').attributes('data-state')).toBe('available')
  })
})

describe('BattleFormatCard — a format that cannot be played', () => {
  it('too little material: a plain block with the chip and the reason with the numbers', async () => {
    const w = render(option({ state: 'not_enough', available: 14, required: 20, reason: 'no_words' }))
    const card = w.get('[data-testid=format-pairs]')

    expect(card.element.tagName).toBe('DIV')
    expect(card.attributes('role')).toBeUndefined()
    expect(w.get('[data-testid=format-chip]').text()).toBe('Мало материала')
    expect(w.get('[data-testid=format-chip]').classes()).toContain('chip-warning')
    expect(w.get('[data-testid=format-chip]').find('svg').exists()).toBe(true)
    expect(w.get('[data-testid=format-reason]').text()).toBe('В этой теме слов меньше, чем нужно для баттла: 14 из 20. Выберите другой урок или весь курс.')

    await card.trigger('click')
    expect(w.emitted('select')).toBeUndefined()
  })

  it.each([
    ['no_words', 'В этой теме слов меньше, чем нужно для баттла: 3 из 20. Выберите другой урок или весь курс.'],
    ['ambiguous', 'Подходящих слов: 3 из 20 — у многих совпадают переводы. Выберите другой урок.'],
    ['no_items', 'Заданий этого формата в теме меньше, чем нужно: 3 из 20.'],
    ['audio_pending', 'Записи для заданий ещё готовятся. Загляните позже.'],
    ['no_audio_words', 'Для слов этой темы пока нет записей.'],
    [null, 'Для этого формата пока не хватает материала.'],
  ] as const)('the reason %s is told in words', (reason, text) => {
    const w = render(option({ state: 'not_enough', available: 3, required: 20, reason }))

    expect(w.get('[data-testid=format-reason]').text()).toBe(text)
  })

  it('switched off: a plain block, dimmed, with the word', async () => {
    const w = render(option({ mode: 'sentence', state: 'disabled' }))
    const card = w.get('[data-testid=format-sentence]')

    expect(card.element.tagName).toBe('DIV')
    expect(card.classes()).toContain('opacity-60')
    expect(w.get('[data-testid=format-chip]').text()).toBe('Выключено')
    await card.trigger('click')
    expect(w.emitted('select')).toBeUndefined()
  })

  it('behind Pro: a plain block with the Pro chip', () => {
    const w = render(option({ mode: 'dictation', state: 'pro_required' }))

    expect(w.get('[data-testid=format-chip]').text()).toBe('Pro')
    expect(w.get('[data-testid=format-dictation]').element.tagName).toBe('DIV')
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = render(option({ state: 'not_enough', available: 14, required: 20, reason: 'no_words' }))

    expect(w.get('[data-testid=format-seconds]').text()).toBe('Har bir raundga 45 soniya')
    expect(w.get('[data-testid=format-reason]').text()).toContain('20 tadan 14 tasi')
  })
})
