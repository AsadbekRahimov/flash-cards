import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '../App.vue'
import { useToast } from '@/composables/useToast'

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ selectionHaptic: vi.fn() }) }))

const screen = (testid: string) => ({ template: `<section data-testid="${testid}">screen</section>` })

async function mountApp(start: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'landing', component: screen('landing') },
      { path: '/today', name: 'today', component: screen('today'), meta: { tab: 'today' } },
      { path: '/courses', name: 'courses', component: screen('courses'), meta: { tab: 'courses' } },
      { path: '/league', name: 'league', component: screen('league'), meta: { tab: 'rating' } },
      { path: '/profile', name: 'profile', component: screen('profile'), meta: { tab: 'profile' } },
      { path: '/training/:id', name: 'training', component: screen('training') },
    ],
  })
  await router.push(start)
  const w = mount(App, { global: { plugins: [router] } })
  await flushPromises()
  return { w, router }
}

describe('App shell', () => {
  afterEach(() => useToast().hide())

  it('shows the screen of the route inside <main>', async () => {
    const { w } = await mountApp('/today')

    expect(w.find('main [data-testid=today]').exists()).toBe(true)
  })

  it('shows the tab bar on a screen that belongs to a tab and pads the content under it', async () => {
    const { w } = await mountApp('/today')

    expect(w.find('[data-testid=tab-bar]').exists()).toBe(true)
    expect(w.get('[data-testid=app-shell]').classes()).toContain('with-tabbar')
  })

  it.each(['/training/5', '/'])('hides the tab bar on %s (immersive screen / landing)', async (path) => {
    const { w } = await mountApp(path)

    expect(w.find('[data-testid=tab-bar]').exists()).toBe(false)
    expect(w.get('[data-testid=app-shell]').classes()).not.toContain('with-tabbar')
  })

  it('shows and hides the tab bar as the route changes', async () => {
    const { w, router } = await mountApp('/today')
    expect(w.find('[data-testid=tab-bar]').exists()).toBe(true)

    await router.push('/training/5')
    await flushPromises()
    expect(w.find('[data-testid=tab-bar]').exists()).toBe(false)
    expect(w.find('[data-testid=training]').exists()).toBe(true)

    await router.push('/league')
    await flushPromises()
    expect(w.find('[data-testid=tab-bar]').exists()).toBe(true)
    expect(w.get('[data-testid=tab-rating]').attributes('aria-current')).toBe('page')
  })

  it('renders the toast on every screen', async () => {
    const { w } = await mountApp('/training/5')

    useToast().show('Сохранено', 60_000)
    await nextTick()

    expect(w.get('[data-testid=toast]').text()).toBe('Сохранено')
  })
})
