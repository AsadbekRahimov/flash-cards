import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import appRouter from '@/router'
import { installVerticalSwipeGuard, type VerticalSwipesApi } from '../verticalSwipes'

function stubApp(version = '7.7') {
  const app = {
    isVersionAtLeast: (wanted: string) => Number(version) >= Number(wanted),
    disableVerticalSwipes: vi.fn<() => void>(),
    enableVerticalSwipes: vi.fn<() => void>(),
  }
  return app satisfies VerticalSwipesApi
}

function makeRouter() {
  const stub = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub, meta: { tab: 'today' } },
      { path: '/achievements', name: 'achievements', component: stub, meta: { tab: 'profile' } },
      { path: '/finished', name: 'finished', component: stub },
      { path: '/training/:id', name: 'training', component: stub, meta: { immersive: true } },
      { path: '/battles/:id', name: 'battle', component: stub, meta: { immersive: true } },
    ],
  })
}

describe('installVerticalSwipeGuard', () => {
  it('switches the swipe off on an immersive screen and on again when the learner leaves it', async () => {
    const router = makeRouter()
    const app = stubApp()
    installVerticalSwipeGuard(router, app)

    await router.push('/today')
    expect(app.disableVerticalSwipes).not.toHaveBeenCalled()
    expect(app.enableVerticalSwipes).not.toHaveBeenCalled()

    await router.push('/training/1')
    expect(app.disableVerticalSwipes).toHaveBeenCalledTimes(1)

    // from one immersive screen to another: nothing to change
    await router.push('/battles/2')
    expect(app.disableVerticalSwipes).toHaveBeenCalledTimes(1)
    expect(app.enableVerticalSwipes).not.toHaveBeenCalled()

    await router.push('/finished')
    expect(app.enableVerticalSwipes).toHaveBeenCalledTimes(1)

    await router.push('/achievements')
    expect(app.enableVerticalSwipes).toHaveBeenCalledTimes(1)
  })

  it('switches it off at once when the app opens straight on an immersive screen (a link of the bot)', async () => {
    const router = makeRouter()
    const app = stubApp()
    installVerticalSwipeGuard(router, app)

    await router.push('/battles/7')

    expect(app.disableVerticalSwipes).toHaveBeenCalledTimes(1)
  })

  it('asks nothing of a client older than Bot API 7.7, nor outside Telegram', async () => {
    const router = makeRouter()
    const old = stubApp('7.6')
    installVerticalSwipeGuard(router, old)
    installVerticalSwipeGuard(router, null)

    await router.push('/training/1')
    await router.push('/today')

    expect(old.disableVerticalSwipes).not.toHaveBeenCalled()
    expect(old.enableVerticalSwipes).not.toHaveBeenCalled()
  })

  it('marks exactly the lesson, the exam, a practice session, the rounds of a battle / a cup / a duel and a run of "Karvon" immersive', () => {
    const immersive = appRouter.getRoutes().filter((route) => route.meta.immersive === true).map((route) => String(route.name)).sort()

    // a duel of a cup is played on the screen of the battle (`/battles/:id`)
    expect(immersive).toEqual(['battle', 'cup-round', 'exam', 'game-run', 'practice-session', 'training'])
  })
})
