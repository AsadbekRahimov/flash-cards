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

  it('has three sizes, 40 px by default', () => {
    expect(render({ name: 'A' }).classes()).toContain('size-10')
    expect(render({ name: 'A', size: 'md' }).classes()).toContain('size-10')
    expect(render({ name: 'A', size: 'sm' }).classes()).toContain('size-8')
    expect(render({ name: 'A', size: 'lg' }).classes()).toEqual(expect.arrayContaining(['size-[72px]', 'font-display']))
  })

  it('is quiet by default and filled with the primary colour for the learner themself, in tokens of the palette', () => {
    const neutral = render({ name: 'A' })
    expect(neutral.classes()).toEqual(expect.arrayContaining(['bg-fill', 'text-muted']))
    expect(neutral.classes()).not.toContain('bg-primary')

    const mine = render({ name: 'A', accent: true })
    expect(mine.classes()).toEqual(expect.arrayContaining(['bg-primary', 'text-on-primary']))
    expect(mine.classes()).not.toContain('bg-fill')
    expect(mine.classes().some((c) => c.includes('tg-'))).toBe(false)
  })

  it('takes the muhr colour of the kit for the "you" row of a table', () => {
    const mine = render({ name: 'A', muhr: true })
    expect(mine.classes()).toEqual(expect.arrayContaining(['bg-muhr', 'text-on-muhr']))
    expect(mine.classes()).not.toContain('bg-fill')
    expect(mine.classes()).not.toContain('bg-primary')
  })

  it('draws the person icon in proportion to the size for a blank name', () => {
    expect(render({ name: '', size: 'lg' }).get('svg').attributes('width')).toBe('36')
    expect(render({ name: '', size: 'sm' }).get('svg').attributes('width')).toBe('18')
  })
})
