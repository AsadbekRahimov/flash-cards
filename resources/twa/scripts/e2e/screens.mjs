// What a learner does on the screens of the four modes, shared by the solo scenarios, the battle and the pictures. `element` is the
// element of the snapshot (the oracle): it knows the right answer; the screen is asked only for what it shows.
import { E2EError, sel, until } from './kit.mjs'

export const MODES = ['pairs', 'collocation', 'sentence', 'dictation']
export const COMPONENT = { pairs: 'pairs-board', collocation: 'collocation-choice', sentence: 'sentence-builder', dictation: 'dictation-input' }
export const MODE_TITLE = { pairs: 'Найди пары', collocation: 'Подбери сочетание', sentence: 'Собери предложение', dictation: 'Слушай и пиши' }
/** The words of the verdict on the review screen of a solo answer (`practice.verdict.*`). */
export const VERDICT_WORDS = { correct: 'Верно!', typo: 'Есть опечатка', skipped: 'Подсказка' }
export const CHIP_WORDS = { typo: 'Опечатка', wrong: 'Ошибка', skipped: 'Подсказка' }

/** The normal form the server compares texts in (docs/16 §5.1): case, apostrophes, hyphens and the punctuation that is not a letter. */
export function normalize(text) {
  return text
    .normalize('NFKC')
    .toLowerCase()
    .replace(/[‘’ʼ`´]/g, "'")
    .replace(/[‐-―-]/g, ' ')
    .replace(/[.,!?;:"()[\]]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

// ------------------------------------------------------------------- what is on the board

/** The two columns of a board of pairs as the page draws them: ids from the test ids, the words from the cards. */
export async function boardCards(p) {
  return p.page.evaluate(() => {
    const read = (side) =>
      [...document.querySelectorAll(`button[data-card="${side}"]`)].map((button) => ({
        id: button.dataset.testid.slice(side.length + 1),
        text: button.querySelector('[data-testid="card-text"]')?.textContent?.trim() ?? '',
        state: button.dataset.state,
      }))
    return { left: read('left'), right: read('right') }
  })
}

/** The options of a collocation as the page draws them. */
export async function optionCards(p) {
  return p.page.evaluate(() =>
    [...document.querySelectorAll('[data-testid="collocation-choice"] button[data-testid^="option-"]')].map((button) => ({
      index: Number(button.dataset.testid.slice('option-'.length)),
      text: button.querySelector('span[lang="en"]')?.textContent?.trim() ?? '',
    })),
  )
}

/** The fragments of the bank of a sentence, in the order the page draws them. */
export async function bankFragments(p) {
  return p.page.evaluate(() =>
    [...document.querySelectorAll('[data-testid="sentence-bank"] button[data-testid^="fragment-"]')].map((button) => ({
      id: button.dataset.testid.slice('fragment-'.length),
      text: button.textContent.trim(),
    })),
  )
}

const same = (a, b) => a.length === b.length && [...a].sort().join('|') === [...b].sort().join('|')

/**
 * Is the screen showing the element the server waits for? null when it does, else a sentence about the difference.
 * (The only thing the server calls "the current element" is its state; a screen that shows another one is a bug of the client.)
 */
export async function whyNotShown(p, element) {
  if (element.kind === 'pairs_round') {
    const board = await boardCards(p)
    return same(board.left.map((card) => card.id), element.left.map((card) => card.id)) && same(board.right.map((card) => card.id), element.right.map((card) => card.id))
      ? null
      : `the board shows other cards than round ${element.round}`
  }
  if (element.kind === 'collocation') {
    const shown = (await optionCards(p)).map((option) => option.text)
    return same(shown, element.options.map((option) => option.text)) ? null : `the options are [${shown.join(', ')}], the server waits for [${element.options.map((option) => option.text).join(', ')}]`
  }
  if (element.kind === 'sentence') {
    const shown = (await bankFragments(p)).map((fragment) => fragment.id)
    return same(shown, element.fragments.map((fragment) => fragment.id)) ? null : 'the fragments on the screen are not those of the current sentence'
  }
  if (element.kind === 'dictation') {
    return (await p.has('dictation-input')) ? null : 'no dictation on the screen'
  }
  return `an element of an unknown kind ${element.kind}`
}

// -------------------------------------------------------------------- what to answer

/** Ids of the fragments in the order of the right sentence (the first accepted one), or null when they cannot be put together. */
export function rightOrder(element) {
  const words = element.fragments.map((fragment) => ({ id: fragment.id, text: normalize(fragment.text) }))
  const target = element.accepted[0]
  const used = new Set()
  const order = []
  const place = (rest) => {
    if (used.size === words.length) return rest === ''
    for (const word of words) {
      if (used.has(word.id)) continue
      const whole = word.text === rest
      if (whole || rest.startsWith(`${word.text} `) || word.text === '') {
        used.add(word.id)
        order.push(word.id)
        if (place(whole ? '' : word.text === '' ? rest : rest.slice(word.text.length + 1))) return true
        used.delete(word.id)
        order.pop()
      }
    }
    return false
  }
  return place(target) ? [...order] : null
}

/** A wrong order: the right one reversed (or its first two swapped when the reverse reads the same). */
export function wrongOrder(element) {
  const right = rightOrder(element)
  if (right === null) throw new E2EError('the fragments of a sentence do not make its accepted text')
  const reversed = [...right].reverse()
  const text = (order) => normalize(order.map((id) => element.fragments.find((fragment) => fragment.id === id).text).join(' '))
  if (element.accepted.includes(text(reversed))) {
    const swapped = [...right]
    ;[swapped[0], swapped[1]] = [swapped[1], swapped[0]]
    return swapped
  }
  return reversed
}

/**
 * A phrase with one slip: two letters in the middle of its longest word swapped (an edit of one, and no word of English).
 * A slip counts as a typo only in a word of 5 letters or more (`practice.grading.typo_min_length`); null when the phrase has none.
 */
export function typoOf(phrase) {
  const tokens = phrase.split(' ')
  let at = -1
  tokens.forEach((token, index) => {
    if (token.length >= 5 && (at < 0 || token.length > tokens[at].length)) at = index
  })
  if (at < 0) return null
  const letters = [...tokens[at]]
  for (let i = 1; i < letters.length - 2; i++) {
    if (letters[i] !== letters[i + 1]) {
      ;[letters[i], letters[i + 1]] = [letters[i + 1], letters[i]]
      break
    }
  }
  tokens[at] = letters.join('')
  return tokens.join(' ')
}

// ----------------------------------------------------------- doing it on the screen

/** Taps an option of a collocation: the right one or a wrong one. Returns the text of the option. */
export async function doCollocation(p, element, intent) {
  const right = new Set(element.options.filter((option) => element.correct_ids.includes(option.id)).map((option) => option.text))
  const options = await optionCards(p)
  const pick = options.find((option) => (intent === 'right' ? right.has(option.text) : !right.has(option.text)))
  if (!pick) throw new E2EError(`no ${intent} option among [${options.map((option) => option.text).join(', ')}]`)
  await p.click(`option-${pick.index}`)
  return pick.text
}

/** Puts the fragments in a line and checks it; `skip` presses "I don't know". Returns the line as text. */
export async function doSentence(p, element, intent) {
  if (intent === 'skip') {
    await p.click('skip-btn')
    return null
  }
  const order = intent === 'right' ? rightOrder(element) : wrongOrder(element)
  if (order === null) throw new E2EError('the fragments of a sentence do not make its accepted text')
  for (const id of order) await p.click(`fragment-${id}`)
  await until(async () => !(await p.$('check-btn').isDisabled()), { label: 'the button "Проверить" of the sentence' })
  await p.click('check-btn')
  return order.map((id) => element.fragments.find((fragment) => fragment.id === id).text).join(' ')
}

/** Types what was heard (right, with a slip, wrong) and checks it; `skip` presses "I don't know". Returns the text. */
export async function doDictation(p, element, intent) {
  if (intent === 'skip') {
    await p.click('skip-btn')
    return null
  }
  const expected = element.expected[0]
  const text = intent === 'right' ? expected : intent === 'typo' ? typoOf(expected) : 'zzzz qqqq'
  if (text === null) throw new E2EError(`no word long enough for a slip in "${expected}"`)
  await p.page.fill(sel('dictation-text'), text)
  await until(async () => !(await p.$('check-btn').isDisabled()), { label: 'the button "Проверить" of the dictation' })
  await p.click('check-btn')
  return text
}

/**
 * A battle board of pairs: the pairs are put together (the last one the board makes itself) and sent with "Check".
 * `wrong` swaps the partners of the first two words, so two of four pairs are wrong.
 */
export async function doPairsBattle(p, element, intent) {
  const board = await boardCards(p)
  const lefts = board.left.map((card) => card.id)
  const wanted = { ...element.pairs }
  if (intent === 'wrong') {
    const [x, y] = lefts
    ;[wanted[x], wanted[y]] = [wanted[y], wanted[x]]
  }
  for (const left of lefts.slice(0, -1)) {
    await p.click(`left-${left}`)
    await p.click(`right-${wanted[left]}`)
  }
  await until(async () => !(await p.$('pairs-check').isDisabled()), { label: 'the button "Проверить" of the pairs' })
  await p.click('pairs-check')
}

/** The answer of a battle round in the way of the mode (`intent`: right / wrong / typo / skip). */
export async function doRound(p, element, intent) {
  switch (element.kind) {
    case 'pairs_round':
      return doPairsBattle(p, element, intent)
    case 'collocation':
      return doCollocation(p, element, intent)
    case 'sentence':
      return doSentence(p, element, intent)
    case 'dictation':
      return doDictation(p, element, intent)
    default:
      throw new E2EError(`no way to answer an element of the kind ${element.kind}`)
  }
}
