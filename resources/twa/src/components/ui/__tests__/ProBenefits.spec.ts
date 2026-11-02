import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import ProBenefits from '../ProBenefits.vue'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import type { PracticeAccess } from '@/api/types'

const FIVE = [
  'Новые слова без дневного лимита',
  'Написание слова и пропуски в примерах',
  'До 20 своих колод по 2000 слов',
  'Заморозки серии: пропуск дня не обрывает серию',
  'Пробные тесты IELTS и SAT',
]

/** The sixth line sits second, after the new words: both are a daily limit that Pro lifts. */
const SIX = [FIVE[0], 'Практика и баттлы без дневного лимита', ...FIVE.slice(1)]

const AVAILABLE: PracticeAccess = { available: true, pro_required: false, modes: ['pairs', 'collocation', 'sentence', 'dictation'], battle: true }
const OFF: PracticeAccess = { available: false, pro_required: false, modes: [], battle: false }

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

  it('lists the Pro benefits with a check each: the five that are always there, until /me says there is practice to sell', () => {
    const w = mount(ProBenefits)

    expect(texts(w)).toEqual(FIVE)
    expect(w.findAll('svg')).toHaveLength(5)
    expect(w.findAll('svg').every((svg) => svg.attributes('fill') === 'currentColor')).toBe(true)
  })

  it('adds "practice and battles without a daily limit", second, when the practice is available to the learner', () => {
    know(AVAILABLE)
    const w = mount(ProBenefits)

    expect(texts(w)).toEqual(SIX)
    expect(w.findAll('svg')).toHaveLength(6)
  })

  it.each([
    ['PRACTICE_ACCESS=off', OFF],
    ['a pilot the learner is not in', { ...OFF, modes: ['pairs'] as PracticeAccess['modes'] }],
    ['an older server that sends no practice block', undefined],
  ])('does not sell what is not there: no such line with %s', (_name, practice) => {
    know(practice)
    const w = mount(ProBenefits)

    expect(texts(w)).toEqual(FIVE)
    expect(w.text()).not.toContain('баттл')
  })

  it('keeps the line when the practice itself is behind Pro (PRACTICE_PLAN=pro: available, pro_required): that is what Pro opens', () => {
    know({ ...AVAILABLE, pro_required: true })
    const w = mount(ProBenefits)

    expect(texts(w)).toEqual(SIX)
  })

  it('does not depend on the battles being on: the line is about the practice being available', () => {
    know({ ...AVAILABLE, battle: false })
    const w = mount(ProBenefits)

    expect(texts(w)).toEqual(SIX)
  })

  it('shows the line when /me comes after the list was drawn, and takes it back if the practice is closed afterwards', async () => {
    const w = mount(ProBenefits)
    expect(texts(w)).toEqual(FIVE)

    know(AVAILABLE)
    await nextTick()
    expect(texts(w)).toEqual(SIX)

    know(OFF)
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
    know(AVAILABLE)
    const w = mount(ProBenefits)

    expect(w.findAll('[data-testid=benefit]')).toHaveLength(6)
    expect(w.text()).toContain("Yangi so'zlar kunlik limitsiz")
    expect(w.text()).toContain('Amaliyot va bellashuvlar kunlik limitsiz')
    expect(w.text()).toContain('IELTS va SAT sinov testlari')
  })

  it('speaks Uzbek without the practice line while there is no practice', () => {
    setLocale('uz')
    know(OFF)
    const w = mount(ProBenefits)

    expect(w.findAll('[data-testid=benefit]')).toHaveLength(5)
    expect(w.text()).not.toContain('bellashuv')
  })
})
