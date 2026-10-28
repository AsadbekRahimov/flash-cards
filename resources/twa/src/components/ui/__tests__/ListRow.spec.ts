import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ListRow from '../ListRow.vue'

async function mountRow(options: Parameters<typeof mount<typeof ListRow>>[1] = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  const w = mount(ListRow, { ...options, global: { plugins: [router] } })
  return { w, router }
}

describe('ListRow', () => {
  it('with `to` is a link with a chevron; the test id lands on the link', async () => {
    const { w } = await mountRow({
      props: { to: { name: 'billing' }, title: 'Подписка', icon: 'credit-card', tile: 'purple' },
      attrs: { 'data-testid': 'row-billing' },
    })

    expect(w.element.tagName).toBe('A')
    expect(w.attributes('href')).toBe('/billing')
    expect(w.attributes('data-testid')).toBe('row-billing')
    expect(w.classes()).toEqual(expect.arrayContaining(['list-row', 'list-row-icon']))
    expect(w.find('.icon-tile.tile-purple svg').exists()).toBe(true)
    // the chevron
    expect(w.findAll('svg')).toHaveLength(2)
  })

  it('navigates when the link is pressed', async () => {
    const { w, router } = await mountRow({ props: { to: { name: 'billing' }, title: 'Подписка' } })

    await w.trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(router.currentRoute.value.name).toBe('billing')
  })

  it('a link can drop the chevron', async () => {
    const { w } = await mountRow({ props: { to: { name: 'billing' }, title: 'Подписка', chevron: false } })

    expect(w.find('svg').exists()).toBe(false)
  })

  it('with a click listener is a button that emits click', async () => {
    const { w } = await mountRow({ props: { title: 'Удалить', onClick: () => {} } })

    expect(w.element.tagName).toBe('BUTTON')
    expect(w.attributes('type')).toBe('button')
    await w.trigger('click')

    expect(w.emitted('click')).toHaveLength(1)
  })

  it('calls the parent handler with the click event', async () => {
    let received: unknown = null
    const { w } = await mountRow({ props: { title: 'x', onClick: (event: MouseEvent) => (received = event) } })

    await w.trigger('click')

    expect(received).toBeInstanceOf(MouseEvent)
  })

  it('without a listener or a link is a plain div, with no chevron', async () => {
    const { w } = await mountRow({ props: { title: 'Язык', value: 'Русский' } })

    expect(w.element.tagName).toBe('DIV')
    expect(w.attributes('type')).toBeUndefined()
    expect(w.find('svg').exists()).toBe(false)
    expect(w.text()).toContain('Русский')
  })

  it('a button can force the chevron', async () => {
    const { w } = await mountRow({ props: { title: 'x', chevron: true, onClick: () => {} } })

    expect(w.find('svg').exists()).toBe(true)
  })

  it('shows title, subtitle and value', async () => {
    const { w } = await mountRow({ props: { title: 'Пригласить друга', subtitle: 'Вам и другу — Pro бесплатно', value: 'Free' } })

    expect(w.text()).toContain('Пригласить друга')
    expect(w.text()).toContain('Вам и другу — Pro бесплатно')
    expect(w.text()).toContain('Free')
  })

  it('draws the tile gray when no colour is given, and no tile without an icon', async () => {
    const withIcon = await mountRow({ props: { title: 'x', icon: 'cog-6-tooth' } })
    expect(withIcon.w.find('.icon-tile.tile-gray').exists()).toBe(true)

    const without = await mountRow({ props: { title: 'x' } })
    expect(without.w.find('.icon-tile').exists()).toBe(false)
    expect(without.w.classes()).not.toContain('list-row-icon')
  })

  it('turns the title red for a destructive row', async () => {
    const { w } = await mountRow({ props: { title: 'Удалить колоду', danger: true } })

    expect(w.find('.text-danger').text()).toBe('Удалить колоду')
  })

  it('a disabled row is an inert div: no link, no click', async () => {
    const { w } = await mountRow({ props: { to: { name: 'billing' }, title: 'Подписка', disabled: true, onClick: () => {} } })

    expect(w.element.tagName).toBe('DIV')
    expect(w.attributes('href')).toBeUndefined()
    expect(w.attributes('aria-disabled')).toBe('true')
    expect(w.classes()).toContain('opacity-50')
    await w.trigger('click')
    expect(w.emitted('click')).toBeUndefined()
  })

  it('renders the leading and trailing slots instead of the tile and the value', async () => {
    const { w } = await mountRow({
      props: { title: 'Лиги', icon: 'trophy', value: 'не показывать' },
      slots: {
        leading: () => h('span', { 'data-testid': 'lead' }, 'L'),
        trailing: () => h('span', { 'data-testid': 'trail' }, 'T'),
      },
    })

    expect(w.get('[data-testid=lead]').text()).toBe('L')
    expect(w.get('[data-testid=trail]').text()).toBe('T')
    expect(w.find('.icon-tile').exists()).toBe(false)
    expect(w.text()).not.toContain('не показывать')
    expect(w.classes()).toContain('list-row-icon')
  })
})
