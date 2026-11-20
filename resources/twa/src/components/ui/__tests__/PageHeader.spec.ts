import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PageHeader from '../PageHeader.vue'

describe('PageHeader', () => {
  it('renders the title as the screen heading, 22px semibold by default', () => {
    const w = mount(PageHeader, { props: { title: 'Настройки' } })

    const h1 = w.get('h1')
    expect(h1.text()).toBe('Настройки')
    expect(h1.classes()).toEqual(expect.arrayContaining(['text-[22px]', 'font-semibold']))
    expect(h1.classes()).not.toContain('text-[26px]')
    expect(h1.classes()).not.toContain('font-bold')
    expect(w.find('p').exists()).toBe(false)
    expect(w.text()).toBe('Настройки')
  })

  it('is 26px tight-tracked for a tab root (`large`)', () => {
    const w = mount(PageHeader, { props: { title: 'Курсы', large: true } })

    expect(w.get('h1').classes()).toEqual(expect.arrayContaining(['text-[26px]', 'leading-[30px]', 'tracking-[-0.2px]']))
    expect(w.get('h1').classes()).not.toContain('text-[22px]')
  })

  it('shows the subtitle under the title, in the secondary colour', () => {
    const w = mount(PageHeader, { props: { title: 'Практика', subtitle: 'На словах, которые вы уже учили: 120' } })

    const subtitle = w.get('[data-testid=subtitle]')
    expect(subtitle.text()).toBe('На словах, которые вы уже учили: 120')
    expect(subtitle.classes()).toContain('text-muted')
    expect(w.html().indexOf('<h1')).toBeLessThan(w.html().indexOf('data-testid="subtitle"'))
  })

  it('shows the eyebrow above the title', () => {
    const w = mount(PageHeader, { props: { title: 'Добрый день, Дилноза', eyebrow: 'Пятница, 20 ноября · Английский B1', large: true } })

    const eyebrow = w.get('[data-testid=eyebrow]')
    expect(eyebrow.text()).toBe('Пятница, 20 ноября · Английский B1')
    expect(eyebrow.classes()).toContain('text-muted')
    expect(w.html().indexOf('data-testid="eyebrow"')).toBeLessThan(w.html().indexOf('<h1'))
    expect(w.find('[data-testid=subtitle]').exists()).toBe(false)
  })

  it('renders the trailing slot on the right, only when given', () => {
    const without = mount(PageHeader, { props: { title: 'A' } })
    expect(without.find('[data-testid=chip]').exists()).toBe(false)

    const w = mount(PageHeader, {
      props: { title: 'A' },
      slots: { trailing: '<span class="chip" data-testid="chip">Pro</span>' },
    })
    expect(w.get('[data-testid=chip]').text()).toBe('Pro')
  })

  it('passes attributes to the root', () => {
    const w = mount(PageHeader, { props: { title: 'A' }, attrs: { 'data-testid': 'header' } })

    expect(w.attributes('data-testid')).toBe('header')
  })
})
