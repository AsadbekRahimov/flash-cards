import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { ofetch } from 'ofetch'
import App from '../App.vue'
import { createApiClient } from '@/api/client'
import { BACKGROUND } from '@/api/background'

/*
 * F5 "Нет сети" with the real client (docs/40 §2.6): only the loss of the main load of a screen raises it while the device says it is
 * online. A background read (`RequestOptions.background`: the achievements of the profile, "my cups" of "Today", a poll, the links of the
 * story screen) is lost quietly - no overlay, no `inert`, no reload of the screen by "Retry".
 */

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ selectionHaptic: vi.fn() }) }))
vi.mock('ofetch', () => ({ ofetch: vi.fn() }))

const client = createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' })
const fetchFailed = () => Object.assign(new TypeError('Failed to fetch'), { response: undefined })

const mounts = { profile: 0 }

/** The profile: a background read (`/achievements`) on mount, its failure swallowed; the test makes the main load (`/me`) itself. */
const Profile = defineComponent({
  setup() {
    onMounted(() => {
      mounts.profile += 1
      void client.get('/achievements', undefined, BACKGROUND).catch(() => undefined)
    })
    return () => h('section', { 'data-testid': 'profile' }, [h('h1', 'Профиль')])
  },
})

const mounted: VueWrapper[] = []

async function mountApp() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: Profile, meta: { tab: 'profile' } },
      // The other roots of the tab bar.
      ...['today', 'my-course', 'league'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
    ],
  })
  await router.push('/profile')
  const w = mount(App, { global: { plugins: [router, createPinia()] }, attachTo: document.body })
  mounted.push(w)
  await flushPromises()
  return w
}

describe('App — F5 and the background reads of a screen', () => {
  beforeEach(() => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => true })
    vi.mocked(ofetch).mockReset()
    mounts.profile = 0
  })
  afterEach(() => {
    mounted.splice(0).forEach((w) => w.unmount())
    document.body.innerHTML = ''
  })

  it('a background read lost to the network while the device says online raises nothing', async () => {
    vi.mocked(ofetch).mockRejectedValue(fetchFailed())
    const w = await mountApp()

    expect(ofetch).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    expect(w.get('main').attributes('inert')).toBeUndefined()
    expect(mounts.profile).toBe(1)
  })

  it('the main load of the screen lost to the network raises F5; its "Retry" loads the screen again', async () => {
    vi.mocked(ofetch).mockRejectedValueOnce(fetchFailed())
    const w = await mountApp()
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)

    vi.mocked(ofetch).mockRejectedValueOnce(fetchFailed())
    await client.get('/me').catch(() => undefined)
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)
    expect(w.get('main').attributes('inert')).toBeDefined()

    vi.mocked(ofetch).mockResolvedValue({} as never)
    await w.get('[data-testid=offline-retry]').trigger('click')
    await flushPromises()

    expect(mounts.profile).toBe(2)
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    expect(document.activeElement).toBe(w.get('[data-testid=profile] h1').element)
  })

  it('a background read that reaches the server still closes F5 (the connection is back)', async () => {
    vi.mocked(ofetch).mockRejectedValueOnce(fetchFailed())
    const w = await mountApp()
    vi.mocked(ofetch).mockRejectedValueOnce(fetchFailed())
    await client.get('/me').catch(() => undefined)
    await flushPromises()
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)

    vi.mocked(ofetch).mockResolvedValueOnce({} as never)
    await client.get('/cups', undefined, BACKGROUND)
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
  })
})
