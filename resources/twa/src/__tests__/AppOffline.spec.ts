import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import App from '../App.vue'
import { useOfflineContext } from '@/composables/useOnline'
import type { NetworkSignal } from '@/api/client'

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ selectionHaptic: vi.fn() }) }))

const net = vi.hoisted(() => ({ listeners: new Set<(s: NetworkSignal) => void>() }))
vi.mock('@/api/client', () => ({
  onNetworkSignal: (listener: (s: NetworkSignal) => void) => {
    net.listeners.add(listener)
    return () => net.listeners.delete(listener)
  },
}))

function emit(outcome: NetworkSignal['outcome'], method: NetworkSignal['method'] = 'GET'): void {
  for (const listener of [...net.listeners]) listener({ outcome, method })
}

function setDeviceOnline(value: boolean): void {
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => value })
}

const mounts = { today: 0 }
const lesson = vi.hoisted(() => ({ retry: vi.fn(), chip: 'Урок 3 · сохранено 4 из 8' as string | null, pending: false }))

const Today = defineComponent({
  setup() {
    onMounted(() => (mounts.today += 1))
    return () => h('section', { 'data-testid': 'today' }, 'today')
  },
})

/** An immersive screen that tells F5 what to retry, as the lesson does. */
const Lesson = defineComponent({
  setup() {
    useOfflineContext({ retry: lesson.retry, chip: () => lesson.chip, pendingAnswer: () => lesson.pending })
    return () => h('section', { 'data-testid': 'lesson' }, 'lesson')
  },
})

const screen = (testid: string) => ({ template: `<section data-testid="${testid}">screen</section>` })

/** Every App of a test is unmounted after it: an App left alive would go on listening to the network. */
const mounted: VueWrapper[] = []

async function mountApp(start: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: Today, meta: { tab: 'today' } },
      { path: '/course', name: 'my-course', component: screen('my-course'), meta: { tab: 'courses' } },
      { path: '/league', name: 'league', component: screen('league'), meta: { tab: 'rating' } },
      { path: '/profile', name: 'profile', component: screen('profile'), meta: { tab: 'profile' } },
      { path: '/finished', name: 'finished', component: screen('finished') },
      { path: '/settings', name: 'settings', component: { template: '<section data-testid="settings"><h1>Настройки</h1><button>x</button></section>' }, meta: { tab: 'profile' } },
      { path: '/lesson', name: 'lesson', component: Lesson, meta: { immersive: true } },
      { path: '/battle', name: 'battle', component: screen('battle'), meta: { immersive: true } },
    ],
  })
  await router.push(start)
  const w = mount(App, { global: { plugins: [router, createPinia()] }, attachTo: document.body })
  mounted.push(w)
  await flushPromises()
  return { w, router }
}

describe('App — F5 «Нет сети»', () => {
  beforeEach(() => {
    setDeviceOnline(true)
    mounts.today = 0
    lesson.retry.mockReset()
    lesson.chip = 'Урок 3 · сохранено 4 из 8'
    lesson.pending = false
  })
  afterEach(() => {
    setDeviceOnline(true)
    mounted.splice(0).forEach((w) => w.unmount())
    document.body.innerHTML = ''
  })

  it('the device goes offline: F5 over the screen, the screen and the tab bar out of reach; back online: F5 goes', async () => {
    const { w } = await mountApp('/today')
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)

    window.dispatchEvent(new Event('offline'))
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)
    expect(w.get('main').attributes('inert')).toBeDefined()
    expect(w.get('[data-testid=tab-bar]').attributes('inert')).toBeDefined()
    expect(document.activeElement).toBe(w.get('[data-testid=offline-retry]').element)
    // A tab: everything learnt is on the server.
    expect(w.get('[data-testid=offline-chip]').text()).toBe('Прогресс сохранён на сервере')

    window.dispatchEvent(new Event('online'))
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    expect(w.get('main').attributes('inert')).toBeUndefined()
    // Nothing was lost while offline: the screen is not loaded again.
    expect(mounts.today).toBe(1)
  })

  it('F5 gone: the focus goes back into the content - the first heading of the screen, else <main>', async () => {
    const { w, router } = await mountApp('/settings')
    window.dispatchEvent(new Event('offline'))
    await flushPromises()
    expect(document.activeElement).toBe(w.get('[data-testid=offline-retry]').element)

    window.dispatchEvent(new Event('online'))
    await flushPromises()

    const heading = w.get('[data-testid=settings] h1').element
    expect(document.activeElement).toBe(heading)
    expect(heading.getAttribute('tabindex')).toBe('-1')

    await router.push('/today')
    await flushPromises()
    emit('failed', 'GET')
    await flushPromises()
    await w.get('[data-testid=offline-retry]').trigger('click')
    await flushPromises()

    // No heading on this screen: <main> itself, which can take the focus without entering the order of the Tab key.
    expect(document.activeElement).toBe(w.get('main').element)
    expect(w.get('main').attributes('tabindex')).toBe('-1')
  })

  it('a screen that opens offline (no event) gets F5 at once', async () => {
    setDeviceOnline(false)
    const { w } = await mountApp('/today')

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)
  })

  it('a load lost to the network while the device says online: F5 with "Retry", which loads the screen again', async () => {
    const { w } = await mountApp('/today')

    emit('failed', 'GET')
    await flushPromises()
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)

    await w.get('[data-testid=offline-retry]').trigger('click')
    await flushPromises()

    expect(mounts.today).toBe(2)
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
  })

  it('the network comes back after a lost load: the screen is loaded again without a press', async () => {
    const { w } = await mountApp('/today')
    window.dispatchEvent(new Event('offline'))
    emit('failed', 'GET')
    await flushPromises()

    window.dispatchEvent(new Event('online'))
    await flushPromises()

    expect(mounts.today).toBe(2)
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
  })

  it('a request that reaches the server closes F5 (navigator.onLine is not trusted)', async () => {
    const { w } = await mountApp('/today')
    emit('failed', 'GET')
    await flushPromises()

    emit('reached', 'GET')
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
  })

  it('a lost action (POST) is said by its own screen, not by F5', async () => {
    const { w } = await mountApp('/today')

    emit('failed', 'POST')
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
  })

  it('a screen without a tab gets no chip', async () => {
    const { w } = await mountApp('/finished')

    window.dispatchEvent(new Event('offline'))
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)
    expect(w.find('[data-testid=offline-chip]').exists()).toBe(false)
  })

  it('a lost load on another screen does not follow the learner to the next one', async () => {
    const { w, router } = await mountApp('/finished')
    emit('failed', 'GET')
    await flushPromises()
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)

    await router.push('/today')
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
  })

  describe('an immersive screen', () => {
    it('keeps its own error state for a lost request while the device says online', async () => {
      const { w } = await mountApp('/battle')

      emit('failed', 'GET')
      await flushPromises()

      expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    })

    it('gets F5 when the device is offline; without a retry of its own it is not loaded again', async () => {
      const { w } = await mountApp('/battle')
      window.dispatchEvent(new Event('offline'))
      await flushPromises()
      expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)
      expect(w.find('[data-testid=offline-chip]').exists()).toBe(false)

      await w.get('[data-testid=offline-retry]').trigger('click')
      await flushPromises()
      expect(w.find('[data-testid=battle]').exists()).toBe(true)

      window.dispatchEvent(new Event('online'))
      await flushPromises()
      expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    })

    it('the lesson: its chip and text on F5, its retry on "Retry" and on the return of the network', async () => {
      lesson.pending = true
      const { w } = await mountApp('/lesson')
      window.dispatchEvent(new Event('offline'))
      await flushPromises()

      expect(w.get('[data-testid=offline-chip]').text()).toBe('Урок 3 · сохранено 4 из 8')
      expect(w.get('[data-testid=offline-text]').text()).toContain('Последний ответ сохранён на телефоне')

      // Still offline: the press is the retry, once.
      setDeviceOnline(false)
      await w.get('[data-testid=offline-retry]').trigger('click')
      await flushPromises()
      expect(lesson.retry).toHaveBeenCalledTimes(1)

      setDeviceOnline(true)
      window.dispatchEvent(new Event('online'))
      await flushPromises()
      expect(lesson.retry).toHaveBeenCalledTimes(2)
      expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    })
  })
})
