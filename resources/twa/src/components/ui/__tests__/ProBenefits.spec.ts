import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProBenefits from '../ProBenefits.vue'
import { setLocale } from '@/i18n'

describe('ProBenefits', () => {
  afterEach(() => setLocale('ru'))

  it('lists the five Pro benefits with a check each', () => {
    const w = mount(ProBenefits)

    expect(w.findAll('[data-testid=benefit]').map((li) => li.text())).toEqual([
      'Новые слова без дневного лимита',
      'Написание слова и пропуски в примерах',
      'До 20 своих колод по 2000 слов',
      'Заморозки серии: пропуск дня не обрывает серию',
      'Пробные тесты IELTS и SAT',
    ])
    expect(w.findAll('svg')).toHaveLength(5)
    expect(w.findAll('svg').every((svg) => svg.attributes('fill') === 'currentColor')).toBe(true)
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
    const w = mount(ProBenefits)

    expect(w.findAll('[data-testid=benefit]')).toHaveLength(5)
    expect(w.text()).toContain("Yangi so'zlar kunlik limitsiz")
    expect(w.text()).toContain('IELTS va SAT sinov testlari')
  })
})
