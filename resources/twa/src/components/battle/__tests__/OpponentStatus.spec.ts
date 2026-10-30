import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import OpponentStatus from '../OpponentStatus.vue'
import type { PlayerState } from '../OpponentStatus.vue'
import { setLocale } from '@/i18n'

afterEach(() => {
  setLocale('ru')
})

const render = (state: PlayerState | null, name = 'Dilnoza', accent = false) => mount(OpponentStatus, { props: { name, state, accent } })

describe('OpponentStatus', () => {
  it.each([
    ['invited', 'ещё не ответил', 'chip-neutral'],
    ['not_ready', 'не готов', 'chip-neutral'],
    ['ready', 'готов', 'chip-success'],
    ['answering', 'отвечает…', 'chip-neutral'],
    ['answered', 'ответил', 'chip-success'],
    ['absent', 'нет связи', 'chip-warning'],
  ] as const)('%s: the word “%s” with a symbol, in the chip colour %s', (state, word, chip) => {
    const w = render(state)
    const el = w.get('[data-testid=player-chip]')

    expect(el.text()).toBe(word)
    expect(el.classes()).toContain(chip)
    expect(el.find('svg').exists()).toBe(true)
    expect(w.get('[data-testid=player-status]').attributes('data-state')).toBe(state)
  })

  it('shows the person: the first letter and the name', () => {
    const w = render('ready', 'Dilnoza')

    expect(w.get('[data-testid=player-name]').text()).toBe('Dilnoza')
    expect(w.text()).toContain('D')
  })

  it('is a live region for the chip: the change of the opponent is heard', () => {
    expect(render('answered').get('[data-testid=player-chip]').attributes('role')).toBe('status')
  })

  it('without a state it is just the person', () => {
    const w = render(null)

    expect(w.find('[data-testid=player-chip]').exists()).toBe(false)
    expect(w.get('[data-testid=player-name]').text()).toBe('Dilnoza')
  })

  it('the learner’s own avatar is in the accent colour', () => {
    expect(render('ready', 'Вы', true).html()).toContain('bg-accent')
    expect(render('ready', 'Вы', false).html()).not.toContain('bg-accent ')
  })

  it('cuts a long name with an ellipsis instead of pushing the chip out', () => {
    const w = render('ready', 'A very long name that does not fit in the row at all')

    expect(w.get('[data-testid=player-name]').classes()).toEqual(expect.arrayContaining(['truncate', 'min-w-0']))
    expect(w.get('[data-testid=player-chip]').classes()).toContain('shrink-0')
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    expect(render('answered').get('[data-testid=player-chip]').text()).toBe('javob berdi')
    expect(render('absent').get('[data-testid=player-chip]').text()).toBe("aloqa yo'q")
  })
})
