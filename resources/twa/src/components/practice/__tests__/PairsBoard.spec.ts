import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import PairsBoard from '../PairsBoard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { setLocale } from '@/i18n'
import { pairsElement } from '@/api/__tests__/practiceFixtures'
import type { PairsRoundElement } from '@/api/types'

const tg = vi.hoisted(() => ({ haptic: vi.fn(), selectionHaptic: vi.fn(), notificationHaptic: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => tg }))

/*
 * "Find the pairs" (docs/16 §11.3, docs/15 §3.1). The fixture round: mother-мама, father-папа, sister-сестра, brother-брат
 *   words:        lM1 mother · lF2 father · lS3 sister · lB4 brother
 *   translations: rT1 сестра · rU2 мама · rV3 брат · rW4 папа   (shuffled apart, as the server shuffles them)
 * The server says whether a match is right: the board learns it from the new `element` (a pair in `matched`: right; one more in
 * `mismatches`: wrong), the way the practice store hands it over.
 */

const PAIR = { mother: ['lM1', 'rU2'], father: ['lF2', 'rW4'], sister: ['lS3', 'rT1'], brother: ['lB4', 'rV3'] } as const

type Mounted = VueWrapper<InstanceType<typeof PairsBoard>>

let wrapper: Mounted | null = null

function board(props: { element?: PairsRoundElement; mode?: 'solo' | 'battle'; disabled?: boolean } = {}): Mounted {
  wrapper = mount(PairsBoard, {
    props: { element: pairsElement(), mode: 'solo', ...props },
    attachTo: document.body,
  }) as Mounted
  return wrapper
}

const card = (w: Mounted, id: string) => w.get(`[data-testid=${id.startsWith('l') ? 'left' : 'right'}-${id}]`)
const state = (w: Mounted, id: string) => card(w, id).attributes('data-state')
const status = (w: Mounted) => w.get('[data-testid=pairs-status]')

/** Taps a word and then a translation (either order). */
async function match(w: Mounted, first: string, second: string): Promise<void> {
  await card(w, first).trigger('click')
  await card(w, second).trigger('click')
}

const answers = (w: Mounted) => w.emitted('answer') ?? []

/** What the store does with the reply: the new element comes in and the board is free again. */
async function reply(w: Mounted, element: PairsRoundElement): Promise<void> {
  await w.setProps({ element, disabled: false })
}

beforeEach(() => {
  vi.useFakeTimers()
  tg.haptic.mockClear()
  tg.selectionHaptic.mockClear()
  tg.notificationHaptic.mockClear()
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  vi.useRealTimers()
  setLocale('ru')
})

describe('PairsBoard — the board', () => {
  it('has the words on the left and the translations on the right, every card a button of at least 56 px', () => {
    const w = board()

    expect(w.findAll('[data-testid=pairs-left] button').map((b) => b.text())).toEqual(['mother', 'father', 'sister', 'brother'])
    expect(w.findAll('[data-testid=pairs-right] button').map((b) => b.text())).toEqual(['сестра', 'мама', 'брат', 'папа'])
    for (const button of w.findAll('button[data-card]')) {
      expect(button.element.tagName).toBe('BUTTON')
      expect(button.attributes('type')).toBe('button')
      expect(button.classes()).toContain('option') // min-h 56 px in the stylesheet (docs/14 §4.3)
      expect(button.attributes('data-state')).toBe('idle')
      expect(button.attributes('aria-pressed')).toBe('false')
    }
  })

  it('marks the English words as English, for a reader that pronounces them and a browser that hyphenates them', () => {
    const w = board()

    for (const word of w.findAll('[data-testid=pairs-left] [data-testid=card-text]')) {
      expect(word.attributes('lang')).toBe('en')
    }
    for (const translation of w.findAll('[data-testid=pairs-right] [data-testid=card-text]')) {
      expect(translation.attributes('lang')).toBeUndefined() // the language of the screen
    }
  })

  it('names the two columns for a screen reader and says which round it is and how many pairs are left', () => {
    const w = board({ element: pairsElement({ round: 2, rounds: 3 }) })

    expect(w.get('[data-testid=pairs-left]').attributes('role')).toBe('group')
    expect(w.get('[data-testid=pairs-left]').attributes('aria-label')).toBe('Слова')
    expect(w.get('[data-testid=pairs-right]').attributes('aria-label')).toBe('Переводы')
    expect(w.get('[data-testid=pairs-round]').text()).toBe('Раунд 2 из 3')
    expect(w.get('[data-testid=pairs-count]').text()).toBe('Осталось 4 пары')
    expect(w.get('h2').classes()).toContain('sr-only')
    expect(w.get('h2').text()).toBe('Найди пары')
  })

  it('has a live line that says what to do', () => {
    const w = board()

    expect(status(w).attributes('role')).toBe('status')
    expect(status(w).attributes('aria-live')).toBe('polite')
    expect(status(w).text()).toBe('Нажмите слово, затем его перевод — или наоборот.')
  })

  it('lets a long translation wrap: it is not cut, not clipped, not shortened', () => {
    const long = 'достопримечательность и ответственность'
    const w = board({ element: pairsElement({ right: [{ id: 'rT1', text: long }, { id: 'rU2', text: 'мама' }, { id: 'rV3', text: 'брат' }, { id: 'rW4', text: 'папа' }] }) })
    const cell = card(w, 'rT1')

    expect(cell.text()).toBe(long)
    expect(cell.get('.break-words').text()).toBe(long)
    for (const cut of ['truncate', 'overflow-hidden', 'line-clamp-1', 'whitespace-nowrap', 'text-ellipsis']) {
      expect(cell.classes(), cut).not.toContain(cut)
      expect(cell.get('.break-words').classes(), cut).not.toContain(cut)
    }
    // Two columns of equal width that may shrink below their content (the grid of 320 px does not burst).
    expect(w.get('[data-testid=pairs-right]').classes()).toContain('min-w-0')
    expect(w.get('[data-testid=pairs-left]').classes()).toContain('min-w-0')
  })
})

describe('PairsBoard — picking a card and letting it go', () => {
  it('a tap on a word picks it: pressed, a dot besides the colour, a word for a screen reader, and the next step in the live line', async () => {
    const w = board()

    await card(w, 'lM1').trigger('click')

    expect(state(w, 'lM1')).toBe('selected')
    expect(card(w, 'lM1').attributes('aria-pressed')).toBe('true')
    expect(card(w, 'lM1').find('[data-testid=pick-dot]').exists()).toBe(true)
    expect(card(w, 'lM1').get('.sr-only').text()).toContain('выбрано')
    expect(status(w).text()).toBe('Теперь выберите перевод слова «mother»')
    expect(state(w, 'lF2')).toBe('idle')
    expect(answers(w)).toHaveLength(0)
  })

  it('a second tap on the picked card lets it go', async () => {
    const w = board()

    await card(w, 'lM1').trigger('click')
    await card(w, 'lM1').trigger('click')

    expect(state(w, 'lM1')).toBe('idle')
    expect(card(w, 'lM1').attributes('aria-pressed')).toBe('false')
    expect(card(w, 'lM1').find('[data-testid=pick-dot]').exists()).toBe(false)
    expect(status(w).text()).toBe('Нажмите слово, затем его перевод — или наоборот.')
    expect(answers(w)).toHaveLength(0)
  })

  it('a tap on another card of the same side moves the pick', async () => {
    const w = board()

    await card(w, 'lM1').trigger('click')
    await card(w, 'lF2').trigger('click')

    expect(state(w, 'lM1')).toBe('idle')
    expect(state(w, 'lF2')).toBe('selected')
    expect(answers(w)).toHaveLength(0)
  })

  it('moves the pick on the right side as well', async () => {
    const w = board()

    await card(w, 'rU2').trigger('click')
    await card(w, 'rW4').trigger('click')

    expect(state(w, 'rU2')).toBe('idle')
    expect(state(w, 'rW4')).toBe('selected')
    expect(answers(w)).toHaveLength(0)
  })

  it('may start from the translation: it is picked, the live line asks for the word', async () => {
    const w = board()

    await card(w, 'rU2').trigger('click')

    expect(state(w, 'rU2')).toBe('selected')
    expect(status(w).text()).toBe('Теперь выберите слово с переводом «мама»')

    await card(w, 'lM1').trigger('click')
    expect(answers(w)).toEqual([[{ left_id: 'lM1', right_id: 'rU2' }]]) // the payload is the same whichever card was first
  })

  it('Escape lets the pick go', async () => {
    const w = board()

    await card(w, 'lM1').trigger('click')
    await w.get('[data-testid=pairs-board]').trigger('keydown', { key: 'Escape' })

    expect(state(w, 'lM1')).toBe('idle')
  })

  it('does not feel a pick in the hand: the haptic is for the answer, not for the tap', async () => {
    const w = board()

    await card(w, 'lM1').trigger('click')
    await card(w, 'lM1').trigger('click')

    expect(tg.haptic).not.toHaveBeenCalled()
    expect(tg.notificationHaptic).not.toHaveBeenCalled()
  })
})

describe('PairsBoard — a match in a solo round', () => {
  it('is sent at once: one answer with the word and the translation', async () => {
    const w = board()

    await match(w, 'lM1', 'rU2')

    expect(answers(w)).toEqual([[{ left_id: 'lM1', right_id: 'rU2' }]])
    // Both cards wait for the verdict, picked-looking; the board does not guess whether the pair is right.
    expect(state(w, 'lM1')).toBe('selected')
    expect(state(w, 'rU2')).toBe('selected')
    expect(status(w).text()).toBe('Проверяем…')
  })

  it('sends a wrong pair as well: it is the server who says it is wrong', async () => {
    const w = board()

    await match(w, 'lM1', 'rW4')

    expect(answers(w)).toEqual([[{ left_id: 'lM1', right_id: 'rW4' }]])
  })

  it('takes no other tap while the match is on its way: no second request, however often the learner taps', async () => {
    const w = board()

    await match(w, 'lM1', 'rU2')
    await match(w, 'lM1', 'rU2') // the same pair again
    await match(w, 'lF2', 'rW4') // another one
    await card(w, 'lS3').trigger('click')

    expect(answers(w)).toHaveLength(1)
    expect(state(w, 'lF2')).toBe('idle')
    expect(state(w, 'lS3')).toBe('idle')
  })

  it('takes no tap while the screen says an answer is on its way (disabled)', async () => {
    const w = board({ disabled: true })

    await match(w, 'lM1', 'rU2')

    expect(answers(w)).toHaveLength(0)
    expect(state(w, 'lM1')).toBe('idle')
    expect(w.get('[data-testid=pairs-board] > div').attributes('aria-busy')).toBe('true')
  })

  it('shows a right pair green with a check for 0.4 s and a word, then the pair leaves the board', async () => {
    const w = board()
    await match(w, 'lM1', 'rU2')
    await w.setProps({ disabled: true })

    await reply(w, pairsElement({ matched: [[...PAIR.mother]] }))

    for (const id of PAIR.mother) {
      expect(state(w, id)).toBe('correct')
      expect(card(w, id).findComponent(AppIcon).props('name')).toBe('check')
      expect(card(w, id).get('.sr-only').text()).toContain('верно')
    }
    expect(status(w).text()).toBe('Верно: mother — мама')
    expect(status(w).attributes('data-tone')).toBe('success')
    expect(w.get('[data-testid=pairs-count]').text()).toBe('Осталось 3 пары')

    await vi.advanceTimersByTimeAsync(399)
    expect(state(w, 'lM1')).toBe('correct')
    await vi.advanceTimersByTimeAsync(1)

    for (const id of PAIR.mother) {
      expect(state(w, id)).toBe('gone')
      expect(card(w, id).classes()).toContain('invisible')
      expect(card(w, id).attributes('aria-hidden')).toBe('true')
      expect(card(w, id).attributes('tabindex')).toBe('-1')
      expect(card(w, id).attributes('disabled')).toBeDefined()
    }
    expect(status(w).text()).toBe('Нажмите слово, затем его перевод — или наоборот.')
    expect(tg.notificationHaptic).not.toHaveBeenCalled()
  })

  it('shows a wrong pair red with a cross for 0.6 s, buzzes, and gives the cards back - without telling the right answer', async () => {
    const w = board()
    await match(w, 'lM1', 'rW4')
    await w.setProps({ disabled: true })

    await reply(w, pairsElement({ mismatches: 1 }))

    for (const id of ['lM1', 'rW4']) {
      expect(state(w, id)).toBe('incorrect')
      expect(card(w, id).findComponent(AppIcon).props('name')).toBe('x-mark')
      expect(card(w, id).get('.sr-only').text()).toContain('не подходит')
    }
    expect(status(w).text()).toBe('Не подходит. Попробуйте ещё раз.')
    expect(status(w).attributes('data-tone')).toBe('danger')
    expect(tg.notificationHaptic).toHaveBeenCalledTimes(1)
    expect(tg.notificationHaptic).toHaveBeenCalledWith('error')
    // The right translation of «mother» stays one card among the others.
    expect(state(w, 'rU2')).toBe('idle')
    expect(w.text()).not.toContain('mother — мама')

    await vi.advanceTimersByTimeAsync(599)
    expect(state(w, 'lM1')).toBe('incorrect')
    await vi.advanceTimersByTimeAsync(1)

    for (const id of ['lM1', 'rW4']) {
      expect(state(w, id)).toBe('idle') // not picked any more: the learner starts again
      expect(card(w, id).attributes('aria-pressed')).toBe('false')
    }
    expect(w.get('[data-testid=pairs-count]').text()).toBe('Осталось 4 пары')
  })

  it('lets the learner try the same word again, and sends it again', async () => {
    const w = board()
    await match(w, 'lM1', 'rW4')
    await w.setProps({ disabled: true })
    await reply(w, pairsElement({ mismatches: 1 }))
    await vi.advanceTimersByTimeAsync(600)

    await match(w, 'lM1', 'rU2')

    expect(answers(w)).toEqual([[{ left_id: 'lM1', right_id: 'rW4' }], [{ left_id: 'lM1', right_id: 'rU2' }]])
  })

  it('takes a tap while a wrong pair is still red: the red goes at once, the tap counts', async () => {
    const w = board()
    await match(w, 'lM1', 'rW4')
    await w.setProps({ disabled: true })
    await reply(w, pairsElement({ mismatches: 1 }))

    await card(w, 'lF2').trigger('click')

    expect(state(w, 'lM1')).toBe('idle')
    expect(state(w, 'rW4')).toBe('idle')
    expect(state(w, 'lF2')).toBe('selected')
  })

  it('does not take a tap on a pair that is done, not even in the 0.4 s it is still on the screen', async () => {
    const w = board()
    await match(w, 'lM1', 'rU2')
    await w.setProps({ disabled: true })
    await reply(w, pairsElement({ matched: [[...PAIR.mother]] }))

    await match(w, 'lM1', 'rU2') // «correct» is on the cards
    await vi.advanceTimersByTimeAsync(400)
    await match(w, 'lM1', 'rU2') // and now they are gone

    expect(answers(w)).toHaveLength(1)
  })

  it('plays a round: pair after pair, each one leaves, the counter goes down', async () => {
    const w = board()
    const matched: [string, string][] = []
    const counts: string[] = []

    for (const [left, right] of [PAIR.mother, PAIR.father, PAIR.sister]) {
      await match(w, left, right)
      await w.setProps({ disabled: true })
      matched.push([left, right])
      await reply(w, pairsElement({ matched: [...matched] }))
      await vi.advanceTimersByTimeAsync(400)
      counts.push(w.get('[data-testid=pairs-count]').text())
    }

    expect(counts).toEqual(['Осталось 3 пары', 'Осталось 2 пары', 'Осталась 1 пара'])
    expect(answers(w)).toEqual([
      [{ left_id: 'lM1', right_id: 'rU2' }],
      [{ left_id: 'lF2', right_id: 'rW4' }],
      [{ left_id: 'lS3', right_id: 'rT1' }],
    ])
    expect(w.findAll('button[data-state="gone"]')).toHaveLength(6)
    expect(w.findAll('button[data-state="idle"]')).toHaveLength(2)
  })

  it('frees the board when the answer was lost on the way: nothing is picked, nothing is shown, the learner may try again', async () => {
    const w = board()
    await match(w, 'lM1', 'rU2')
    await w.setProps({ disabled: true })

    await w.setProps({ disabled: false }) // network error: the store makes the board ready again, the element is as it was

    expect(state(w, 'lM1')).toBe('idle')
    expect(state(w, 'rU2')).toBe('idle')
    expect(status(w).attributes('data-tone')).toBe('hint')
    expect(tg.notificationHaptic).not.toHaveBeenCalled()

    await match(w, 'lM1', 'rU2')
    expect(answers(w)).toHaveLength(2) // the same pair goes again; the store gives it the same request id
    expect(answers(w)[1]).toEqual(answers(w)[0])
  })

  it('shows a pair that the server says is done already (another tab was first) as a right one', async () => {
    const w = board()
    await match(w, 'lM1', 'rU2')
    await w.setProps({ disabled: true })

    await reply(w, pairsElement({ matched: [[...PAIR.mother], [...PAIR.father]] })) // the re-read after 409 pair_already_matched

    expect(state(w, 'lF2')).toBe('correct')
    await vi.advanceTimersByTimeAsync(400)
    expect(state(w, 'lM1')).toBe('gone')
    expect(state(w, 'lF2')).toBe('gone')
  })
})

describe('PairsBoard — a reload', () => {
  it('brings back the pairs that were done: they are not on the board, with no animation, and what is left can be played', async () => {
    const w = board({ element: pairsElement({ matched: [[...PAIR.mother], [...PAIR.father]], mismatches: 2 }) })

    for (const id of [...PAIR.mother, ...PAIR.father]) {
      expect(state(w, id)).toBe('gone')
    }
    expect(w.get('[data-testid=pairs-count]').text()).toBe('Осталось 2 пары')
    expect(status(w).attributes('data-tone')).toBe('hint')
    expect(tg.notificationHaptic).not.toHaveBeenCalled()
    expect(w.findAll('button[data-state="idle"]')).toHaveLength(4)

    await match(w, 'lS3', 'rT1')
    expect(answers(w)).toEqual([[{ left_id: 'lS3', right_id: 'rT1' }]])
  })

  it('keeps the order of the cards it was given', () => {
    const w = board({ element: pairsElement({ matched: [[...PAIR.sister]] }) })

    expect(w.findAll('[data-testid=pairs-left] button').map((b) => b.attributes('data-testid'))).toEqual(['left-lM1', 'left-lF2', 'left-lS3', 'left-lB4'])
    expect(w.findAll('[data-testid=pairs-right] button').map((b) => b.attributes('data-testid'))).toEqual(['right-rT1', 'right-rU2', 'right-rV3', 'right-rW4'])
  })

  it('starts afresh for another round of the same board', async () => {
    const w = board()
    await card(w, 'lM1').trigger('click')

    await w.setProps({ element: pairsElement({ id: 'ePair00002', round: 2 }) })

    expect(state(w, 'lM1')).toBe('idle')
    expect(w.get('[data-testid=pairs-round]').text()).toBe('Раунд 2 из 3')
  })
})

describe('PairsBoard — the keyboard', () => {
  it('has every card in the tab order, words first and then translations, and none that is out of reach', () => {
    const w = board()
    const order = w.findAll('button[data-card]').map((b) => b.attributes('data-testid'))

    expect(order).toEqual(['left-lM1', 'left-lF2', 'left-lS3', 'left-lB4', 'right-rT1', 'right-rU2', 'right-rV3', 'right-rW4'])
    for (const button of w.findAll('button[data-card]')) {
      expect(button.attributes('tabindex')).toBeUndefined()
      expect(button.attributes('disabled')).toBeUndefined()
    }
  })

  it('works with a press of Enter or Space on a card (which a browser turns into a click)', async () => {
    const w = board()

    ;(card(w, 'lM1').element as HTMLButtonElement).click()
    ;(card(w, 'rU2').element as HTMLButtonElement).click()
    await w.vm.$nextTick()

    expect(answers(w)).toEqual([[{ left_id: 'lM1', right_id: 'rU2' }]])
  })

  it('keeps a keyboard user in the board: when the card with the focus leaves, the next card takes it', async () => {
    const w = board()
    ;(card(w, 'lM1').element as HTMLButtonElement).focus()
    await match(w, 'lM1', 'rU2')
    await w.setProps({ disabled: true })
    await reply(w, pairsElement({ matched: [[...PAIR.mother]] }))
    expect(document.activeElement).toBe(card(w, 'lM1').element)

    await vi.advanceTimersByTimeAsync(400)

    expect(document.activeElement).toBe(card(w, 'lF2').element)
  })

  it('does not take the focus from a learner who has it elsewhere', async () => {
    const outside = document.createElement('button')
    document.body.appendChild(outside)
    const w = board()
    await match(w, 'lM1', 'rU2')
    await w.setProps({ disabled: true })
    await reply(w, pairsElement({ matched: [[...PAIR.mother]] }))
    outside.focus()

    await vi.advanceTimersByTimeAsync(400)

    expect(document.activeElement).toBe(outside)
    outside.remove()
  })
})

describe('PairsBoard — a battle round: the whole table at once', () => {
  const grown = (w: Mounted) => w.findAll('[data-testid=pair-number]').map((n) => n.text())

  it('has no "check" before every pair is made, and no answer from a tap', async () => {
    const w = board({ mode: 'battle' })
    const check = w.get('[data-testid=pairs-check]')

    expect(check.text()).toBe('Проверить')
    expect(check.attributes('disabled')).toBeDefined()
    expect(check.classes()).toContain('btn-primary')
    expect(w.get('[data-testid=pairs-count]').text()).toBe('Соединено 0 из 4')
    expect(w.get('[data-testid=pairs-status]').text()).toBe('Соедините слова с переводами и нажмите «Проверить». Пару можно поменять.')

    await match(w, 'lM1', 'rU2')
    await match(w, 'lF2', 'rW4')

    expect(answers(w)).toHaveLength(0)
    expect(w.get('[data-testid=pairs-count]').text()).toBe('Соединено 2 из 4')
    expect(w.get('[data-testid=pairs-check]').attributes('disabled')).toBeDefined()
  })

  it('numbers the pairs, with a symbol on both cards of a pair, and keeps them on the board', async () => {
    const w = board({ mode: 'battle' })

    await match(w, 'lM1', 'rU2')
    await match(w, 'rW4', 'lF2') // from the translation this time

    expect(grown(w)).toEqual(['1', '2', '1', '2'])
    for (const id of ['lM1', 'rU2']) {
      expect(state(w, id)).toBe('selected')
      expect(card(w, id).attributes('data-pair')).toBe('1')
      expect(card(w, id).attributes('aria-pressed')).toBe('true')
      expect(card(w, id).get('.sr-only').text()).toContain('пара 1')
    }
    expect(card(w, 'lF2').attributes('data-pair')).toBe('2')
    expect(card(w, 'rW4').attributes('data-pair')).toBe('2')
    expect(state(w, 'lS3')).toBe('idle')
    expect(w.findAll('button[data-state="gone"]')).toHaveLength(0)
  })

  it('may be changed before it is sent: a tap on a paired card breaks the pair up and picks the card', async () => {
    const w = board({ mode: 'battle' })
    await match(w, 'lM1', 'rU2')

    await card(w, 'lM1').trigger('click')

    expect(grown(w)).toEqual([])
    expect(state(w, 'rU2')).toBe('idle')
    expect(state(w, 'lM1')).toBe('selected')
    expect(card(w, 'lM1').find('[data-testid=pick-dot]').exists()).toBe(true)

    await card(w, 'rW4').trigger('click')
    expect(card(w, 'lM1').attributes('data-pair')).toBe('1')
    expect(card(w, 'rW4').attributes('data-pair')).toBe('1')
    expect(answers(w)).toHaveLength(0)
  })

  it('takes a card over from its pair: the old pair breaks up, the new one is made', async () => {
    const w = board({ mode: 'battle' })
    await match(w, 'lM1', 'rU2')
    await match(w, 'lF2', 'rW4')

    await match(w, 'lS3', 'rU2') // «мама» goes from mother to sister

    expect(state(w, 'lM1')).toBe('idle')
    expect(card(w, 'lS3').attributes('data-pair')).toBe('1') // the number the broken pair freed
    expect(card(w, 'rU2').attributes('data-pair')).toBe('1')
    expect(card(w, 'lF2').attributes('data-pair')).toBe('2')
    expect(w.get('[data-testid=pairs-count]').text()).toBe('Соединено 2 из 4')
  })

  it('reuses the lowest free number', async () => {
    const w = board({ mode: 'battle' })
    await match(w, 'lM1', 'rU2')
    await match(w, 'lF2', 'rW4')
    await card(w, 'lM1').trigger('click') // pair 1 is gone
    await card(w, 'lM1').trigger('click') // let go

    await match(w, 'lS3', 'rT1')

    expect(card(w, 'lS3').attributes('data-pair')).toBe('1')
  })

  it('lets a pick go with another tap on the same card', async () => {
    const w = board({ mode: 'battle' })

    await card(w, 'lM1').trigger('click')
    await card(w, 'lM1').trigger('click')

    expect(state(w, 'lM1')).toBe('idle')
    expect(tg.haptic).not.toHaveBeenCalled()
  })

  it('sends the table ONCE, with "Check": every word and its translation', async () => {
    const w = board({ mode: 'battle' })
    await match(w, 'lM1', 'rU2')
    await match(w, 'lF2', 'rW4')
    await match(w, 'lS3', 'rT1')
    expect(w.get('[data-testid=pairs-status]').text()).not.toContain('Все пары собраны')
    await match(w, 'lB4', 'rV3')
    expect(w.get('[data-testid=pairs-status]').text()).toBe('Все пары собраны. Нажмите «Проверить».')
    expect(w.get('[data-testid=pairs-check]').attributes('disabled')).toBeUndefined()

    await w.get('[data-testid=pairs-check]').trigger('click')
    await w.get('[data-testid=pairs-check]').trigger('click')
    ;(w.get('[data-testid=pairs-check]').element as HTMLButtonElement).click()

    expect(answers(w)).toEqual([[{ pairs: { lM1: 'rU2', lF2: 'rW4', lS3: 'rT1', lB4: 'rV3' } }]])
  })

  it('sends the table as it was changed to, not as it was first made', async () => {
    const w = board({ mode: 'battle' })
    // First everything wrong...
    await match(w, 'lM1', 'rW4')
    await match(w, 'lF2', 'rU2')
    await match(w, 'lS3', 'rV3')
    await match(w, 'lB4', 'rT1')
    // ...then mended: mother takes «мама» (father lets go of it), father takes «папа».
    await match(w, 'lM1', 'rU2')
    await match(w, 'lF2', 'rW4')

    await w.get('[data-testid=pairs-check]').trigger('click')

    const pairs = (answers(w)[0]?.[0] as { pairs: Record<string, string> }).pairs
    expect(pairs.lM1).toBe('rU2')
    expect(pairs.lF2).toBe('rW4')
    expect(Object.keys(pairs).sort()).toEqual(['lB4', 'lF2', 'lM1', 'lS3'])
    expect(new Set(Object.values(pairs)).size).toBe(4) // a translation is on one pair only
  })

  it('is final once sent: the cards no longer take a tap, the button is gone dim, the line says so', async () => {
    const w = board({ mode: 'battle' })
    for (const [left, right] of [PAIR.mother, PAIR.father, PAIR.sister, PAIR.brother]) await match(w, left, right)
    await w.get('[data-testid=pairs-check]').trigger('click')

    await card(w, 'lM1').trigger('click')

    expect(grown(w)).toEqual(['1', '2', '3', '4', '3', '1', '4', '2']) // the pairs stay as they were made
    expect(card(w, 'lM1').attributes('aria-disabled')).toBe('true')
    expect(w.get('[data-testid=pairs-check]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=pairs-status]').text()).toBe('Ответ отправлен.')
    expect(answers(w)).toHaveLength(1)
  })

  it('gives the table back for a second try when the screen frees the board after a lost answer', async () => {
    const w = board({ mode: 'battle' })
    for (const [left, right] of [PAIR.mother, PAIR.father, PAIR.sister, PAIR.brother]) await match(w, left, right)
    await w.get('[data-testid=pairs-check]').trigger('click')
    await w.setProps({ disabled: true })

    await w.setProps({ disabled: false }) // the answer did not get through

    expect(w.get('[data-testid=pairs-check]').attributes('disabled')).toBeUndefined()
    await w.get('[data-testid=pairs-check]').trigger('click')
    expect(answers(w)).toHaveLength(2)
    expect(answers(w)[1]).toEqual(answers(w)[0])
  })

  it('does nothing while the screen says it is busy', async () => {
    const w = board({ mode: 'battle', disabled: true })

    await match(w, 'lM1', 'rU2')

    expect(grown(w)).toEqual([])
    expect(w.get('[data-testid=pairs-check]').attributes('disabled')).toBeDefined()
  })

  it('feels a pair being made in the hand, lightly', async () => {
    const w = board({ mode: 'battle' })

    await match(w, 'lM1', 'rU2')

    expect(tg.haptic).toHaveBeenCalledWith('light')
    expect(tg.notificationHaptic).not.toHaveBeenCalled()
  })

  it('knows nothing of the verdicts of a solo round: no flash, no hidden cards', async () => {
    const w = board({ mode: 'battle' })
    await match(w, 'lM1', 'rU2')

    await w.setProps({ element: pairsElement({ matched: [[...PAIR.father]], mismatches: 3 }) })

    expect(w.findAll('button[data-state="gone"]')).toHaveLength(0)
    expect(w.findAll('button[data-state="correct"]')).toHaveLength(0)
    expect(w.findAll('button[data-state="incorrect"]')).toHaveLength(0)
    expect(tg.notificationHaptic).not.toHaveBeenCalled()
  })
})

describe('PairsBoard — Uzbek', () => {
  it('speaks uz: the round, the columns, the hint, the verdict', async () => {
    setLocale('uz')
    const w = board({ element: pairsElement({ round: 1, rounds: 3 }) })

    expect(w.get('[data-testid=pairs-round]').text()).toBe('3 raunddan 1-raund')
    expect(w.get('[data-testid=pairs-count]').text()).toBe('4 ta juft qoldi')
    expect(w.get('[data-testid=pairs-left]').attributes('aria-label')).toBe("So'zlar")
    expect(w.get('[data-testid=pairs-right]').attributes('aria-label')).toBe('Tarjimalar')
    expect(status(w).text()).toBe("So'zni, keyin uning tarjimasini bosing — yoki teskarisi.")

    await card(w, 'lM1').trigger('click')
    expect(status(w).text()).toBe("Endi «mother» so'zining tarjimasini tanlang")
    expect(card(w, 'lM1').get('.sr-only').text()).toContain('tanlandi')

    await card(w, 'rU2').trigger('click')
    await w.setProps({ disabled: true })
    await reply(w, pairsElement({ matched: [[...PAIR.mother]] }))
    expect(status(w).text()).toBe("To'g'ri: mother — мама")
  })
})
