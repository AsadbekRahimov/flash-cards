import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import ProBenefits from '../ProBenefits.vue'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import { useSettingsStore } from '@/stores/settings'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { PracticeAccess, ProPromise } from '@/api/types'

/**
 * The rows by their keys (`data-benefit`); what each says is checked below. The name stayed from the five lines of before: the emoji of Pro
 * (docs/31 §5а) is the sixth line that is always there, the last one.
 */
const FIVE = [
  'benefits.new_words',
  'benefits.exercises',
  'benefits.decks',
  'benefits.freezes',
  'benefits.mock_exams',
  'billing.benefit.pro_emoji',
]

/** The sixth line sits second, after the new words: both are a daily limit that Pro lifts. */
const SIX = [FIVE[0], 'benefits.practice', ...FIVE.slice(1)]

/** The same, with the practice alone: the battles are switched off, or Free has no limit on them. */
const SIX_SOLO = [FIVE[0], 'benefits.practice_solo', ...FIVE.slice(1)]

const MODES: PracticeAccess['modes'] = ['pairs', 'collocation', 'sentence', 'dictation']

/** The block `practice` of `/me`: what the server says about the practice and, in `pro_promise`, about the line of the Pro list. */
const block = (overrides: Partial<PracticeAccess> = {}): PracticeAccess => ({
  available: true,
  pro_required: false,
  modes: MODES,
  battle: true,
  pro_promise: 'both',
  ...overrides,
})

const BOTH = block()
const SOLO = block({ battle: false, pro_promise: 'solo' })
const NONE = block({ available: false, modes: [], battle: false, pro_promise: 'none' })

/** `/me` of a Free learner; `practice` is its block (absent: an older server). */
function know(practice?: PracticeAccess): void {
  useBillingStore().apply({
    entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
    paywall: { mode: 'coming_soon', contact: null },
    ...(practice ? { practice } : {}),
  })
}

const texts = (w: ReturnType<typeof mount>): (string | undefined)[] => w.findAll('[data-testid=benefit]').map((li) => li.attributes('data-benefit'))

/** "Name | note" of each row. */
const rows = (w: ReturnType<typeof mount>): string[] =>
  w.findAll('[data-testid=benefit]').map((li) => `${li.get('[data-testid=benefit-name]').text()} | ${li.get('[data-testid=benefit-note]').text()}`)

describe('ProBenefits', () => {
  beforeEach(() => setActivePinia(createPinia()))
  afterEach(() => setLocale('ru'))

  it('lists the Pro benefits, one line each: the five that are always there, until /me says there is a line about practice', () => {
    const w = mount(ProBenefits)

    expect(texts(w)).toEqual(FIVE)
    expect(rows(w)).toEqual([
      'Новые слова | без дневного лимита',
      'Упражнения | написание слова и пропуски в примерах',
      'Свои колоды | до 20 колод по 2000 слов',
      'Заморозка серии | пропуск дня не обрывает серию',
      'Пробные тесты | IELTS и SAT',
      'Эмодзи у имени | свой символ рядом с вашим именем',
    ])
  })

  it('leaves the emoji of Pro out when the server sends an empty set of emoji', () => {
    useSettingsStore().adopt(settingsFixture({ options: { ...settingsFixture().options, pro_emojis: [] } }))

    expect(texts(mount(ProBenefits))).toEqual(FIVE.slice(0, -1))
  })

  it('keeps the emoji of Pro while the set has emoji in it', () => {
    useSettingsStore().adopt(settingsFixture({ options: { ...settingsFixture().options, pro_emojis: [{ code: 'fire', emoji: '🔥' }] } }))

    expect(texts(mount(ProBenefits))).toContain('billing.benefit.pro_emoji')
  })

  it('quotes the Free limit in the note on the new words while /me knows it', () => {
    know(BOTH)
    const w = mount(ProBenefits)

    expect(rows(w).slice(0, 2)).toEqual(['Новые слова | без лимита вместо 20 в день', 'Практика и баттлы | без дневного лимита'])
  })

  describe('short: the three rows of the paywall', () => {
    it('new words, the streak freeze and the practice', () => {
      know(SOLO)

      expect(texts(mount(ProBenefits, { props: { short: true } }))).toEqual(['benefits.new_words', 'benefits.freezes', 'benefits.practice_solo'])
    })

    it('the exercises stand in when there is no line about practice', () => {
      know(NONE)

      expect(texts(mount(ProBenefits, { props: { short: true } }))).toEqual(['benefits.new_words', 'benefits.freezes', 'benefits.exercises'])
    })
  })

  describe('the line about practice is the word of the server: practice.pro_promise', () => {
    it("'both': practice and battles without a daily limit, second, after the new words", () => {
      know(BOTH)
      const w = mount(ProBenefits)

      expect(texts(w)).toEqual(SIX)
      expect(w.findAll('[data-testid=benefit]')).toHaveLength(7)
    })

    it("'solo': the practice alone", () => {
      know(SOLO)
      const w = mount(ProBenefits)

      expect(texts(w)).toEqual(SIX_SOLO)
      expect(w.text()).not.toContain('баттл')
    })

    it("'none': no line, and nothing about practice is left in the list", () => {
      know(NONE)
      const w = mount(ProBenefits)

      expect(texts(w)).toEqual(FIVE)
      expect(w.text()).not.toContain('баттл')
      expect(w.text()).not.toContain('Практика')
    })

    it("'/me' has not come yet: no line (the default of the store is 'none')", () => {
      const billing = useBillingStore()
      const w = mount(ProBenefits)

      expect(billing.practice).toBeNull()
      expect(billing.practiceAccess.pro_promise).toBe('none')
      expect(texts(w)).toEqual(FIVE)
    })

    it.each<[string, PracticeAccess | undefined]>([
      ['an older server that sends no practice block', undefined],
      ['an older server whose block has no pro_promise', { available: true, pro_required: false, modes: MODES, battle: true } as PracticeAccess],
      ['a word of a newer server this app does not know', block({ pro_promise: 'trio' as ProPromise })],
    ])('no line for %s', (_name, practice) => {
      know(practice)
      const w = mount(ProBenefits)

      expect(texts(w)).toEqual(FIVE)
    })

    it.each<[string, Partial<PracticeAccess>, string[]]>([
      // The line follows `pro_promise` alone - the flags of the block next to it are not the reason for it.
      ['practice open, battles on, but the server says none (nothing for Pro to lift there)', { pro_promise: 'none' }, FIVE],
      ['practice closed to the learner, the word is none', { available: false, pro_promise: 'none' }, FIVE],
      ['battles on, the word is solo (Free is not limited in them)', { battle: true, pro_promise: 'solo' }, SIX_SOLO],
      ['battles off, the word is both (the server decided)', { battle: false, pro_promise: 'both' }, SIX],
      ['practice behind Pro (available, pro_required), no Free limits: the server says both', { pro_required: true, pro_promise: 'both' }, SIX],
      ['practice behind Pro, battles off: the server says solo', { pro_required: true, battle: false, pro_promise: 'solo' }, SIX_SOLO],
    ])('ignores the flags next to it: %s', (_name, overrides, expected) => {
      know(block(overrides))
      const w = mount(ProBenefits)

      expect(texts(w)).toEqual(expected)
    })
  })

  it('shows the line when /me comes after the list was drawn, changes it with the word, and takes it back when the word becomes none', async () => {
    const w = mount(ProBenefits)
    expect(texts(w)).toEqual(FIVE)

    know(BOTH)
    await nextTick()
    expect(texts(w)).toEqual(SIX)

    know(SOLO)
    await nextTick()
    expect(texts(w)).toEqual(SIX_SOLO)

    know(NONE)
    await nextTick()
    expect(texts(w)).toEqual(FIVE)
  })

  it('is a list with a test id', () => {
    const w = mount(ProBenefits)

    expect(w.element.tagName).toBe('UL')
    expect(w.attributes('data-testid')).toBe('pro-benefits')
  })

  it('draws a green tick in a soft circle, the name and the note under it, no lines between the items (docs/design/2026-11-25-redesign Pro)', () => {
    const w = mount(ProBenefits)

    expect(w.classes()).not.toContain('border-t')
    for (const item of w.findAll('[data-testid=benefit]')) {
      const tick = item.get('span[aria-hidden=true]')
      expect(tick.classes()).toEqual(expect.arrayContaining(['rounded-full', 'bg-success-soft', 'text-success-ink']))
      expect(tick.find('svg').exists()).toBe(true)
      expect(item.classes()).not.toContain('border-b')
      expect(item.get('[data-testid=benefit-name]').classes()).toEqual(expect.arrayContaining(['text-base', 'font-bold']))
      expect(item.get('[data-testid=benefit-note]').classes()).toContain('text-muted')
    }
  })

  it('compact: tighter rows', () => {
    const regular = mount(ProBenefits)
    const compact = mount(ProBenefits, { props: { compact: true } })

    expect(regular.classes()).toContain('gap-3.5')
    expect(compact.classes()).toContain('gap-3')
  })

  describe('the line about the game is the word of the server: game.pro_promise', () => {
    const knowGame = (pro_promise: 'none' | 'full'): void => {
      useBillingStore().apply({
        entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
        paywall: { mode: 'coming_soon', contact: null },
        practice: BOTH,
        game: { available: pro_promise === 'full', limits: null, pro_promise, coins: 0, tickets: 0 },
      })
    }

    it("'full': the Caravan without a daily limit, right after the practice", () => {
      knowGame('full')

      expect(texts(mount(ProBenefits))).toEqual([FIVE[0], 'benefits.practice', 'benefits.game', ...FIVE.slice(1)])
      expect(rows(mount(ProBenefits))[2]).toBe('Караван | без дневного лимита')
    })

    it("'none' (or no word of the game): no such line", () => {
      knowGame('none')

      expect(texts(mount(ProBenefits))).toEqual(SIX)
    })

    it('speaks Uzbek', () => {
      setLocale('uz')
      knowGame('full')

      expect(rows(mount(ProBenefits))[2]).toBe('Karvon | kunlik cheklovsiz')
    })
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    know(BOTH)
    const w = mount(ProBenefits)

    expect(w.findAll('[data-testid=benefit]')).toHaveLength(7)
    expect(rows(w)[0]).toBe("Yangi so'zlar | kuniga 20 ta o'rniga cheklovsiz")
    expect(rows(w)[1]).toBe('Mashqlar va bellashuvlar | kunlik cheklovsiz')
    expect(rows(w)[5]).toBe('Sinov imtihonlari | IELTS va SAT')
    expect(rows(w)[6]).toBe("Ism yonidagi emoji | ismingiz yonida o'zingiz tanlagan belgi")
  })

  it('speaks Uzbek: the practice alone for solo, and no practice line for none', () => {
    setLocale('uz')
    know(SOLO)
    const solo = mount(ProBenefits)

    expect(solo.findAll('[data-testid=benefit]')).toHaveLength(7)
    expect(rows(solo)[1]).toBe('Mashqlar | kunlik cheklovsiz')
    expect(solo.text()).not.toContain('bellashuv')

    know(NONE)
    const none = mount(ProBenefits)

    expect(none.findAll('[data-testid=benefit]')).toHaveLength(6)
    expect(none.text()).not.toContain('bellashuv')
    expect(none.text()).not.toContain('Mashqlar')
  })
})
