import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BattleResult from '../BattleResult.vue'
import { closedBattle, finishedBattle, invitedBattle } from '@/api/__tests__/battleFixtures'
import { setLocale } from '@/i18n'
import type { BattleAction } from '@/stores/battle'
import type { Battle as BattleData, BattleEndReason, BattleOutcome } from '@/api/types'

afterEach(() => {
  setLocale('ru')
})

function render(battle: BattleData = finishedBattle(), props: { rematch?: BattleData | null; busy?: BattleAction | null } = {}) {
  return mount(BattleResult, {
    props: {
      battle,
      result: battle.result ?? { outcome: 'none', end_reason: 'canceled', rounds: [] },
      rematch: null,
      busy: null,
      ...props,
    },
  })
}

type Wrapper = ReturnType<typeof render>
const primaries = (w: Wrapper) => w.findAll('button.btn-primary')

describe('BattleResult — the outcome', () => {
  it.each([
    ['won', 'Победа', 'bg-success-soft'],
    ['lost', 'Поражение', 'bg-track'],
    ['draw', 'Ничья', 'bg-accent-soft'],
  ] as const)('%s: “%s” big, with a symbol and a colour of its own', (outcome, title, circle) => {
    const w = render(finishedBattle(outcome))

    expect(w.get('[data-testid=battle-result]').attributes('data-outcome')).toBe(outcome)
    expect(w.get('h1').text()).toBe(title)
    expect(w.get('[data-testid=result-icon]').classes()).toContain(circle)
    expect(w.get('[data-testid=result-icon]').find('svg').exists()).toBe(true)
  })

  it('a loss is not painted red: a quiet colour, a word and a symbol', () => {
    const w = render(finishedBattle('lost'))

    expect(w.get('[data-testid=result-icon]').classes().join(' ')).not.toMatch(/danger|red/)
  })

  it('a battle that did not take place: “The match did not take place”, with no score', () => {
    const w = render(closedBattle('room_expired'))

    expect(w.get('h1').text()).toBe('Матч не состоялся')
    expect(w.find('[data-testid=scoreboard]').exists()).toBe(false)
    expect(w.find('[data-testid=result-rounds]').exists()).toBe(false)
  })

  it('tells what was played and where', () => {
    const w = render(finishedBattle())

    expect(w.get('[data-testid=result-topic]').text()).toBe('Собери предложение · Модуль 2 · Урок 4 · Еда')
    expect(render(finishedBattle('won', 'completed', { lesson: null })).get('[data-testid=result-topic]').text()).toBe('Собери предложение · Английский A1')
  })
})

describe('BattleResult — why it ended (end_reason), in words', () => {
  const cases: [BattleEndReason, BattleOutcome, string][] = [
    ['completed', 'won', 'Сыграны все раунды.'],
    ['completed', 'lost', 'Сыграны все раунды.'],
    ['completed', 'draw', 'Сыграны все раунды, счёт равный.'],
    ['surrender', 'won', 'Соперник сдался — победа ваша.'],
    ['surrender', 'lost', 'Вы сдались: победа засчитана сопернику.'],
    ['absent', 'won', 'Соперник вышел из матча и не вернулся — победа ваша.'],
    ['absent', 'lost', 'Вы пропали из матча: засчитано поражение.'],
    ['both_absent', 'none', 'Оба игрока пропали — матч закрыт без победителя.'],
    ['declined', 'none', 'Приглашение отклонено.'],
    ['invite_expired', 'none', 'Приглашение не приняли вовремя.'],
    ['room_expired', 'none', 'Комната закрыта: матч не начался вовремя.'],
    ['canceled', 'none', 'Матч отменили до начала.'],
    ['voided', 'none', 'Матч аннулирован из-за технической проблемы. Очки не засчитаны.'],
  ]

  it.each(cases)('%s (%s): %s', (reason, outcome, text) => {
    const battle = outcome === 'none' ? closedBattle(reason) : finishedBattle(outcome, reason)
    const w = render(battle)

    expect(w.get('[data-testid=result-reason]').text()).toBe(text)
    expect(w.get('[data-testid=battle-result]').attributes('data-reason')).toBe(reason)
  })

  it('has a word for every reason in Uzbek too', () => {
    setLocale('uz')
    const texts = cases.map(([reason, outcome]) => render(outcome === 'none' ? closedBattle(reason) : finishedBattle(outcome, reason)).get('[data-testid=result-reason]').text())

    expect(texts.every((text) => text !== '' && !/\p{Script=Cyrillic}/u.test(text))).toBe(true)
    expect(new Set(texts).size).toBe(texts.length - 1) // `completed` reads the same for the winner and for the loser
  })
})

describe('BattleResult — the score and the rounds', () => {
  it('shows the final score big', () => {
    const w = render(finishedBattle('won'))

    expect(w.get('[data-testid=score-me]').text()).toBe('300')
    expect(w.get('[data-testid=score-opponent]').text()).toBe('200')
    expect(w.get('[data-testid=score-opponent-name]').text()).toBe('Dilnoza')
  })

  it('lists the rounds: for each player a symbol, the points and (for a screen reader) the verdict', () => {
    const w = render(finishedBattle('won'))
    const rows = w.findAll('[data-testid=round-row]')

    expect(rows).toHaveLength(5)
    expect(rows.map((row) => row.attributes('data-round'))).toEqual(['1', '2', '3', '4', '5'])
    const first = rows[0]!
    expect(first.get('[data-testid=round-me]').text()).toContain('100')
    expect(first.get('[data-testid=round-me]').attributes('data-verdict')).toBe('correct')
    expect(first.get('[data-testid=round-me]').find('.sr-only').text()).toBe('Верно')
    expect(first.get('[data-testid=round-opponent]').attributes('data-verdict')).toBe('wrong')
    expect(first.get('[data-testid=round-opponent]').find('.sr-only').text()).toBe('Ошибка')
    expect(first.get('[data-testid=round-opponent]').find('svg').exists()).toBe(true)
    // the other verdicts of the list
    expect(rows[2]!.get('[data-testid=round-me]').find('.sr-only').text()).toBe('Опечатка')
    expect(rows[3]!.get('[data-testid=round-me]').find('.sr-only').text()).toBe('Пропуск')
    expect(rows[3]!.get('[data-testid=round-opponent]').find('.sr-only').text()).toBe('Время вышло')
  })

  it('names the columns: you and the opponent', () => {
    const w = render(finishedBattle())

    const header = w.get('[data-testid=result-rounds]').text()
    expect(header).toContain('Вы')
    expect(header).toContain('Dilnoza')
  })

  it('a battle without rounds has no list', () => {
    const w = render(finishedBattle('won', 'surrender', { result: { outcome: 'won', end_reason: 'surrender', rounds: [] } }))

    expect(w.find('[data-testid=result-rounds]').exists()).toBe(false)
  })

  it('an opponent without a name is “Opponent”', () => {
    const w = render(finishedBattle('won', 'completed', { opponent: { first_name: null } }))

    expect(w.get('[data-testid=score-opponent-name]').text()).toBe('Соперник')
  })
})

describe('BattleResult — what to do next', () => {
  it('after a match: “Rematch” is the one primary button, then “To the friends” and “To the course”', async () => {
    const w = render()

    expect(primaries(w)).toHaveLength(1)
    expect(w.get('[data-testid=rematch-btn]').text()).toBe('Реванш')
    expect(w.get('[data-testid=rematch-btn]').classes()).toContain('btn-primary')
    expect(w.get('[data-testid=friends-btn]').text()).toBe('К друзьям')
    expect(w.get('[data-testid=friends-btn]').classes()).toContain('btn-tinted')
    expect(w.get('[data-testid=course-btn]').text()).toBe('В курс')
    expect(w.get('[data-testid=course-btn]').classes()).toContain('btn-plain')

    await w.get('[data-testid=rematch-btn]').trigger('click')
    await w.get('[data-testid=friends-btn]').trigger('click')
    await w.get('[data-testid=course-btn]').trigger('click')
    expect(w.emitted('rematch')).toHaveLength(1)
    expect(w.emitted('friends')).toHaveLength(1)
    expect(w.emitted('course')).toHaveLength(1)
  })

  it.each(['invite_expired', 'room_expired', 'canceled', 'both_absent', 'voided'] as const)('after a battle that fell through (%s) one may invite again', (reason) => {
    const w = render(closedBattle(reason))

    expect(w.get('[data-testid=rematch-btn]').text()).toBe('Пригласить снова')
    expect(primaries(w)).toHaveLength(1)
  })

  it('after a decline nobody asks the friend again at once: “To the friends” is the primary button', () => {
    const w = render(closedBattle('declined'))

    expect(w.find('[data-testid=rematch-btn]').exists()).toBe(false)
    expect(w.get('[data-testid=friends-btn]').classes()).toContain('btn-primary')
    expect(primaries(w)).toHaveLength(1)
  })

  it('waits for the answer of the server: the buttons are off while an action is on its way', () => {
    const w = render(finishedBattle(), { busy: 'rematch' })

    expect(w.get('[data-testid=rematch-btn]').attributes('disabled')).toBeDefined()
  })

  it('the friend offers a rematch: the offer is answered here, and “Accept” is the one primary button', async () => {
    const offer = invitedBattle({ id: 16, role: 'opponent' })
    const w = render(finishedBattle('lost'), { rematch: offer })

    expect(w.get('[data-testid=rematch-offer]').text()).toContain('Dilnoza предлагает реванш')
    expect(w.find('[data-testid=rematch-btn]').exists()).toBe(false)
    expect(primaries(w)).toHaveLength(1)
    expect(w.get('[data-testid=accept-rematch-btn]').classes()).toContain('btn-primary')
    expect(w.get('[data-testid=decline-rematch-btn]').classes()).toContain('btn-tinted')

    await w.get('[data-testid=accept-rematch-btn]').trigger('click')
    await w.get('[data-testid=decline-rematch-btn]').trigger('click')
    expect(w.emitted('accept-rematch')).toHaveLength(1)
    expect(w.emitted('decline-rematch')).toHaveLength(1)
  })

  it('a rematch of the learner’s own is a way back into it, not a second offer', async () => {
    const own = invitedBattle({ id: 16, role: 'challenger' })
    const w = render(finishedBattle(), { rematch: own })

    expect(w.get('[data-testid=rematch-own]').exists()).toBe(true)
    expect(w.find('[data-testid=rematch-btn]').exists()).toBe(false)
    expect(primaries(w)).toHaveLength(1)
    await w.get('[data-testid=open-rematch-btn]').trigger('click')
    expect(w.emitted('open-rematch')).toHaveLength(1)
  })

  it('an offer that is over (declined, run out) is not shown: “Rematch” is there as usual', () => {
    const w = render(finishedBattle(), { rematch: closedBattle('declined', { id: 16, role: 'opponent' }) })

    expect(w.find('[data-testid=rematch-offer]').exists()).toBe(false)
    expect(w.find('[data-testid=rematch-own]').exists()).toBe(false)
    expect(w.get('[data-testid=rematch-btn]').exists()).toBe(true)
  })

  it('has exactly one primary button in every variant', () => {
    const variants = [
      render(finishedBattle('won')),
      render(finishedBattle('lost')),
      render(finishedBattle('draw')),
      render(closedBattle('canceled')),
      render(closedBattle('declined')),
      render(finishedBattle(), { rematch: invitedBattle({ id: 16, role: 'opponent' }) }),
      render(finishedBattle(), { rematch: invitedBattle({ id: 16, role: 'challenger' }) }),
    ]

    for (const w of variants) expect(primaries(w)).toHaveLength(1)
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = render(finishedBattle('lost'))

    expect(w.get('h1').text()).toBe("Mag'lubiyat")
    expect(w.get('[data-testid=rematch-btn]').text()).toBe('Revansh')
    expect(w.get('[data-testid=friends-btn]').text()).toBe("Do'stlarga")
  })
})
