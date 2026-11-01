import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BattleScoreboard from '../BattleScoreboard.vue'
import { setLocale } from '@/i18n'

afterEach(() => {
  setLocale('ru')
})

describe('BattleScoreboard', () => {
  it('shows the score big: the learner on the left, the opponent on the right', () => {
    const w = mount(BattleScoreboard, { props: { me: 100, opponent: 125, opponentName: 'Dilnoza' } })

    expect(w.get('[data-testid=score-me]').text()).toBe('100')
    expect(w.get('[data-testid=score-opponent]').text()).toBe('125')
    expect(w.get('[data-testid=score-me-name]').text()).toBe('Вы')
    expect(w.get('[data-testid=score-opponent-name]').text()).toBe('Dilnoza')
  })

  it('names the whole score for a screen reader, so that the digits are not read in pieces', () => {
    const w = mount(BattleScoreboard, { props: { me: 100, opponent: 125, opponentName: 'Dilnoza' } })

    const group = w.get('[role=group]')
    expect(group.attributes('aria-label')).toBe('Счёт: вы 100, Dilnoza 125')
  })

  it('the digits keep their width as they change (tabular), and a long name is cut', () => {
    const w = mount(BattleScoreboard, { props: { me: 0, opponent: 0, opponentName: 'A very long name' } })

    expect(w.get('[data-testid=score-me]').classes()).toContain('tabular-nums')
    expect(w.get('[data-testid=score-opponent-name]').classes()).toContain('truncate')
  })

  it('has a larger size for the result', () => {
    const md = mount(BattleScoreboard, { props: { me: 1, opponent: 2, opponentName: 'D' } })
    const lg = mount(BattleScoreboard, { props: { me: 1, opponent: 2, opponentName: 'D', size: 'lg' } })

    expect(md.get('[data-testid=score-me]').classes().join(' ')).toContain('text-[32px]')
    expect(lg.get('[data-testid=score-me]').classes().join(' ')).toContain('text-[44px]')
  })

  it('holds what is put under the score (the timer of the round)', () => {
    const w = mount(BattleScoreboard, { props: { me: 0, opponent: 0, opponentName: 'D' }, slots: { default: '<span data-testid="under">timer</span>' } })

    expect(w.get('[data-testid=under]').text()).toBe('timer')
    expect(mount(BattleScoreboard, { props: { me: 0, opponent: 0, opponentName: 'D' } }).find('[data-testid=under]').exists()).toBe(false)
  })

  // 18.12b. 320 x 568: the card was 126 px (a row of numbers and a row of the timer) and pushed the task of the round under the sticky
  // block. In a short window (max-height 640 px) the timer stands where the colon was, between the two scores: one row, 74 px.
  describe('with the timer of a round, in a short window', () => {
    const withTimer = () =>
      mount(BattleScoreboard, { props: { me: 0, opponent: 0, opponentName: 'D' }, slots: { default: '<span data-testid="under">timer</span>' } })

    it('is one row: the timer takes the place of the colon, the numbers are a little smaller, the card has less padding', () => {
      const w = withTimer()
      const card = w.get('[data-testid=scoreboard]')
      const colon = w.get('[aria-hidden=true]')
      const timer = w.get('[data-testid=scoreboard-timer]')

      expect(card.classes()).toEqual(expect.arrayContaining(['short:p-3', 'short:items-center', 'short:gap-y-0']))
      expect(colon.text()).toBe(':')
      expect(colon.classes()).toContain('short:hidden')
      expect(timer.classes()).toEqual(expect.arrayContaining(['short:col-start-2', 'short:row-start-1', 'short:col-span-1']))
      expect(timer.find('[data-testid=under]').exists()).toBe(true)
      for (const id of ['score-me', 'score-opponent']) expect(w.get(`[data-testid=${id}]`).classes()).toEqual(expect.arrayContaining(['short:text-[28px]', 'short:leading-8']))
    })

    it('is two rows in a window of the usual height: the timer is under the numbers, the colon is between them', () => {
      const w = withTimer()
      const timer = w.get('[data-testid=scoreboard-timer]')

      expect(timer.classes()).toEqual(expect.arrayContaining(['col-span-3', 'row-start-2']))
      expect(w.get('[data-testid=scoreboard]').classes()).toContain('gap-y-3')
    })

    it('every cell has its place, so that the numbers and the colon keep their columns whichever way the timer is laid out', () => {
      const w = withTimer()
      const place = (id: string) => w.get(`[data-testid=${id}]`).element.parentElement?.className ?? ''

      expect(place('score-me')).toContain('col-start-1')
      expect(place('score-opponent')).toContain('col-start-3')
      expect(w.get('[aria-hidden=true]').classes()).toContain('col-start-2')
    })

    it('has no empty row when the round has no timer (the review): the slot is not rendered, and the card keeps its colon and its size', () => {
      const w = mount(BattleScoreboard, { props: { me: 100, opponent: 0, opponentName: 'D' } })

      expect(w.find('[data-testid=scoreboard-timer]').exists()).toBe(false)
      expect(w.get('[data-testid=scoreboard]').classes().filter((name) => name.startsWith('short:'))).toEqual([])
      expect(w.get('[aria-hidden=true]').classes()).not.toContain('short:hidden')
      expect(w.get('[data-testid=score-me]').classes()).not.toContain('short:text-[28px]')
    })

    it('the result (size lg) is not touched: no timer, the big numbers', () => {
      const w = mount(BattleScoreboard, { props: { me: 450, opponent: 450, opponentName: 'D', size: 'lg' } })

      expect(w.get('[data-testid=score-me]').classes()).toContain('text-[44px]')
      expect(w.html()).not.toContain('short:')
    })
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = mount(BattleScoreboard, { props: { me: 3, opponent: 4, opponentName: 'Dilnoza' } })

    expect(w.get('[data-testid=score-me-name]').text()).toBe('Siz')
    expect(w.get('[role=group]').attributes('aria-label')).toBe('Hisob: siz 3, Dilnoza 4')
  })
})
