import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import BattleReveal from '../BattleReveal.vue'
import { SERVER_NOW, at, revealRound, sentenceReveal } from '@/api/__tests__/battleFixtures'
import { collocationElement, dictationElement, pairsElement } from '@/api/__tests__/practiceFixtures'
import { setLocale } from '@/i18n'
import type { BattleRound } from '@/api/types'

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(SERVER_NOW))
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

function render(round: BattleRound = revealRound(), props: Partial<{ last: boolean; offsetMs: number }> = {}) {
  return mount(BattleReveal, { props: { round, opponentName: 'Dilnoza', last: false, offsetMs: 0, totalSeconds: 4, ...props } })
}

type Wrapper = ReturnType<typeof render>
const side = (w: Wrapper, key: 'me' | 'opponent') => w.get(`[data-testid=side-${key}]`)

describe('BattleReveal — a sentence', () => {
  it('shows the right answer big, and a card for each player with the verdict (symbol + word), the answer and the points', () => {
    const w = render()

    expect(w.get('[data-testid=reveal-correct]').text()).toBe('My mother is a teacher.')

    const me = side(w, 'me')
    expect(me.get('[data-testid=side-name]').text()).toBe('Вы')
    expect(me.get('[data-testid=side-verdict]').text()).toBe('Верно')
    expect(me.get('[data-testid=side-verdict]').classes()).toContain('chip-success')
    expect(me.get('[data-testid=side-verdict]').find('svg').exists()).toBe(true)
    expect(me.get('[data-testid=side-answer]').text()).toBe('My mother is a teacher')
    expect(me.get('[data-testid=side-points]').text()).toBe('+100')

    const them = side(w, 'opponent')
    expect(them.get('[data-testid=side-name]').text()).toBe('Dilnoza')
    expect(them.get('[data-testid=side-verdict]').text()).toBe('Ошибка')
    expect(them.get('[data-testid=side-verdict]').classes()).toContain('chip-danger')
    expect(them.get('[data-testid=side-answer]').text()).toBe('mother My is teacher a')
    expect(them.get('[data-testid=side-points]').text()).toBe('+0')
  })

  it('says the points to a screen reader as a number with its noun, and hides the bare “+100” from it', () => {
    const w = render()

    expect(side(w, 'me').get('[data-testid=side-points]').attributes('aria-hidden')).toBe('true')
    expect(side(w, 'me').find('.sr-only').text()).toBe('100 очков')
    expect(side(w, 'opponent').find('.sr-only').text()).toBe('0 очков')
  })

  it('shows the translation and the explanation of the task', () => {
    const w = render()

    expect(w.get('[data-testid=reveal-translation]').text()).toContain('Моя мама — учитель.')
    expect(w.get('[data-testid=reveal-explanation]').text()).toContain('Порядок слов')
  })

  it('has no notes card when the task has neither', () => {
    const w = render(revealRound({ reveal: sentenceReveal({ explanation: null, translation: null }) }))

    expect(w.find('[data-testid=reveal-notes]').exists()).toBe(false)
  })
})

describe('BattleReveal — the verdicts', () => {
  it.each([
    ['correct', 'Верно', 'chip-success'],
    ['typo', 'Опечатка', 'chip-warning'],
    ['wrong', 'Ошибка', 'chip-danger'],
    ['skipped', 'Пропуск', 'chip-accent'],
    ['timeout', 'Время вышло', 'chip-neutral'],
  ] as const)('%s: the word “%s”, a symbol and the colour of the chip', (verdict, word, chip) => {
    const w = render(revealRound({ reveal: sentenceReveal({ me: { answer: null, verdict, points: 0 } }) }))
    const chipEl = side(w, 'me').get('[data-testid=side-verdict]')

    expect(chipEl.text()).toBe(word)
    expect(chipEl.classes()).toContain(chip)
    expect(chipEl.find('svg').exists()).toBe(true)
  })

  it('a player who gave no answer (the time ran out, “I don’t know”) is told so, not shown an empty line', () => {
    const w = render(revealRound({ reveal: sentenceReveal({ opponent: { answer: null, verdict: 'timeout', points: 0 } }) }))

    expect(side(w, 'opponent').get('[data-testid=side-answer]').text()).toBe('Ответа нет')
    expect(side(w, 'opponent').get('[data-testid=side-verdict]').text()).toBe('Время вышло')
  })

  it('the answer of a player who was right is in the normal colour, a wrong one is quieter', () => {
    const w = render()

    expect(side(w, 'me').get('[data-testid=side-answer]').classes()).not.toContain('text-tg-subtitle')
    expect(side(w, 'opponent').get('[data-testid=side-answer]').classes()).toContain('text-tg-subtitle')
  })
})

describe('BattleReveal — the other modes', () => {
  it('collocation: the whole phrase with the right option, and the phrase each player completed', () => {
    const w = render(
      revealRound({
        element: collocationElement(),
        reveal: {
          correct: { correct_option_ids: ['oB2'] },
          me: { answer: { option_id: 'oB2' }, verdict: 'correct', points: 100 },
          opponent: { answer: { option_id: 'oA1' }, verdict: 'wrong', points: 0 },
          explanation: 'С meeting говорят have a meeting.',
          translation: null,
        },
      }),
    )

    expect(w.get('[data-testid=reveal-correct]').text()).toBe("Let's have a meeting on Monday.")
    expect(side(w, 'me').get('[data-testid=side-answer]').text()).toBe("Let's have a meeting on Monday.")
    expect(side(w, 'opponent').get('[data-testid=side-answer]').text()).toBe("Let's make a meeting on Monday.")
  })

  it('dictation: what was dictated, and what each player typed', () => {
    const w = render(
      revealRound({
        element: dictationElement(),
        reveal: {
          correct: { expected: 'See you tomorrow' },
          me: { answer: { text: 'see you tomorow' }, verdict: 'typo', points: 0 },
          opponent: { answer: { text: 'See you tomorrow' }, verdict: 'correct', points: 100 },
          explanation: null,
          translation: 'До завтра',
        },
      }),
    )

    expect(w.get('[data-testid=reveal-correct]').text()).toBe('See you tomorrow')
    expect(side(w, 'me').get('[data-testid=side-answer]').text()).toBe('see you tomorow')
    expect(side(w, 'me').get('[data-testid=side-verdict]').text()).toBe('Опечатка')
    expect(side(w, 'opponent').get('[data-testid=side-answer]').text()).toBe('See you tomorrow')
  })

  it('pairs: the word pairs of the round, and how many pairs each player had right', () => {
    const w = render(
      revealRound({
        element: pairsElement(),
        reveal: {
          correct: { pairs: { lM1: 'rU2', lF2: 'rW4', lS3: 'rT1', lB4: 'rV3' } },
          me: { answer: { pairs: { lM1: 'rU2', lF2: 'rW4', lS3: 'rT1', lB4: 'rV3' } }, verdict: 'correct', points: 100 },
          opponent: { answer: { pairs: { lM1: 'rU2', lF2: 'rW4', lS3: 'rV3', lB4: 'rT1' } }, verdict: 'wrong', points: 50 },
          explanation: null,
          translation: null,
        },
      }),
    )

    expect(w.find('[data-testid=reveal-correct]').exists()).toBe(false)
    const pairs = w.get('[data-testid=reveal-pairs]').findAll('li').map((li) => li.text().replace(/\s*—\s*/, ' '))
    expect(pairs).toEqual(['mother мама', 'father папа', 'sister сестра', 'brother брат'])
    expect(side(w, 'me').get('[data-testid=side-answer]').text()).toBe('Верных пар: 4 из 4')
    expect(side(w, 'opponent').get('[data-testid=side-answer]').text()).toBe('Верных пар: 2 из 4')
    expect(side(w, 'opponent').get('[data-testid=side-points]').text()).toBe('+50')
  })
})

describe('BattleReveal — the pause before the next round', () => {
  it('counts down to the end of the pause by the clock of the server', async () => {
    const w = render(revealRound({ phase_ends_at: at(3) }))
    expect(w.get('[data-testid=reveal-next-text]').text()).toBe('Следующий раунд через 3…')

    await vi.advanceTimersByTimeAsync(1000)
    await nextTick()
    expect(w.get('[data-testid=reveal-next-text]').text()).toBe('Следующий раунд через 2…')
  })

  it('counts to the deadline of the SERVER, whose clock may differ from the device’s', () => {
    const w = render(revealRound({ phase_ends_at: at(3) }), { offsetMs: 1000 })

    expect(w.get('[data-testid=reveal-next-text]').text()).toBe('Следующий раунд через 2…')
  })

  it('on the last round it speaks of the result', async () => {
    const w = render(revealRound({ phase_ends_at: at(3) }), { last: true })
    expect(w.get('[data-testid=reveal-next-text]').text()).toBe('Итоги через 3…')

    await vi.advanceTimersByTimeAsync(3500)
    await nextTick()
    expect(w.get('[data-testid=reveal-next-text]').text()).toBe('Подводим итоги…')
  })

  it('at 0 it does not say “in 0” and does not go negative: it waits for the server', async () => {
    const w = render(revealRound({ phase_ends_at: at(1) }))

    await vi.advanceTimersByTimeAsync(4000)
    await nextTick()

    expect(w.get('[data-testid=reveal-next-text]').text()).toBe('Следующий раунд…')
  })

  it('has no button: the server moves the match on', () => {
    const w = render()

    expect(w.find('button').exists()).toBe(false)
  })

  it('draws nothing for a round that has no reveal yet', () => {
    const w = render(revealRound({ reveal: null }))

    expect(w.find('[data-testid=battle-reveal]').exists()).toBe(false)
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = render(revealRound({ phase_ends_at: at(3) }))

    expect(w.get('[data-testid=reveal-next-text]').text()).toBe('Keyingi raund 3 soniyadan keyin…')
    expect(side(w, 'me').get('[data-testid=side-name]').text()).toBe('Siz')
    expect(side(w, 'me').get('[data-testid=side-verdict]').text()).toBe("To'g'ri")
  })
})
