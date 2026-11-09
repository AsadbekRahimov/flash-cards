import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import ProBenefits from '../ProBenefits.vue'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import type { PracticeAccess, ProPromise } from '@/api/types'

const FIVE = [
  'Новые слова без дневного лимита',
  'Написание слова и пропуски в примерах',
  'До 20 своих колод по 2000 слов',
  'Заморозки серии: пропуск дня не обрывает серию',
  'Пробные тесты IELTS и SAT',
]

/** The sixth line sits second, after the new words: both are a daily limit that Pro lifts. */
const SIX = [FIVE[0], 'Практика и баттлы без дневного лимита', ...FIVE.slice(1)]

/** The same, with the practice alone: the battles are switched off, or Free has no limit on them. */
const SIX_SOLO = [FIVE[0], 'Практика без дневного лимита', ...FIVE.slice(1)]

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

const texts = (w: ReturnType<typeof mount>): string[] => w.findAll('[data-testid=benefit]').map((li) => li.text())

describe('ProBenefits', () => {
  beforeEach(() => setActivePinia(createPinia()))
  afterEach(() => setLocale('ru'))

  it('lists the Pro benefits with a check each: the five that are always there, until /me says there is a line about practice', () => {
    const w = mount(ProBenefits)

    expect(texts(w)).toEqual(FIVE)
    expect(w.findAll('svg')).toHaveLength(5)
    expect(w.findAll('svg').every((svg) => svg.attributes('fill') === 'currentColor')).toBe(true)
  })

  describe('the line about practice is the word of the server: practice.pro_promise', () => {
    it("'both': practice and battles without a daily limit, second, after the new words", () => {
      know(BOTH)
      const w = mount(ProBenefits)

      expect(texts(w)).toEqual(SIX)
      expect(w.findAll('svg')).toHaveLength(6)
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

  it('compact: smaller icons and text, tighter spacing', () => {
    const regular = mount(ProBenefits)
    const compact = mount(ProBenefits, { props: { compact: true } })

    expect(regular.get('svg').attributes('width')).toBe('20')
    expect(compact.get('svg').attributes('width')).toBe('18')
    expect(regular.classes()).toContain('space-y-3')
    expect(compact.classes()).toContain('space-y-2')
    expect(compact.get('li span').classes()).toContain('text-sm')
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    know(BOTH)
    const w = mount(ProBenefits)

    expect(w.findAll('[data-testid=benefit]')).toHaveLength(6)
    expect(w.text()).toContain("Kuniga istagancha yangi so'z")
    expect(w.text()).toContain('Mustahkamlash mashqlari va bellashuvlar cheklovsiz')
    expect(w.text()).toContain('IELTS va SAT sinov imtihonlari')
  })

  it('speaks Uzbek: the practice alone for solo, and no practice line for none', () => {
    setLocale('uz')
    know(SOLO)
    const solo = mount(ProBenefits)

    expect(solo.findAll('[data-testid=benefit]')).toHaveLength(6)
    expect(solo.text()).toContain('Mustahkamlash mashqlari cheklovsiz')
    expect(solo.text()).not.toContain('bellashuv')

    know(NONE)
    const none = mount(ProBenefits)

    expect(none.findAll('[data-testid=benefit]')).toHaveLength(5)
    expect(none.text()).not.toContain('bellashuv')
    expect(none.text()).not.toContain('Mashqlar')
  })
})
