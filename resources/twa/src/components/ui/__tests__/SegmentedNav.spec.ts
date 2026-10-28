import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import SegmentedNav from '../SegmentedNav.vue'

const ITEMS = [
  { to: { name: 'courses' }, label: 'Курсы', testid: 'nav-courses' },
  { to: { name: 'decks' }, label: 'Мои колоды', testid: 'nav-decks' },
]

async function mountNav(start: string) {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/decks', name: 'decks', component: stub },
      { path: '/other', name: 'other', component: stub },
    ],
  })
  await router.push(start)
  const w = mount(SegmentedNav, { props: { items: ITEMS }, global: { plugins: [router] } })
  return { w, router }
}

describe('SegmentedNav', () => {
  it('renders a link per item with its href and label', async () => {
    const { w } = await mountNav('/courses')

    const links = w.findAll('a')
    expect(links.map((a) => a.text())).toEqual(['Курсы', 'Мои колоды'])
    expect(links.map((a) => a.attributes('href'))).toEqual(['/courses', '/decks'])
    expect(w.get('[data-testid=nav-decks]').text()).toBe('Мои колоды')
  })

  it('highlights the item of the current route', async () => {
    const { w } = await mountNav('/decks')

    expect(w.get('[data-testid=nav-decks]').attributes('aria-current')).toBe('page')
    expect(w.get('[data-testid=nav-decks]').classes()).toContain('bg-surface')
    expect(w.get('[data-testid=nav-courses]').attributes('aria-current')).toBeUndefined()
    expect(w.get('[data-testid=nav-courses]').classes()).not.toContain('bg-surface')
  })

  it('highlights nothing on another route', async () => {
    const { w } = await mountNav('/other')

    expect(w.findAll('[aria-current]')).toHaveLength(0)
  })

  it('switches the screen and the highlight on click', async () => {
    const { w, router } = await mountNav('/courses')

    await w.get('[data-testid=nav-decks]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('decks')
    expect(w.get('[data-testid=nav-decks]').attributes('aria-current')).toBe('page')
    expect(w.get('[data-testid=nav-courses]').attributes('aria-current')).toBeUndefined()
  })
})
