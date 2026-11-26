import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import TabBar from '../TabBar.vue'
import { setLocale } from '@/i18n'
import type { TabId } from '@/router'

const haptic = vi.hoisted(() => ({ selectionHaptic: vi.fn() }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => haptic }))

const stub = { template: '<div />' }

function makeRouter() {
  const meta = (tab: TabId) => ({ tab })
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub, meta: meta('today') },
      { path: '/course', name: 'my-course', component: stub, meta: meta('courses') },
      { path: '/courses', name: 'courses', component: stub, meta: meta('courses') },
      { path: '/courses/:slug', name: 'course', component: stub, meta: meta('courses') },
      { path: '/league', name: 'league', component: stub, meta: meta('rating') },
      { path: '/friends', name: 'friends', component: stub, meta: meta('rating') },
      { path: '/profile', name: 'profile', component: stub, meta: meta('profile') },
      { path: '/settings', name: 'settings', component: stub, meta: meta('profile') },
    ],
  })
}

async function mountBar(start: string) {
  const router = makeRouter()
  await router.push(start)
  const w = mount(TabBar, { global: { plugins: [router] } })
  return { w, router }
}

describe('TabBar', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => setLocale('ru'))

  it('is a navigation with the four tabs of docs/14 §5.1, in order', async () => {
    const { w } = await mountBar('/today')

    expect(w.attributes('role')).toBe('navigation')
    expect(w.findAll('a').map((a) => a.text())).toEqual(['Сегодня', 'Курс', 'Лига', 'Профиль'])
    expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual(['/today', '/course', '/league', '/profile'])
    expect(w.findAll('a').map((a) => a.attributes('data-testid'))).toEqual(['tab-today', 'tab-courses', 'tab-rating', 'tab-profile'])
  })

  it('names its landmark, so a screen reader can tell it from the other navigation of the screen', async () => {
    const { w } = await mountBar('/today')
    expect(w.attributes('aria-label')).toBe('Разделы')

    setLocale('uz')
    const { w: uz } = await mountBar('/today')
    expect(uz.attributes('aria-label')).toBe("Bo'limlar")
  })

  it('pairs every label with a decorative inline icon of its own, drawn in the colour of the text', async () => {
    const { w } = await mountBar('/today')

    const icons = w.findAll('a').map((link) => {
      const svg = link.get('svg')
      expect(svg.attributes('aria-hidden')).toBe('true')
      expect(svg.attributes('stroke')).toBe('currentColor')
      expect(svg.attributes('fill')).toBe('none')
      expect(svg.attributes('viewBox')).toBe('0 0 24 24')
      expect(svg.findAll('path').length).toBeGreaterThan(0)
      // the icon comes first, the label under it
      expect(link.element.firstElementChild?.tagName.toLowerCase()).toBe('svg')
      expect(link.text()).not.toBe('')
      return svg.attributes('data-icon')
    })

    expect(icons).toEqual(['today', 'courses', 'rating', 'profile'])
    // four different drawings, not one icon four times
    expect(new Set(w.findAll('svg').map((svg) => svg.html())).size).toBe(4)
  })

  it('marks the tab of the current route: a pill in nav-active, the accent, bold, aria-current', async () => {
    const { w } = await mountBar('/league')

    const active = w.get('[data-testid=tab-rating]')
    expect(active.attributes('aria-current')).toBe('page')
    expect(active.classes()).toEqual(expect.arrayContaining(['rounded-option', 'bg-nav-active', 'text-accent', 'font-bold']))

    for (const id of ['today', 'courses', 'profile']) {
      const tab = w.get(`[data-testid=tab-${id}]`)
      expect(tab.attributes('aria-current'), id).toBeUndefined()
      expect(tab.classes(), id).toEqual(expect.arrayContaining(['rounded-option', 'text-body', 'font-medium']))
      expect(tab.classes(), id).not.toContain('bg-nav-active')
      expect(tab.classes(), id).not.toContain('font-bold')
    }
  })

  it('moves the pill with the route', async () => {
    const { w, router } = await mountBar('/today')
    expect(w.get('[data-testid=tab-today]').classes()).toContain('bg-nav-active')

    await router.push('/profile')
    await flushPromises()

    expect(w.findAll('.bg-nav-active').map((tab) => tab.attributes('data-testid'))).toEqual(['tab-profile'])
    expect(w.findAll('[aria-current=page]').map((tab) => tab.attributes('data-testid'))).toEqual(['tab-profile'])
  })

  it.each([
    ['/courses/ielts-core', 'courses'],
    ['/friends', 'rating'],
    ['/settings', 'profile'],
  ])('keeps the tab of an inner screen highlighted: %s -> %s', async (path, tab) => {
    const { w } = await mountBar(path)

    expect(w.findAll('[aria-current=page]')).toHaveLength(1)
    expect(w.get(`[data-testid=tab-${tab}]`).attributes('aria-current')).toBe('page')
  })

  it('switches tab on press, with a selection haptic', async () => {
    const { w, router } = await mountBar('/today')

    await w.get('[data-testid=tab-profile]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('profile')
    expect(haptic.selectionHaptic).toHaveBeenCalledTimes(1)
    expect(w.get('[data-testid=tab-profile]').attributes('aria-current')).toBe('page')
    expect(w.get('[data-testid=tab-today]').attributes('aria-current')).toBeUndefined()
  })

  it('no haptic when the pressed tab is already the active one', async () => {
    const { w } = await mountBar('/today')

    await w.get('[data-testid=tab-today]').trigger('click')
    await flushPromises()

    expect(haptic.selectionHaptic).not.toHaveBeenCalled()
  })

  it('from an inner screen the active tab leads back to its root', async () => {
    const { w, router } = await mountBar('/settings')

    await w.get('[data-testid=tab-profile]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('profile')
  })

  it('opens the learner course from the "Course" tab, not the catalog (docs/14 §6.5): the screen that looks it up', async () => {
    const { w, router } = await mountBar('/courses/ielts-core')

    await w.get('[data-testid=tab-courses]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('my-course')
    expect(w.get('[data-testid=tab-courses]').attributes('aria-current')).toBe('page')
  })

  it('is fixed to the bottom, flat, as high as the tab bar plus the home indicator', async () => {
    const { w } = await mountBar('/today')

    expect(w.classes()).toEqual(expect.arrayContaining(['fixed', 'inset-x-0', 'bottom-0', 'bg-surface', 'border-t', 'border-hairline']))
    expect(w.classes().join(' ')).not.toMatch(/backdrop-blur|shadow/)
    expect(w.classes().join(' ')).toContain('env(safe-area-inset-bottom')
    expect(w.get('ul').classes()).toContain('h-[var(--app-tabbar-h)]')
  })

  it('labels the tabs in Uzbek', async () => {
    setLocale('uz')
    const { w } = await mountBar('/today')

    expect(w.findAll('a').map((a) => a.text())).toEqual(['Bugun', 'Kurs', 'Liga', 'Profil'])
  })

  it('highlights nothing on a route without a tab', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/today', name: 'today', component: stub },
        { path: '/course', name: 'my-course', component: stub },
        { path: '/league', name: 'league', component: stub },
        { path: '/profile', name: 'profile', component: stub },
      ],
    })
    await router.push('/today')
    const w = mount(TabBar, { global: { plugins: [router] } })

    expect(w.findAll('[aria-current]')).toHaveLength(0)
  })
})
