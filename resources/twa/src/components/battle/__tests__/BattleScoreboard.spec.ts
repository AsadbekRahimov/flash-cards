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

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = mount(BattleScoreboard, { props: { me: 3, opponent: 4, opponentName: 'Dilnoza' } })

    expect(w.get('[data-testid=score-me-name]').text()).toBe('Siz')
    expect(w.get('[role=group]').attributes('aria-label')).toBe('Hisob: siz 3, Dilnoza 4')
  })
})
