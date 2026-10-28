import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import InitialAvatar from '../InitialAvatar.vue'

const render = (props: InstanceType<typeof InitialAvatar>['$props']) => mount(InitialAvatar, { props })

describe('InitialAvatar', () => {
  it('shows the capitalised first letter of the name', () => {
    expect(render({ name: 'dilnoza' }).text()).toBe('D')
    expect(render({ name: 'Асадбек' }).text()).toBe('А')
    expect(render({ name: '  bek' }).text()).toBe('B')
  })

  it('takes a whole code point, so an emoji is not cut in half', () => {
    expect(render({ name: '🔥Fire' }).text()).toBe('🔥')
  })

  it('shows a person icon for a blank name', () => {
    const w = render({ name: '   ' })

    expect(w.text()).toBe('')
    expect(w.find('svg').exists()).toBe(true)
  })

  it('is decorative: hidden from assistive technology', () => {
    expect(render({ name: 'Aziz' }).attributes('aria-hidden')).toBe('true')
  })

  it('has two sizes, 40 px by default', () => {
    expect(render({ name: 'A' }).classes()).toContain('size-10')
    expect(render({ name: 'A', size: 'md' }).classes()).toContain('size-10')
    expect(render({ name: 'A', size: 'sm' }).classes()).toContain('size-8')
  })

  it('is neutral by default and accent-filled for the learner themself', () => {
    const neutral = render({ name: 'A' })
    expect(neutral.classes()).toContain('bg-track')
    expect(neutral.classes()).not.toContain('bg-accent')

    const mine = render({ name: 'A', accent: true })
    expect(mine.classes()).toContain('bg-accent')
    expect(mine.classes()).not.toContain('bg-track')
  })
})
