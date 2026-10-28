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
    expect(w.findAll('a').map((a) => a.text())).toEqual(['Главная', 'Курсы', 'Рейтинг', 'Профиль'])
    expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual(['/today', '/courses', '/league', '/profile'])
    expect(w.findAll('a').map((a) => a.attributes('data-testid'))).toEqual(['tab-today', 'tab-courses', 'tab-rating', 'tab-profile'])
  })

  it('names its landmark, so a screen reader can tell it from the other navigation of the screen', async () => {
    const { w } = await mountBar('/today')
    expect(w.attributes('aria-label')).toBe('Разделы')

    setLocale('uz')
    const { w: uz } = await mountBar('/today')
    expect(uz.attributes('aria-label')).toBe("Bo'limlar")
  })

  it('marks the tab of the current route: accent colour, aria-current, solid icon', async () => {
    const { w } = await mountBar('/league')

    const active = w.get('[data-testid=tab-rating]')
    expect(active.attributes('aria-current')).toBe('page')
    expect(active.classes()).toContain('text-accent')
    expect(active.get('svg').attributes('fill')).toBe('currentColor')

    for (const id of ['today', 'courses', 'profile']) {
      const tab = w.get(`[data-testid=tab-${id}]`)
      expect(tab.attributes('aria-current'), id).toBeUndefined()
      expect(tab.classes(), id).toContain('text-tg-hint')
      expect(tab.get('svg').attributes('fill'), id).toBe('none')
    }
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
    const { w, router } = await mountBar('/courses/ielts-core')

    await w.get('[data-testid=tab-courses]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('courses')
  })

  it('is fixed to the bottom, as high as the tab bar plus the home indicator', async () => {
    const { w } = await mountBar('/today')

    expect(w.classes()).toEqual(expect.arrayContaining(['fixed', 'inset-x-0', 'bottom-0', 'backdrop-blur-xl', 'border-t']))
    expect(w.classes().join(' ')).toContain('env(safe-area-inset-bottom')
    expect(w.get('ul').classes()).toContain('h-[var(--app-tabbar-h)]')
  })

  it('labels the tabs in Uzbek', async () => {
    setLocale('uz')
    const { w } = await mountBar('/today')

    expect(w.findAll('a').map((a) => a.text())).toEqual(['Asosiy', 'Kurslar', 'Reyting', 'Profil'])
  })

  it('highlights nothing on a route without a tab', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/today', name: 'today', component: stub },
        { path: '/courses', name: 'courses', component: stub },
        { path: '/league', name: 'league', component: stub },
        { path: '/profile', name: 'profile', component: stub },
      ],
    })
    await router.push('/today')
    const w = mount(TabBar, { global: { plugins: [router] } })

    expect(w.findAll('[aria-current]')).toHaveLength(0)
  })
})
