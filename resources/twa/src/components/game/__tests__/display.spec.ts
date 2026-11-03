import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import EventBanner from '../EventBanner.vue'
import FlaskIcons from '../FlaskIcons.vue'
import GameReveal from '../GameReveal.vue'
import RunHeader from '../RunHeader.vue'
import RunSummary from '../RunSummary.vue'
import AnswerReveal from '@/components/practice/AnswerReveal.vue'
import { setLocale } from '@/i18n'
import { collocation, gap, pairs, spell, summary, translateFwd } from '@/api/__tests__/gameRunFixtures'
import type { GameOutcome } from '@/stores/gameRun'

vi.mock('@/api', () => ({ api: { practice: { audioBlob: vi.fn() }, gameRuns: { audioBlob: vi.fn() } } }))

beforeEach(() => setActivePinia(createPinia()))
afterEach(() => setLocale('ru'))

function outcome(overrides: Partial<GameOutcome> = {}): GameOutcome {
  return {
    element: translateFwd(),
    answer: { option_id: 'o1' },
    verdict: 'correct',
    points: 10,
    reveal: { correct_id: 'o1', correct_text: 'яблоко', word: 'apple', translation: 'яблоко' },
    flaskLost: false,
    combo: 1,
    eventApplied: null,
    pair: null,
    roundDone: false,
    position: 3,
    ...overrides,
  }
}

describe('FlaskIcons', () => {
  it('draws max flasks, the first ones full, and says it in one sentence', () => {
    const wrapper = mount(FlaskIcons, { props: { flasks: 2, max: 3 } })

    expect(wrapper.findAll('[data-testid=flask]').map((flask) => flask.attributes('data-full'))).toEqual(['true', 'true', 'false'])
    expect(wrapper.get('[data-testid=flasks]').attributes('aria-label')).toBe('Фляги: 2 из 3')
  })

  it('draws nothing for a run without flasks (a boss)', () => {
    expect(mount(FlaskIcons, { props: { flasks: 0, max: 0 } }).findAll('[data-testid=flask]')).toHaveLength(0)
  })
})

describe('RunHeader', () => {
  const header = (props: Partial<InstanceType<typeof RunHeader>['$props']> = {}) =>
    mount(RunHeader, { props: { position: 3, total: 10, flasks: 3, flasksMax: 3, score: 40, combo: 0, ...props } })

  it('shows "3 / 10", the score and the flasks', () => {
    const wrapper = header()

    expect(wrapper.get('[data-testid=position]').text()).toBe('3 / 10')
    expect(wrapper.get('[data-testid=score]').text()).toBe('40')
    expect(wrapper.find('[data-testid=flasks]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=progress-bar]').attributes('aria-label')).toBe('Задание 3 из 10')
  })

  it('shows the combo from the third answer in a row, not before', () => {
    expect(header({ combo: 2 }).find('[data-testid=combo]').exists()).toBe(false)
    expect(header({ combo: 3 }).get('[data-testid=combo]').text()).toBe('Комбо ×3')
    expect(header({ combo: 7 }).get('[data-testid=combo]').text()).toBe('Комбо ×7')
  })

  it('has a ✕ that is a 44 px target and says close', async () => {
    const wrapper = header()
    const close = wrapper.get('[data-testid=close-btn]')

    expect(close.classes()).toContain('size-11')
    expect(close.attributes('aria-label')).toBe('Закрыть')
    await close.trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('writes the city and the lesson under the bar', () => {
    expect(header({ caption: 'Самарканд · Модуль 1 · Урок 2' }).get('[data-testid=caption]').text()).toBe('Самарканд · Модуль 1 · Урок 2')
  })
})

describe('EventBanner', () => {
  it.each([
    ['sandstorm', 'Песчаная буря', 'Слово — только на слух.'],
    ['oasis', 'Оазис', 'Потерянная фляга вернётся.'],
    ['bazaar', 'Базар', 'Здесь можно купить подсказку.'],
  ] as const)('%s', (event, title, text) => {
    const wrapper = mount(EventBanner, { props: { event } })

    expect(wrapper.get('[data-testid=event-title]').text()).toBe(title)
    expect(wrapper.get('[data-testid=event-text]').text()).toBe(text)
    expect(wrapper.attributes('role')).toBe('status')
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    expect(mount(EventBanner, { props: { event: 'sandstorm' } }).get('[data-testid=event-title]').text()).toBe("Qum bo'roni")
  })
})

describe('GameReveal — a task made of a word', () => {
  const reveal = (o: GameOutcome, last = false) => mount(GameReveal, { props: { outcome: o, last } })

  it('a right answer: the symbol and the words, the right answer, the word and its translation, the points', () => {
    const wrapper = reveal(outcome({ reveal: { correct_id: 'o1', correct_text: 'яблоко', word: 'apple', translation: 'яблоко ' } }))

    expect(wrapper.get('[data-testid=verdict-label]').text()).toBe('Верно!')
    expect(wrapper.get('[data-testid=verdict-circle]').attributes('data-verdict')).toBe('correct')
    expect(wrapper.get('[data-testid=right-answer]').text()).toBe('яблоко')
    expect(wrapper.get('[data-testid=word]').text()).toBe('apple')
    expect(wrapper.get('[data-testid=chip-points]').text()).toBe('+10')
    expect(wrapper.find('[data-testid=your-answer]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=chip-flask]').exists()).toBe(false)
  })

  it('a miss: "Wrong", the flask lost, what the learner picked', () => {
    const wrapper = reveal(outcome({ verdict: 'wrong', points: 0, flaskLost: true, answer: { option_id: 'o2' } }))

    expect(wrapper.get('[data-testid=verdict-label]').text()).toBe('Неверно')
    expect(wrapper.get('[data-testid=chip-flask]').text()).toBe('Фляга потеряна')
    expect(wrapper.find('[data-testid=chip-points]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=your-answer]').text()).toContain('груша')
  })

  it('a typed miss shows what was typed; a gap shows the whole example', () => {
    const wrapper = reveal(
      outcome({ element: gap(), verdict: 'wrong', points: 0, answer: { text: 'drinks' }, reveal: { correct_text: 'drink', full_text: 'I drink tea every morning.', translation: 'пить' } }),
    )

    expect(wrapper.get('[data-testid=your-answer]').text()).toContain('drinks')
    expect(wrapper.get('[data-testid=full-text]').text()).toBe('I drink tea every morning.')
  })

  it('a hint ("I don\'t know") shows the answer without a reproach', () => {
    const wrapper = reveal(outcome({ element: spell(), verdict: 'skipped', points: 0, answer: { skip: true }, reveal: { correct_text: 'apple', translation: 'яблоко' } }))

    expect(wrapper.get('[data-testid=verdict-label]').text()).toBe('Подсказка')
    expect(wrapper.find('[data-testid=your-answer]').exists()).toBe(false)
  })

  it('the oasis that gave a flask back is told', () => {
    expect(reveal(outcome({ eventApplied: 'oasis', verdict: 'wrong', flaskLost: true })).get('[data-testid=chip-oasis]').text()).toBe('Оазис: фляга вернулась.')
  })

  it('an answer counted earlier: only the verdict and the note', () => {
    const wrapper = reveal(outcome({ earlier: true, verdict: 'wrong', reveal: null, points: 0, answer: { option_id: 'o3' } }))

    expect(wrapper.get('[data-testid=earlier-note]').text()).toBe('Засчитан ответ, отправленный раньше.')
    expect(wrapper.find('[data-testid=right-answer]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=your-answer]').exists()).toBe(false)
  })

  it('"Next" continues; on the last element it says "To the summary"', async () => {
    const wrapper = reveal(outcome())
    expect(wrapper.get('[data-testid=next-btn]').text()).toBe('Дальше')
    await wrapper.get('[data-testid=next-btn]').trigger('click')
    expect(wrapper.emitted('continue')).toHaveLength(1)

    expect(reveal(outcome(), true).get('[data-testid=next-btn]').text()).toBe('К итогу')
  })
})

describe('GameReveal — the kinds of the practice', () => {
  it('a collocation is explained by AnswerReveal of the practice, with the points of the run above it', async () => {
    const wrapper = mount(GameReveal, {
      props: {
        outcome: outcome({
          element: collocation(),
          answer: { option_id: 'c1' },
          reveal: { correct_option_ids: ['c1'], full_text: "Let's have a meeting.", translation: 'Давайте проведём встречу.', explanation: null },
        }),
      },
    })

    expect(wrapper.findComponent(AnswerReveal).exists()).toBe(true)
    expect(wrapper.get('[data-testid=chip-points]').text()).toBe('+10')
    expect(wrapper.find('[data-testid=word-reveal]').exists()).toBe(false)

    await wrapper.get('[data-testid=continue-btn]').trigger('click')
    expect(wrapper.emitted('continue')).toHaveLength(1)
  })

  it('the end of a round of pairs is explained by AnswerReveal as a round', () => {
    const wrapper = mount(GameReveal, {
      props: { outcome: outcome({ element: pairs(), answer: { left_id: 'l2', right_id: 'r1' }, pair: { left_id: 'l2', right_id: 'r1' }, roundDone: true, reveal: null, points: 0 }) },
    })

    expect(wrapper.get('[data-testid=answer-reveal]').attributes('data-verdict')).toBe('round')
  })
})

describe('RunSummary', () => {
  const render = (s = summary(), failed = false) => mount(RunSummary, { props: { summary: s, failed } })

  it('completed: the stars (with words for a reader) and the score', () => {
    const wrapper = render(summary({ stars: 2, score: 320 }))

    expect(wrapper.get('[data-testid=summary-title]').text()).toBe('Забег пройден')
    expect(wrapper.findAll('[data-testid=star]').map((star) => star.attributes('data-full'))).toEqual(['true', 'true', 'false'])
    expect(wrapper.get('[data-testid=stars]').attributes('aria-label')).toBe('Звёзды: 2 из 3')
    expect(wrapper.get('[data-testid=summary-score]').text()).toBe('320')
  })

  it('shows no reward the server did not give: zeros and missing fields leave no row', () => {
    const wrapper = render(summary({ coins: 0, league_points: 0 }))

    expect(wrapper.find('[data-testid=rewards]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('+0')
    expect(wrapper.find('[data-testid=streak-held]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=boss]').exists()).toBe(false)
  })

  it('shows the coins, the league points, the new stars and "the streak held" when they are there', () => {
    const wrapper = render(summary({ coins: 15, league_points: 18, new_stars: 1, streak_held: true }))

    expect(wrapper.get('[data-testid=reward-coins]').text()).toContain('+15')
    expect(wrapper.get('[data-testid=reward-league]').text()).toContain('+18')
    expect(wrapper.get('[data-testid=reward-new_stars]').text()).toContain('+1')
    expect(wrapper.get('[data-testid=streak-held]').text()).toBe('Серия удержана')
  })

  it('shows the boss when the server sends one', () => {
    const wrapper = render(summary({ boss: { accuracy: 91.4, won: true, first: true } }))
    expect(wrapper.get('[data-testid=boss]').text()).toContain('Босс побеждён')
    expect(wrapper.get('[data-testid=boss]').text()).toContain('Точность 91%')
  })

  it('failed: "The flasks are over", the mistakes with the answer and the verdict in words, no rewards', () => {
    const wrapper = render(
      summary({
        coins: 15,
        mistakes: [
          { element_id: 'e1', kind: 'spell', label: 'apple — яблоко', your_answer: 'appel', verdict: 'typo' },
          { element_id: 'e2', kind: 'translate_fwd', label: 'bread — хлеб', your_answer: null, verdict: 'skipped' },
          { element_id: 'e3', kind: 'gap', label: 'drink — пить', your_answer: 'drinks', verdict: 'wrong' },
        ],
      }),
      true,
    )

    expect(wrapper.get('[data-testid=summary-title]').text()).toBe('Фляги кончились')
    expect(wrapper.find('[data-testid=rewards]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid^=mistake-]').filter((node) => /^mistake-\d$/.test(node.attributes('data-testid') ?? ''))).toHaveLength(3)
    expect(wrapper.get('[data-testid=mistake-0] [data-testid=mistake-label]').text()).toBe('apple — яблоко')
    expect(wrapper.get('[data-testid=mistake-0] [data-testid=mistake-yours]').text()).toBe('Вы ответили: appel')
    expect(wrapper.get('[data-testid=mistake-0] [data-testid=mistake-verdict]').text()).toBe('Опечатка')
    expect(wrapper.get('[data-testid=mistake-1] [data-testid=mistake-yours]').text()).toBe('Без ответа')
    expect(wrapper.get('[data-testid=mistake-1] [data-testid=mistake-verdict]').text()).toBe('Не знали')
    expect(wrapper.get('[data-testid=mistake-2] [data-testid=mistake-verdict]').text()).toBe('Неверно')
  })

  it('failed with mistakes: "Repeat in cards" first, then "Again" and "To the map"; each is an event', async () => {
    const wrapper = render(summary({ mistakes: [{ element_id: 'e1', kind: 'spell', label: 'a', your_answer: null, verdict: 'wrong' }] }), true)

    expect(wrapper.findAll('[data-testid=actions] button').map((button) => button.attributes('data-testid'))).toEqual(['review-btn', 'again-btn', 'map-btn'])
    await wrapper.get('[data-testid=review-btn]').trigger('click')
    await wrapper.get('[data-testid=again-btn]').trigger('click')
    await wrapper.get('[data-testid=map-btn]').trigger('click')
    expect(wrapper.emitted('review')).toHaveLength(1)
    expect(wrapper.emitted('again')).toHaveLength(1)
    expect(wrapper.emitted('map')).toHaveLength(1)
  })

  it('failed with words pulled to repeat but no list: still offers the cards', () => {
    expect(render(summary({ srs_pulled: 3 }), true).find('[data-testid=review-btn]').exists()).toBe(true)
  })

  it('completed: "Repeat in cards" is not offered; "Again" is the main button', () => {
    const wrapper = render(summary({ mistakes: [{ element_id: 'e1', kind: 'spell', label: 'a', your_answer: null, verdict: 'wrong' }] }))

    expect(wrapper.find('[data-testid=review-btn]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=again-btn]').classes()).toContain('btn-primary')
  })

  it('failed with nothing to repeat: no cards button', () => {
    expect(render(summary(), true).find('[data-testid=review-btn]').exists()).toBe(false)
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    expect(render(summary(), true).get('[data-testid=summary-title]').text()).toBe('Meshlar tugadi')
  })
})
