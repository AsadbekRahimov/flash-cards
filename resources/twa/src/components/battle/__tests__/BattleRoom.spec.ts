import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import BattleRoom from '../BattleRoom.vue'
import { SERVER_NOW, at, invitedBattle, roomBattle } from '@/api/__tests__/battleFixtures'
import { setLocale } from '@/i18n'
import type { BattleAction } from '@/stores/battle'
import type { AudioState } from '@/stores/practice'
import type { Battle } from '@/api/types'

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(SERVER_NOW))
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

interface Props {
  ready: { me: boolean; opponent: boolean }
  opponentPresent: boolean
  expiresAt: string | null
  offsetMs: number
  busy: BattleAction | null
  audio: { state: AudioState; loaded: number; total: number } | null
  busyBattleId: number | null
}

function render(battle: Battle, props: Partial<Props> = {}) {
  return mount(BattleRoom, {
    props: {
      battle,
      ready: battle.ready,
      opponentPresent: true,
      expiresAt: battle.status === 'invited' ? battle.invite_expires_at : battle.room_expires_at,
      offsetMs: 0,
      busy: null,
      audio: null,
      busyBattleId: null,
      ...props,
    },
  })
}

type Wrapper = ReturnType<typeof render>
const primaries = (w: Wrapper) => w.findAll('button.btn-primary')
const chips = (w: Wrapper) => w.findAll('[data-testid=player-chip]').map((chip) => chip.text())

describe('BattleRoom — the invitation that was sent', () => {
  const sent = () => invitedBattle({ role: 'challenger' })

  it('says whom it waits for and how long the invitation lasts', () => {
    const w = render(sent())

    expect(w.get('[data-testid=battle-room]').attributes('data-phase')).toBe('invite_sent')
    expect(w.get('h1').text()).toBe('Ждём ответа: Dilnoza')
    expect(w.get('[data-testid=room-expires]').text()).toBe('Приглашение действует ещё 4:00')
    expect(chips(w)).toEqual(['ещё не ответил'])
  })

  it('counts the time down by the clock of the server', async () => {
    const w = render(sent(), { offsetMs: 10_000 })
    expect(w.get('[data-testid=room-expires]').text()).toBe('Приглашение действует ещё 3:50')

    await vi.advanceTimersByTimeAsync(5000)
    await nextTick()
    expect(w.get('[data-testid=room-expires]').text()).toBe('Приглашение действует ещё 3:45')
  })

  it('says nothing about the time once it is out: the server moves the battle on', async () => {
    const w = render(sent(), { expiresAt: at(1) })

    await vi.advanceTimersByTimeAsync(2000)
    await nextTick()

    expect(w.find('[data-testid=room-expires]').exists()).toBe(false)
  })

  it('does not know the name of the friend: it does not say “Waiting for Friend…”', () => {
    const w = render(invitedBattle({ role: 'challenger', opponent: { first_name: null } }))

    expect(w.get('h1').text()).toBe('Ждём ответа друга…')
  })

  it('has one button, “Cancel”, and no primary one', async () => {
    const w = render(sent())

    expect(primaries(w)).toHaveLength(0)
    expect(w.get('[data-testid=cancel-btn]').text()).toBe('Отменить')
    expect(w.get('[data-testid=cancel-btn]').classes()).toContain('btn-tinted')
    expect(w.find('[data-testid=accept-btn]').exists()).toBe(false)
    await w.get('[data-testid=cancel-btn]').trigger('click')
    expect(w.emitted('cancel')).toHaveLength(1)
  })

  it('waits for an action that is on its way', () => {
    const w = render(sent(), { busy: 'cancel' })

    expect(w.get('[data-testid=cancel-btn]').attributes('disabled')).toBeDefined()
  })
})

describe('BattleRoom — the invitation that came', () => {
  const received = () => invitedBattle({ role: 'opponent' })

  it('says who calls, to what, and shows the format, the topic and the rules', () => {
    const w = render(received())

    expect(w.get('[data-testid=battle-room]').attributes('data-phase')).toBe('invite_received')
    expect(w.get('h1').text()).toBe('Dilnoza зовёт вас на баттл')
    expect(w.get('[data-testid=room-mode]').text()).toBe('Собери предложение')
    expect(w.get('[data-testid=room-topic]').text()).toBe('Модуль 2 · Урок 4 · Еда')
    expect(w.get('[data-testid=room-brief]').text()).toContain('Английский A1')
    expect(w.findAll('[data-testid=rule-row]').map((row) => row.text())).toEqual([
      'Раундов: 5. Задания у обоих одинаковые.',
      'На каждый раунд — 45 с. Время считает сервер.',
      'За раунд — до 100 очков. Скорость очков не добавляет.',
      'Побеждает тот, у кого больше очков. При равном счёте — ничья.',
      'Ответ в раунде один и окончательный.',
      'Выход из матча — это сдача: победа достанется сопернику. Надолго пропасть тоже значит проиграть.',
    ])
  })

  it('has “Accept” as the one primary button and “Decline” beside it', async () => {
    const w = render(received())

    expect(primaries(w)).toHaveLength(1)
    expect(w.get('[data-testid=accept-btn]').classes()).toContain('btn-primary')
    expect(w.get('[data-testid=accept-btn]').text()).toBe('Принять')
    expect(w.get('[data-testid=decline-btn]').classes()).toContain('btn-tinted')
    expect(w.find('[data-testid=cancel-btn]').exists()).toBe(false)

    await w.get('[data-testid=accept-btn]').trigger('click')
    await w.get('[data-testid=decline-btn]').trigger('click')
    expect(w.emitted('accept')).toHaveLength(1)
    expect(w.emitted('decline')).toHaveLength(1)
  })

  it('does not put a chip next to the person who calls: they are not “not ready”', () => {
    const w = render(received())

    expect(w.find('[data-testid=player-chip]').exists()).toBe(false)
    expect(w.get('[data-testid=player-name]').text()).toBe('Dilnoza')
  })

  it('both buttons wait for an action that is on its way', () => {
    const w = render(received(), { busy: 'accept' })

    expect(w.get('[data-testid=accept-btn]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=decline-btn]').attributes('disabled')).toBeDefined()
  })

  it('shows the battle of the learner’s own that is in the way, with a way to it', async () => {
    const w = render(received(), { busyBattleId: 9 })

    expect(w.get('[data-testid=room-busy]').text()).toContain('У вас уже есть активный баттл')
    await w.get('[data-testid=open-busy]').trigger('click')
    expect(w.emitted('open-busy')).toHaveLength(1)
    expect(render(received()).find('[data-testid=room-busy]').exists()).toBe(false)
  })

  it('puts that refusal above the rules: the button that caused it is at the bottom, the reason must be in sight', () => {
    const w = render(received(), { busyBattleId: 9 })

    expect(w.get('[data-testid=room-busy]').element.compareDocumentPosition(w.get('[data-testid=room-rules]').element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })
})

describe('BattleRoom — the room', () => {
  const room = (overrides: Partial<Battle> = {}) => roomBattle({ ready: { me: false, opponent: false }, ...overrides })

  it('shows both players with their “ready” marks, and how long the room stays open', () => {
    const w = render(room({ ready: { me: false, opponent: true } }))

    expect(w.get('[data-testid=battle-room]').attributes('data-phase')).toBe('room')
    expect(w.get('h1').text()).toBe('Комната готова')
    expect(w.findAll('[data-testid=player-name]').map((name) => name.text())).toEqual(['Вы', 'Dilnoza'])
    expect(chips(w)).toEqual(['не готов', 'готов'])
    expect(w.get('[data-testid=room-expires]').text()).toBe('Комната закроется через 1:45')
  })

  it('has “Ready” as the one primary button, and “Leave”', async () => {
    const w = render(room())

    expect(primaries(w)).toHaveLength(1)
    expect(w.get('[data-testid=ready-btn]').text()).toBe('Готов')
    expect(w.get('[data-testid=ready-btn]').attributes('disabled')).toBeUndefined()
    expect(w.get('[data-testid=leave-btn]').text()).toBe('Выйти')
    expect(w.get('[data-testid=leave-btn]').classes()).toContain('btn-tinted')

    await w.get('[data-testid=ready-btn]').trigger('click')
    await w.get('[data-testid=leave-btn]').trigger('click')
    expect(w.emitted('ready')).toHaveLength(1)
    expect(w.emitted('leave')).toHaveLength(1)
  })

  it('after “Ready”: “Ready - waiting for the opponent”, with a check mark, and the button is off', () => {
    const w = render(room({ ready: { me: true, opponent: false } }))

    const button = w.get('[data-testid=ready-btn]')
    expect(button.text()).toBe('Готов — ждём соперника')
    expect(button.find('svg').exists()).toBe(true)
    expect(button.attributes('disabled')).toBeDefined()
    expect(chips(w)).toEqual(['готов', 'не готов'])
  })

  it('the freshest flags come in from outside (the poll), not from the battle', () => {
    const w = render(room(), { ready: { me: true, opponent: true } })

    expect(chips(w)).toEqual(['готов', 'готов'])
  })

  it('an opponent who is gone is told by a word and a symbol', () => {
    const w = render(room(), { opponentPresent: false })

    expect(chips(w)).toEqual(['не готов', 'нет связи'])
  })

  it('“Ready” waits for an action that is on its way', () => {
    const w = render(room(), { busy: 'ready' })

    expect(w.get('[data-testid=ready-btn]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=leave-btn]').attributes('disabled')).toBeDefined()
  })

  it('the rules of the pairs say the points of a pair; the others say the points of a round', () => {
    const pairs = render(room({ mode: 'pairs' }))
    const dictation = render(room({ mode: 'dictation', round_seconds: 60 }))

    expect(pairs.findAll('[data-testid=rule-row]').map((row) => row.text())).toContain('За каждую верную пару — 25 очков, за раунд до 100. Скорость очков не добавляет.')
    const rules = dictation.findAll('[data-testid=rule-row]').map((row) => row.text())
    expect(rules).toContain('На каждый раунд — 60 с. Время считает сервер.')
    expect(rules).toContain('За раунд — до 100 очков. Скорость очков не добавляет.')
    expect(rules[rules.length - 1]).toBe('Если звук не загрузится во время матча, раунд даст 0 очков. Если записи нет совсем, матч аннулируется.')
  })

  it('a whole course is the topic when there is no lesson', () => {
    const w = render(room({ lesson: null }))

    expect(w.get('[data-testid=room-topic]').text()).toBe('Английский A1')
  })
})

describe('BattleRoom — a dictation loads its recordings before “Ready”', () => {
  const dictation = () => roomBattle({ mode: 'dictation', round_seconds: 60, ready: { me: false, opponent: false } })

  it('shows how many of the five are in, and “Ready” waits', () => {
    const w = render(dictation(), { audio: { state: 'loading', loaded: 3, total: 5 } })

    expect(w.get('[data-testid=audio-progress]').text()).toBe('Загружаем звук: 3 из 5')
    expect(w.get('[data-testid=ready-btn]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=room-audio]').attributes('data-state')).toBe('loading')
  })

  it('when all are in it says so and “Ready” opens', () => {
    const w = render(dictation(), { audio: { state: 'ready', loaded: 5, total: 5 } })

    expect(w.get('[data-testid=audio-progress]').text()).toBe('Звук загружен')
    expect(w.get('[data-testid=ready-btn]').attributes('disabled')).toBeUndefined()
  })

  it('a recording that did not come: a plain sentence, “Retry”, and “Ready” stays off', async () => {
    const w = render(dictation(), { audio: { state: 'failed', loaded: 4, total: 5 } })

    expect(w.get('[data-testid=audio-failed]').text()).toContain('Не удалось загрузить звук')
    expect(w.get('[data-testid=ready-btn]').attributes('disabled')).toBeDefined()
    await w.get('[data-testid=audio-retry]').trigger('click')
    expect(w.emitted('retry-audio')).toHaveLength(1)
  })

  it('what stops “Ready” is above the rules: on a phone the rules fill the screen, the reason must not be under them', () => {
    const w = render(dictation(), { audio: { state: 'failed', loaded: 4, total: 5 } })

    const rules = w.get('[data-testid=room-rules]').element
    expect(w.get('[data-testid=room-audio]').element.compareDocumentPosition(rules) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(w.get('[data-testid=room-brief]').element.compareDocumentPosition(w.get('[data-testid=room-audio]').element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('the other formats have no sound to wait for', () => {
    const w = render(roomBattle({ mode: 'pairs', ready: { me: false, opponent: false } }))

    expect(w.find('[data-testid=room-audio]').exists()).toBe(false)
    expect(w.get('[data-testid=ready-btn]').attributes('disabled')).toBeUndefined()
  })

  it('sound is only for the room: the invitation does not load anything', () => {
    const w = render(invitedBattle({ role: 'opponent', mode: 'dictation' }), { audio: { state: 'loading', loaded: 0, total: 5 } })

    expect(w.find('[data-testid=room-audio]').exists()).toBe(false)
  })
})

describe('BattleRoom — Uzbek', () => {
  it('speaks Uzbek in the room and in the invitation', () => {
    setLocale('uz')
    const room = render(roomBattle({ ready: { me: false, opponent: false } }))
    const sent = render(invitedBattle({ role: 'challenger' }))

    expect(room.get('h1').text()).toBe('Xona tayyor')
    expect(room.get('[data-testid=ready-btn]').text()).toBe('Tayyorman')
    expect(room.get('[data-testid=leave-btn]').text()).toBe('Chiqish')
    expect(sent.get('h1').text()).toBe('Dilnoza kutilmoqda…')
    expect(sent.get('[data-testid=cancel-btn]').text()).toBe('Bekor qilish')
  })
})
