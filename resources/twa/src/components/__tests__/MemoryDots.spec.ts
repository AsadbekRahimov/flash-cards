import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import MemoryDots from '@/components/ui/MemoryDots.vue'
import { setLocale } from '@/i18n'

/*
 * The "memory strength" dots of the kit (F1, F2; docs/40 §2.2): five dots, `strength` of them filled - the danger colour up to 2, the muhr
 * colour from 3 - the rest the sunken paper; the number is said in words (`aria-label`), not by the colour alone.
 */

function dots(strength: number, size?: 'md' | 'sm') {
  const wrapper = mount(MemoryDots, { props: size === undefined ? { strength } : { strength, size } })
  const all = wrapper.findAll('[data-filled]')
  return { wrapper, all, filled: all.filter((dot) => dot.attributes('data-filled') === 'true') }
}

describe('MemoryDots', () => {
  afterEach(() => setLocale('ru'))

  it('draws five dots and fills as many as the strength', () => {
    for (const strength of [0, 1, 2, 3, 4, 5]) {
      const { all, filled, wrapper } = dots(strength)
      expect(all).toHaveLength(5)
      expect(filled).toHaveLength(strength)
      expect(wrapper.attributes('data-strength')).toBe(String(strength))
    }
  })

  it('paints a weak word (≤ 2) in the danger colour and a stronger one in the muhr colour; the empty dots are sunken', () => {
    for (const strength of [1, 2]) {
      const { filled, all } = dots(strength)
      expect(filled.every((dot) => dot.classes('bg-danger') && !dot.classes('bg-muhr'))).toBe(true)
      expect(all.filter((dot) => dot.attributes('data-filled') === 'false').every((dot) => dot.classes('bg-sunken'))).toBe(true)
    }
    for (const strength of [3, 4, 5]) {
      expect(dots(strength).filled.every((dot) => dot.classes('bg-muhr') && !dot.classes('bg-danger'))).toBe(true)
    }
    expect(dots(0).all.every((dot) => dot.classes('bg-sunken'))).toBe(true)
  })

  it('says the number in words, in the language of the app', () => {
    const { wrapper } = dots(3)
    expect(wrapper.attributes('role')).toBe('img')
    expect(wrapper.attributes('aria-label')).toBe('Память: 3 из 5')
    expect(wrapper.findAll('[data-filled]').every((dot) => dot.attributes('aria-hidden') === 'true')).toBe(true)
    setLocale('uz')
    expect(mount(MemoryDots, { props: { strength: 4 } }).attributes('aria-label')).toBe('Xotira: 5 dan 4')
  })

  it('clamps a value out of the scale', () => {
    expect(dots(9).filled).toHaveLength(5)
    expect(dots(-2).filled).toHaveLength(0)
    expect(dots(2.7).filled).toHaveLength(2)
    expect(dots(Number.NaN).filled).toHaveLength(0)
  })

  it('has a 14 px size under a card and an 8 px one in a row of the dictionary', () => {
    expect(dots(3).all.every((dot) => dot.classes('size-3.5'))).toBe(true)
    expect(dots(3, 'sm').all.every((dot) => dot.classes('size-2'))).toBe(true)
  })
})
