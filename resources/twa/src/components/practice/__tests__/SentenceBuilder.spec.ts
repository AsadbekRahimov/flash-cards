import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import SentenceBuilder from '../SentenceBuilder.vue'
import { sentenceElement } from '@/api/__tests__/practiceFixtures'
import { forgetSentenceDrafts, readSentenceDraft, writeSentenceDraft } from '@/composables/sentenceDraft'
import { setLocale } from '@/i18n'
import type { PracticeChoice, SentenceElement } from '@/api/types'

/**
 * "Build the sentence" (docs/15 §3.2, docs/16 §11.3). The fixture: "My mother is a teacher." with the fragments shuffled
 * as teacher (fQ1) / My (fW2) / a (fE3) / is (fR4) / mother (fT5); the right line is fW2 fT5 fR4 fE3 fQ1.
 */
const RIGHT = ['fW2', 'fT5', 'fR4', 'fE3', 'fQ1']
const ALL = ['fQ1', 'fW2', 'fE3', 'fR4', 'fT5']

beforeEach(() => forgetSentenceDrafts())
afterEach(() => setLocale('ru'))

interface Props {
  element?: SentenceElement
  mode?: 'solo' | 'battle'
  disabled?: boolean
}

const mountBuilder = (props: Props = {}, attach = false) =>
  mount(SentenceBuilder, {
    props: { element: sentenceElement(), mode: 'solo', ...props },
    attachTo: attach ? document.body : undefined,
  })

const bankButton = (w: VueWrapper, id: string) => w.get(`[data-testid=fragment-${id}]`)
const lineButton = (w: VueWrapper, id: string) => w.get(`[data-testid=line-${id}]`)
const lineButtons = (w: VueWrapper) => w.findAll('[data-testid=sentence-answer] button')

/** Ids of the fragments in the line, in order. */
const lineIds = (w: VueWrapper): string[] => lineButtons(w).map((button) => (button.attributes('data-testid') ?? '').replace('line-', ''))
const lineTexts = (w: VueWrapper): string[] => lineButtons(w).map((button) => button.text())

/** Ids of the fragments that are still in the bank (their slot is not empty). */
const bankIds = (w: VueWrapper): string[] =>
  w
    .findAll('[data-testid=sentence-bank] button')
    .filter((button) => button.attributes('data-placed') === 'false')
    .map((button) => (button.attributes('data-testid') ?? '').replace('fragment-', ''))

/** Every slot of the bank, empty or not. */
const slotCount = (w: VueWrapper): number => w.findAll('[data-testid=sentence-bank] button').length

async function put(w: VueWrapper, ids: string[]): Promise<void> {
  for (const id of ids) await bankButton(w, id).trigger('click')
}

/** Nothing lost, nothing twice: the line and the bank together are the fragments of the element, each once. */
function expectIntact(w: VueWrapper, ids: string[] = ALL): void {
  const line = lineIds(w)
  expect(new Set(line).size, 'a fragment twice in the line').toBe(line.length)
  expect([...line, ...bankIds(w)].sort(), 'a fragment lost or doubled').toEqual([...ids].sort())
  expect(slotCount(w)).toBe(ids.length)
}

const checkButton = (w: VueWrapper) => w.get('[data-testid=check-btn]')
const skipButton = (w: VueWrapper) => w.get('[data-testid=skip-btn]')
const resetButton = (w: VueWrapper) => w.get('[data-testid=reset-btn]')
const isDisabled = (button: { attributes: (name: string) => string | undefined }): boolean => button.attributes('disabled') !== undefined

describe('SentenceBuilder — the screen', () => {
  it('shows the meaning, an empty line with a hint, and every fragment in the bank in the order the server gave', () => {
    const w = mountBuilder()

    expect(w.get('[data-testid=sentence-prompt]').text()).toBe('Моя мама — учитель.')
    expect(lineIds(w)).toEqual([])
    expect(w.get('[data-testid=sentence-empty]').text()).toBe('Нажимайте на слова ниже по порядку')
    expect(w.get('[data-testid=sentence-progress]').text()).toBe('Собрано 0 из 5')
    expect(w.findAll('[data-testid=sentence-bank] button').map((button) => button.text())).toEqual(['teacher', 'My', 'a', 'is', 'mother'])
    expect(bankIds(w)).toEqual(ALL)
  })

  it('has "Check" off until the line is whole, "Start again" off while the line is empty, and "I don\'t know" on', () => {
    const w = mountBuilder()

    expect(isDisabled(checkButton(w))).toBe(true)
    expect(isDisabled(resetButton(w))).toBe(true)
    expect(isDisabled(skipButton(w))).toBe(false)
    expect(w.findAll('.btn-primary')).toHaveLength(1)
    expect(checkButton(w).text()).toBe('Проверить')
    expect(skipButton(w).text()).toBe('Не знаю')
    expect(resetButton(w).text()).toBe('Начать заново')
  })

  it('keeps the two buttons at the bottom, in the sticky block', () => {
    const w = mountBuilder()

    const actions = w.get('[data-testid=actions]')
    expect(actions.find('[data-testid=check-btn]').exists()).toBe(true)
    expect(actions.find('[data-testid=skip-btn]').exists()).toBe(true)
  })
})

describe('SentenceBuilder — the line and the bank', () => {
  it('puts a tapped fragment at the END of the line, and leaves an empty slot in the bank', async () => {
    const w = mountBuilder()

    await put(w, ['fW2', 'fT5'])
    expect(lineIds(w)).toEqual(['fW2', 'fT5'])
    expect(bankIds(w)).toEqual(['fQ1', 'fE3', 'fR4'])
    expect(w.get('[data-testid=sentence-progress]').text()).toBe('Собрано 2 из 5')
    expect(w.find('[data-testid=sentence-empty]').exists()).toBe(false)

    // the slot of a placed fragment stays and cannot be pressed: nothing moves under the finger
    const slot = bankButton(w, 'fW2')
    expect(slot.attributes('data-placed')).toBe('true')
    expect(slot.classes()).toContain('invisible')
    expect(isDisabled(slot)).toBe(true)
    expect(slotCount(w)).toBe(5)

    await bankButton(w, 'fQ1').trigger('click')
    expect(lineIds(w)).toEqual(['fW2', 'fT5', 'fQ1'])
  })

  it('gives a fragment of the line back to the bank, to its own place, and keeps the rest of the line in order', async () => {
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5', 'fR4'])

    await lineButton(w, 'fT5').trigger('click')

    expect(lineIds(w)).toEqual(['fW2', 'fR4'])
    expect(bankIds(w)).toEqual(['fQ1', 'fE3', 'fT5']) // fT5 is back where it was: after fR4 in the bank's order
    expect(w.findAll('[data-testid=sentence-bank] button').map((button) => button.attributes('data-testid'))).toEqual([
      'fragment-fQ1', 'fragment-fW2', 'fragment-fE3', 'fragment-fR4', 'fragment-fT5',
    ])
    expect(bankButton(w, 'fT5').classes()).not.toContain('invisible')
    expect(isDisabled(bankButton(w, 'fT5'))).toBe(false)
    expectIntact(w)
  })

  it('"Start again" gives everything back at once and then switches itself off', async () => {
    const w = mountBuilder()
    await put(w, RIGHT.slice(0, 4))
    expect(isDisabled(resetButton(w))).toBe(false)

    await resetButton(w).trigger('click')

    expect(lineIds(w)).toEqual([])
    expect(bankIds(w)).toEqual(ALL)
    expect(isDisabled(resetButton(w))).toBe(true)
    expect(w.find('[data-testid=sentence-empty]').exists()).toBe(true)
    expect(w.emitted('answer')).toBeUndefined()
  })

  it('lets the same fragment go in, out and in again', async () => {
    const w = mountBuilder()

    await bankButton(w, 'fW2').trigger('click')
    await lineButton(w, 'fW2').trigger('click')
    await bankButton(w, 'fW2').trigger('click')

    expect(lineIds(w)).toEqual(['fW2'])
    expectIntact(w)
  })

  it('"Check" is on only when every fragment is in the line, and goes off again when one is taken out', async () => {
    const w = mountBuilder()

    await put(w, RIGHT.slice(0, 4))
    expect(isDisabled(checkButton(w))).toBe(true)

    await bankButton(w, 'fQ1').trigger('click')
    expect(isDisabled(checkButton(w))).toBe(false)

    await lineButton(w, 'fR4').trigger('click')
    expect(isDisabled(checkButton(w))).toBe(true)
    expect(w.emitted('answer')).toBeUndefined()
  })
})

describe('SentenceBuilder — no fragment is lost or doubled', () => {
  it('does not put a fragment in twice when it is pressed twice before the screen has caught up', async () => {
    const w = mountBuilder()
    const button = bankButton(w, 'fW2').element as HTMLButtonElement

    button.click()
    button.click() // the second press arrives before the first is drawn: the button is still there
    await nextTick()

    expect(lineIds(w)).toEqual(['fW2'])
    expectIntact(w)
  })

  it('does not take a fragment out twice, nor one that is not in the line', async () => {
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5'])
    const stale = lineButton(w, 'fW2').element as HTMLButtonElement

    stale.click()
    stale.click()
    await nextTick()
    stale.click() // the button is gone from the screen, the press still reaches its handler
    await nextTick()

    expect(lineIds(w)).toEqual(['fT5'])
    expect(bankIds(w)).toEqual(['fQ1', 'fW2', 'fE3', 'fR4'])
    expectIntact(w)
  })

  it('keeps every fragment, once, through 400 random presses - doubled, stale, and from the "Start again" button', async () => {
    const w = mountBuilder()
    let seed = 20261029
    const random = (n: number): number => {
      seed = (seed * 1664525 + 1013904223) % 4294967296
      return Math.floor((seed / 4294967296) * n)
    }
    let stale: HTMLButtonElement[] = []

    for (let step = 0; step < 400; step++) {
      const root = w.element as HTMLElement
      const buttons = [...root.querySelectorAll<HTMLButtonElement>('[data-testid=sentence-bank] button, [data-testid=sentence-answer] button, [data-testid=reset-btn]')]
      const pick = random(10) === 0 && stale.length > 0 ? (stale[random(stale.length)] as HTMLButtonElement) : (buttons[random(buttons.length)] as HTMLButtonElement)
      const presses = 1 + random(3)
      for (let i = 0; i < presses; i++) pick.click()
      if (random(3) === 0) stale = [...stale, pick].slice(-12)
      if (random(4) !== 0) await nextTick() // sometimes the next press comes before the screen is drawn

      await nextTick()
      expectIntact(w)
    }

    await nextTick()
    expectIntact(w)
  })

  it('draws each of two identical words as a fragment of its own: either "the" first, and both are pressed separately', async () => {
    const fragments: PracticeChoice[] = [
      { id: 'fA1', text: 'the' },
      { id: 'fB2', text: 'cat' },
      { id: 'fC3', text: 'sees' },
      { id: 'fD4', text: 'the' },
      { id: 'fE5', text: 'dog' },
    ]
    const w = mountBuilder({ element: sentenceElement({ id: 'eCat0001', fragments, prompt: 'Кошка видит собаку.' }) })

    // the second "the" first, the first "the" in the fourth place
    await put(w, ['fD4', 'fB2', 'fC3', 'fA1', 'fE5'])

    expect(lineIds(w)).toEqual(['fD4', 'fB2', 'fC3', 'fA1', 'fE5'])
    expect(lineTexts(w)).toEqual(['The', 'cat', 'sees', 'the', 'dog'])
    expect(isDisabled(checkButton(w))).toBe(false)
    await checkButton(w).trigger('click')
    expect(w.emitted('answer')).toEqual([[{ order: ['fD4', 'fB2', 'fC3', 'fA1', 'fE5'] }]])
  })

  it('takes one of two identical words out and leaves the other', async () => {
    const fragments: PracticeChoice[] = [
      { id: 'fA1', text: 'the' },
      { id: 'fB2', text: 'cat' },
      { id: 'fD4', text: 'the' },
    ]
    const w = mountBuilder({ element: sentenceElement({ id: 'eCat0002', fragments }) })
    await put(w, ['fA1', 'fB2', 'fD4'])

    await lineButton(w, 'fD4').trigger('click')

    expect(lineIds(w)).toEqual(['fA1', 'fB2'])
    expect(bankIds(w)).toEqual(['fD4'])
    expect(bankButton(w, 'fA1').attributes('data-placed')).toBe('true')
  })
})

describe('SentenceBuilder — sending', () => {
  it('sends the ids of all the fragments in the order they were put, with the capital letter only on the screen', async () => {
    const w = mountBuilder()
    await put(w, ['fT5', 'fW2', 'fR4', 'fE3', 'fQ1'])

    expect(lineTexts(w)).toEqual(['Mother', 'My', 'is', 'a', 'teacher'])
    await checkButton(w).trigger('click')

    expect(w.emitted('answer')).toEqual([[{ order: ['fT5', 'fW2', 'fR4', 'fE3', 'fQ1'] }]])
    expect(w.emitted('skip')).toBeUndefined()
  })

  it('sends once, however many times "Check" is pressed', async () => {
    const w = mountBuilder()
    await put(w, RIGHT)
    const button = checkButton(w).element as HTMLButtonElement

    button.click()
    button.click()
    await nextTick()
    button.click()
    await checkButton(w).trigger('click')

    expect(w.emitted('answer')).toHaveLength(1)
    expect(isDisabled(checkButton(w))).toBe(true)
  })

  it('stops the line while the answer is out, and gives it back when the screen takes the lock off (the answer did not go through)', async () => {
    const w = mountBuilder()
    await put(w, RIGHT)
    await checkButton(w).trigger('click')

    // the screen locks the component while the request is on its way
    await w.setProps({ disabled: true })
    await w.get('[data-testid=line-fW2]').trigger('click')
    expect(lineIds(w)).toEqual(RIGHT)

    // the request failed (no network): the line is as it was and can be sent again - the very same line
    await w.setProps({ disabled: false })
    expect(isDisabled(checkButton(w))).toBe(false)
    await checkButton(w).trigger('click')

    expect(w.emitted('answer')).toEqual([[{ order: RIGHT }], [{ order: RIGHT }]])
  })

  it('sends "I don\'t know" once, and no line with it', async () => {
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5'])
    const button = skipButton(w).element as HTMLButtonElement

    button.click()
    button.click()
    await nextTick()

    expect(w.emitted('skip')).toHaveLength(1)
    expect(w.emitted('answer')).toBeUndefined()
    expect(isDisabled(skipButton(w))).toBe(true)
  })

  it('does nothing at all while `disabled`: no fragment, no reset, no check, no skip', async () => {
    const w = mountBuilder({ disabled: true })

    await bankButton(w, 'fW2').trigger('click')
    ;(bankButton(w, 'fW2').element as HTMLButtonElement).click()
    ;(resetButton(w).element as HTMLButtonElement).click()
    ;(checkButton(w).element as HTMLButtonElement).click()
    ;(skipButton(w).element as HTMLButtonElement).click()

    expect(lineIds(w)).toEqual([])
    expect(w.emitted('answer')).toBeUndefined()
    expect(w.emitted('skip')).toBeUndefined()
    for (const button of w.findAll('button')) expect(isDisabled(button)).toBe(true)
  })

  it('works the same in a battle: the whole line, once - the final answer', async () => {
    const w = mountBuilder({ mode: 'battle' })
    await put(w, RIGHT)

    expect(w.get('[data-testid=sentence-builder]').attributes('data-mode')).toBe('battle')
    const button = checkButton(w).element as HTMLButtonElement
    button.click()
    button.click()
    await nextTick()

    expect(w.emitted('answer')).toEqual([[{ order: RIGHT }]])
  })
})

describe('SentenceBuilder — the capital letter', () => {
  it('draws the first fragment of the line with a capital, and no other, and leaves the bank as it is', async () => {
    const w = mountBuilder()

    await put(w, ['fE3', 'fR4'])

    expect(lineTexts(w)).toEqual(['A', 'is'])
    expect(bankButton(w, 'fE3').text()).toBe('a')
    expect(bankButton(w, 'fQ1').text()).toBe('teacher')
  })

  it('moves the capital to the next fragment when the first one is taken out, and takes it away from the one that leaves', async () => {
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5', 'fR4'])
    expect(lineTexts(w)).toEqual(['My', 'mother', 'is'])

    await lineButton(w, 'fW2').trigger('click')
    expect(lineTexts(w)).toEqual(['Mother', 'is'])
    expect(bankButton(w, 'fW2').text()).toBe('My')

    await lineButton(w, 'fT5').trigger('click')
    expect(lineTexts(w)).toEqual(['Is'])
    expect(bankButton(w, 'fT5').text()).toBe('mother')
  })

  it('capitalizes a letter of any script and a character outside the BMP as a whole, and leaves digits and empty texts alone', async () => {
    const fragments: PracticeChoice[] = [
      { id: 'fA1', text: 'élan' },
      { id: 'fB2', text: '2 apples' },
      { id: 'fC3', text: '\u{1F600} yes' },
      { id: 'fD4', text: 'ўзбек' },
    ]
    const w = mountBuilder({ element: sentenceElement({ id: 'eCap0001', fragments }) })

    const first = async (id: string): Promise<string> => {
      await bankButton(w, id).trigger('click')
      const text = lineTexts(w)[0] ?? ''
      await resetButton(w).trigger('click')
      return text
    }

    expect(await first('fA1')).toBe('Élan')
    expect(await first('fB2')).toBe('2 apples')
    expect(await first('fC3')).toBe('\u{1F600} yes')
    expect(await first('fD4')).toBe('Ўзбек')
  })

  it('does not change what is sent: the ids, never the text', async () => {
    const w = mountBuilder()
    await put(w, RIGHT)
    await checkButton(w).trigger('click')

    const sent = (w.emitted('answer')?.[0]?.[0] ?? {}) as { order?: string[] }
    expect(sent).toEqual({ order: RIGHT })
    expect(JSON.stringify(sent)).not.toContain('My')
  })
})

describe('SentenceBuilder — for a screen reader and a keyboard', () => {
  it('names every button by what it does to which word, and the line as a list', async () => {
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5'])

    expect(bankButton(w, 'fQ1').attributes('aria-label')).toBe('Добавить: teacher')
    expect(lineButton(w, 'fT5').attributes('aria-label')).toBe('Убрать: mother')
    // the first word is read as it is drawn
    expect(lineButton(w, 'fW2').attributes('aria-label')).toBe('Убрать: My')

    const list = w.get('[data-testid=sentence-answer]')
    expect(list.element.tagName).toBe('UL')
    expect(list.attributes('role')).toBe('list') // explicit: a list without bullets is not a list for VoiceOver
    expect(list.attributes('aria-label')).toBe('Ваше предложение')
    expect(list.findAll('li')).toHaveLength(2)
    expect(w.get('[data-testid=sentence-bank]').attributes('role')).toBe('group')
    expect(w.get('[data-testid=sentence-bank]').attributes('aria-label')).toBe('Слова для предложения')
  })

  it('tells the progress in a live region and ties it to "Check", which says why it is off', () => {
    const w = mountBuilder()

    const progress = w.get('[data-testid=sentence-progress]')
    expect(progress.attributes('role')).toBe('status')
    expect(progress.attributes('aria-live')).toBe('polite')
    expect(progress.attributes('id')).toBeTruthy()
    expect(checkButton(w).attributes('aria-describedby')).toBe(progress.attributes('id'))
  })

  it('makes every control a real button of at least 48 px, and the fragments at least 48 px wide as well', () => {
    const w = mountBuilder()

    const buttons = w.findAll('button')
    expect(buttons.length).toBe(5 + 3) // five fragments, "Start again", "Check", "I don't know" - the line is empty
    for (const button of buttons) {
      expect(button.attributes('type')).toBe('button')
      expect(button.classes(), button.text()).toContain('btn') // .btn is min-h-12 = 48 px (style.css)
    }
    for (const id of ALL) expect(bankButton(w, id).classes()).toEqual(expect.arrayContaining(['min-w-12', 'max-w-full']))
  })

  it('wraps the line onto new rows, lets a long fragment wrap, and has nothing that scrolls sideways', async () => {
    const long = 'internationalisation-and-its-consequences'
    const fragments: PracticeChoice[] = [
      ...sentenceElement().fragments,
      { id: 'fL6', text: long },
      { id: 'fL7', text: 'one more word' },
      { id: 'fL8', text: 'and another' },
    ]
    const w = mountBuilder({ element: sentenceElement({ id: 'eEight001', fragments }) })
    await put(w, fragments.map((fragment) => fragment.id))

    expect(lineIds(w)).toHaveLength(8)
    expect(w.get('[data-testid=sentence-answer]').classes()).toContain('flex-wrap')
    expect(w.get('[data-testid=sentence-bank]').classes()).toContain('flex-wrap')
    for (const li of w.findAll('[data-testid=sentence-answer] li')) expect(li.classes()).toEqual(expect.arrayContaining(['max-w-full', 'min-w-0']))
    for (const button of w.findAll('[data-testid=sentence-answer] button, [data-testid=sentence-bank] button')) {
      expect(button.classes()).toEqual(expect.arrayContaining(['max-w-full', 'wrap-anywhere']))
    }
    expect(w.html()).not.toMatch(/overflow-x-(auto|scroll)|whitespace-nowrap|flex-nowrap|w-max\b/)
    expect(isDisabled(checkButton(w))).toBe(false)
  })

  it('moves the focus along with a pressed fragment: to the next free slot, and to "Check" when none is left', async () => {
    const w = mountBuilder({}, true)
    const focusOn = async (id: string) => {
      ;(bankButton(w, id).element as HTMLButtonElement).focus()
      await bankButton(w, id).trigger('click')
      await flushPromises()
    }

    await focusOn('fW2')
    expect(document.activeElement).toBe(bankButton(w, 'fE3').element) // the slot after it

    await focusOn('fE3')
    await focusOn('fR4')
    await focusOn('fT5')
    expect(document.activeElement).toBe(bankButton(w, 'fQ1').element) // only the one before is left

    await focusOn('fQ1')
    expect(document.activeElement).toBe(checkButton(w).element) // the bank is empty and the line is whole
    w.unmount()
  })

  it('gives the focus to the slot a fragment returns to, and to the first slot after "Start again"', async () => {
    const w = mountBuilder({}, true)
    await put(w, ['fW2', 'fT5', 'fR4'])

    ;(lineButton(w, 'fT5').element as HTMLButtonElement).focus()
    await lineButton(w, 'fT5').trigger('click')
    await flushPromises()
    expect(document.activeElement).toBe(bankButton(w, 'fT5').element)

    ;(resetButton(w).element as HTMLButtonElement).focus()
    await resetButton(w).trigger('click')
    await flushPromises()
    expect(document.activeElement).toBe(bankButton(w, 'fQ1').element)
    w.unmount()
  })

  it('leaves the focus alone when nothing of ours had it (a touch)', async () => {
    const w = mountBuilder({}, true)
    ;(document.activeElement as HTMLElement | null)?.blur()

    await bankButton(w, 'fW2').trigger('click')
    await flushPromises()

    expect(w.element.contains(document.activeElement)).toBe(false)
    w.unmount()
  })
})

describe('SentenceBuilder — the line is kept when the learner leaves and comes back', () => {
  it('brings the half-built line back to the same element', async () => {
    const first = mountBuilder()
    await put(first, ['fT5', 'fW2'])
    first.unmount()

    const back = mountBuilder()

    expect(lineIds(back)).toEqual(['fT5', 'fW2'])
    expect(bankIds(back)).toEqual(['fQ1', 'fE3', 'fR4'])
    expect(back.get('[data-testid=sentence-progress]').text()).toBe('Собрано 2 из 5')
    expectIntact(back)
  })

  it('brings back a whole line too, ready to be checked', async () => {
    const first = mountBuilder()
    await put(first, RIGHT)
    first.unmount()

    const back = mountBuilder()

    expect(lineIds(back)).toEqual(RIGHT)
    expect(isDisabled(checkButton(back))).toBe(false)
  })

  it('starts another element from an empty line, and keeps the line of the first one for when it is back', async () => {
    const first = mountBuilder()
    await put(first, ['fW2'])
    first.unmount()

    const other = mountBuilder({ element: sentenceElement({ id: 'eSent00002' }) })
    expect(lineIds(other)).toEqual([])
    other.unmount()

    expect(lineLength(mountBuilder())).toBe(1)
  })

  it('forgets the line once it has been sent and the element is over', async () => {
    const first = mountBuilder()
    await put(first, RIGHT)
    await checkButton(first).trigger('click')
    first.unmount()

    expect(lineIds(mountBuilder())).toEqual([])
  })

  it('keeps the line of an answer that did not go through, if the learner leaves instead of trying again', async () => {
    const first = mountBuilder()
    await put(first, RIGHT)
    await checkButton(first).trigger('click')
    await first.setProps({ disabled: true })
    await first.setProps({ disabled: false }) // the request failed
    first.unmount()

    expect(lineIds(mountBuilder())).toEqual(RIGHT)
  })

  it('takes from a saved line only what still fits the element: no stranger, no repeat', () => {
    writeSentenceDraft('eSent00001', ['fW2', 'fNOPE', 'fW2', 'fT5'])

    const w = mountBuilder()

    expect(lineIds(w)).toEqual(['fW2', 'fT5'])
    expectIntact(w)
  })

  it('shows the line of the element that is on screen when the screen puts another in its place, and the old line when it is back', async () => {
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5'])

    await w.setProps({ element: sentenceElement({ id: 'eSent00002', fragments: [{ id: 'fX1', text: 'Hello' }, { id: 'fX2', text: 'there' }] }) })
    expect(lineIds(w)).toEqual([])
    expect(bankIds(w)).toEqual(['fX1', 'fX2'])

    await w.setProps({ element: sentenceElement() })
    expect(lineIds(w)).toEqual(['fW2', 'fT5'])
  })

  it('keeps the line when the same element is read again (a new object, the same id and fragments)', async () => {
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5'])

    await w.setProps({ element: sentenceElement() })

    expect(lineIds(w)).toEqual(['fW2', 'fT5'])
  })
})

describe('sentenceDraft', () => {
  it('keeps the newest twenty lines', () => {
    for (let i = 0; i < 25; i++) writeSentenceDraft(`e${i}`, ['f1'])

    expect(readSentenceDraft('e0', ['f1'])).toEqual([])
    expect(readSentenceDraft('e4', ['f1'])).toEqual([])
    expect(readSentenceDraft('e5', ['f1'])).toEqual(['f1'])
    expect(readSentenceDraft('e24', ['f1'])).toEqual(['f1'])
  })

  it('counts a line that is written again as the newest, and takes an empty line for no line', () => {
    for (let i = 0; i < 20; i++) writeSentenceDraft(`e${i}`, ['f1'])
    writeSentenceDraft('e0', ['f1', 'f2']) // e0 is the newest now
    writeSentenceDraft('e20', ['f1']) // the oldest (e1) goes

    expect(readSentenceDraft('e0', ['f1', 'f2'])).toEqual(['f1', 'f2'])
    expect(readSentenceDraft('e1', ['f1'])).toEqual([])

    writeSentenceDraft('e0', [])
    expect(readSentenceDraft('e0', ['f1', 'f2'])).toEqual([])
  })
})

describe('SentenceBuilder — Uzbek', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = mountBuilder()
    await put(w, ['fW2', 'fT5'])

    expect(w.get('[data-testid=sentence-progress]').text()).toBe("5 tadan 2 tasi yig'ildi")
    expect(resetButton(w).text()).toBe('Qaytadan boshlash')
    expect(checkButton(w).text()).toBe('Tekshirish')
    expect(skipButton(w).text()).toBe('Bilmayman')
    expect(bankButton(w, 'fQ1').attributes('aria-label')).toBe("Qo'shish: teacher")
    expect(lineButton(w, 'fT5').attributes('aria-label')).toBe('Olib tashlash: mother')
    expect(w.get('[data-testid=sentence-answer]').attributes('aria-label')).toBe('Sizning gapingiz')
    expect(w.get('[data-testid=sentence-bank]').attributes('aria-label')).toBe("Gap uchun so'zlar")

    await resetButton(w).trigger('click')
    expect(w.get('[data-testid=sentence-empty]').text()).toBe("Quyidagi so'zlarni tartib bilan bosing")
  })
})

function lineLength(w: VueWrapper): number {
  return lineIds(w).length
}
