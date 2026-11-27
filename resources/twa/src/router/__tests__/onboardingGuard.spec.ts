import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { installOnboardingGuard, needsOnboarding, onboardingLocation } from '@/composables/useOnboardingGuard'
import appRouter from '../index'
import type { Learner } from '@/api/types'

/*
 * The guard of the onboarding (docs/40 §2.1 "Когда показывать"): a learner with `onboarding.completed = false` is sent from the roots
 * of the tabs to the step the server keeps; a learner who finished it (in the bot too), an older server without the block, a deep link
 * (`start_param`) and a link with `?from=` are let through. Before the learner is known, the guard waits for `/auth`.
 */

const state = vi.hoisted(() => ({
  learner: null as Learner | null,
  ensure: vi.fn(),
  inTelegram: true,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ensure: state.ensure,
    get learner() {
      return state.learner
    },
  }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ isInTelegram: () => state.inTelegram }),
}))

const learner = (onboarding: Learner['onboarding'], completed = false): Learner => ({
  id: 1,
  first_name: 'Aziz',
  locale: 'ru',
  onboarding_completed: completed,
  ...(onboarding !== undefined ? { onboarding } : {}),
})

const blank = { template: '<div />' }

function makeRouter(start: string | null = null): Router {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'landing', component: blank },
      { path: '/today', name: 'today', component: blank, meta: { tab: 'today' } },
      { path: '/course', name: 'my-course', component: blank, meta: { tab: 'courses' } },
      { path: '/league', name: 'league', component: blank, meta: { tab: 'rating' } },
      { path: '/profile', name: 'profile', component: blank, meta: { tab: 'profile' } },
      { path: '/battles/:id', name: 'battle', component: blank },
      { path: '/cups/c/:code', name: 'cup-invite', component: blank, meta: { tab: 'rating' } },
      { path: '/onboarding/:step?', name: 'onboarding', component: blank },
    ],
  })
  installOnboardingGuard(router, { readStart: () => start })
  return router
}

describe('onboarding guard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    state.learner = null
    state.inTelegram = true
    state.ensure.mockImplementation(async () => {
      state.learner ??= learner({ completed: false, step: null })
    })
  })

  it('sends a newcomer from "Today" to O1, waiting for /auth first', async () => {
    const router = makeRouter()

    await router.push('/today')

    expect(state.ensure).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.fullPath).toBe('/onboarding')
  })

  it.each([
    ['/today', 'level', '/onboarding/level'],
    ['/course', 'daily_goal', '/onboarding/daily_goal'],
    ['/league', 'plan', '/onboarding/plan'],
    ['/profile', 'name', '/onboarding'],
  ])('resumes from %s at the saved step %s', async (from, step, target) => {
    state.learner = learner({ completed: false, step })
    const router = makeRouter()

    await router.push(from)

    expect(state.ensure).not.toHaveBeenCalled()
    expect(router.currentRoute.value.fullPath).toBe(target)
  })

  it('lets through a learner who finished it (in the bot or here)', async () => {
    state.learner = learner({ completed: true, step: null }, true)
    const router = makeRouter()

    await router.push('/today')

    expect(router.currentRoute.value.fullPath).toBe('/today')
  })

  it('lets everyone through on an older server without the onboarding block', async () => {
    state.learner = learner(undefined, false)
    const router = makeRouter()

    await router.push('/today')

    expect(router.currentRoute.value.fullPath).toBe('/today')
  })

  it('does not intercept an open by a deep link, for the whole open', async () => {
    state.learner = learner({ completed: false, step: 'age' })
    const router = makeRouter('L2JhdHRsZXMvNw')

    await router.push('/battles/7')
    expect(router.currentRoute.value.fullPath).toBe('/battles/7')
    await router.push('/cups/c/abc')
    expect(router.currentRoute.value.fullPath).toBe('/cups/c/abc')
    await router.push('/today')
    expect(router.currentRoute.value.fullPath).toBe('/today')
  })

  it('does not intercept a link with ?from= (a reminder, an assignment), nor a screen that is not a tab root', async () => {
    state.learner = learner({ completed: false, step: 'age' })
    const router = makeRouter()

    await router.push('/today?from=assignment&lesson=7')
    expect(router.currentRoute.value.fullPath).toBe('/today?from=assignment&lesson=7')
    await router.push('/battles/7')
    expect(router.currentRoute.value.fullPath).toBe('/battles/7')
    await router.push('/cups/c/abc')
    expect(router.currentRoute.value.fullPath).toBe('/cups/c/abc')
  })

  it('lets the screen through when /auth fails or the app is outside Telegram', async () => {
    state.ensure.mockRejectedValue(new Error('network'))
    const router = makeRouter()
    await router.push('/today')
    expect(router.currentRoute.value.fullPath).toBe('/today')

    state.inTelegram = false
    state.ensure.mockClear()
    const outside = makeRouter()
    await outside.push('/profile')
    expect(outside.currentRoute.value.fullPath).toBe('/profile')
    expect(state.ensure).not.toHaveBeenCalled()
  })

  it('reads the learner: only a block that says "not completed" sends to the onboarding', () => {
    expect(needsOnboarding(null)).toBe(false)
    expect(needsOnboarding(learner(undefined))).toBe(false)
    expect(needsOnboarding(learner({ completed: true, step: null }))).toBe(false)
    expect(needsOnboarding(learner({ completed: false, step: 'goals' }))).toBe(true)
    expect(onboardingLocation('unknown')).toEqual({ name: 'onboarding' })
    expect(onboardingLocation('reminder')).toEqual({ name: 'onboarding', params: { step: 'reminder' } })
  })

  it('has the route of the app: lazy, no tab bar, the step as an optional parameter', () => {
    const record = appRouter.getRoutes().find((r) => r.name === 'onboarding')

    expect(record?.path).toBe('/onboarding/:step?')
    expect(record?.meta.tab).toBeUndefined()
    expect(typeof record?.components?.default).toBe('function')
    expect(appRouter.resolve('/onboarding/level').name).toBe('onboarding')
    expect(appRouter.resolve('/onboarding').name).toBe('onboarding')
  })
})
