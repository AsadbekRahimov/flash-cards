import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PageHeader from '../PageHeader.vue'

describe('PageHeader', () => {
  it('renders the title as the screen heading, 22px by default', () => {
    const w = mount(PageHeader, { props: { title: 'Настройки' } })

    const h1 = w.get('h1')
    expect(h1.text()).toBe('Настройки')
    expect(h1.classes()).toContain('text-[22px]')
    expect(h1.classes()).not.toContain('text-[28px]')
    expect(w.find('p').exists()).toBe(false)
    expect(w.text()).toBe('Настройки')
  })

  it('is 28px tight-tracked for a tab root (`large`)', () => {
    const w = mount(PageHeader, { props: { title: 'Курсы', large: true } })

    expect(w.get('h1').classes()).toEqual(expect.arrayContaining(['text-[28px]', 'tracking-tight']))
    expect(w.get('h1').classes()).not.toContain('text-[22px]')
  })

  it('shows the subtitle under the title', () => {
    const w = mount(PageHeader, { props: { title: 'Привет, Асадбек!', subtitle: 'вторник, 28 октября' } })

    expect(w.get('p').text()).toBe('вторник, 28 октября')
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
