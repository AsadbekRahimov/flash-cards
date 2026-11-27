import type { RouteLocationNormalized, RouteLocationRaw, Router } from 'vue-router'
import { ONBOARDING_STEPS, type OnboardingStep } from '@/api/onboardingTypes'
import type { Learner } from '@/api/types'
import { readStartParam } from '@/router/startParam'
import { useAuthStore } from '@/stores/auth'
import { useTelegram } from '@/composables/useTelegram'

/**
 * Who sees the onboarding of the Mini App and where it starts (docs/40 §2.1 "Когда показывать").
 *
 * The roots of the four tabs are the only screens it stands in front of: a learner whose `onboarding.completed` (`/auth`) is false is
 * sent to the onboarding, at the step the server keeps (`onboarding.step`). Never intercepted:
 * - a learner who has finished it (in the bot or here) - and an older server without the `onboarding` block;
 * - an open by a deep link (`start_param`: a battle, a cup, an assignment, a session) - for the whole open;
 * - a link with `?from=` (a reminder of the bot, an assignment): it names what to do on "Today";
 * - any screen that is not the root of a tab (a lesson, a battle, the onboarding itself).
 */

/** The screens the tab bar leads to. */
export const TAB_ROOTS: readonly string[] = ['today', 'my-course', 'league', 'profile']

/** The screen of a step: `null`, or the first step that nobody has answered yet - O1 "Welcome"; a step the client does not know - O1 too. */
export function onboardingLocation(step: string | null | undefined): RouteLocationRaw {
  const known: OnboardingStep | null = typeof step === 'string' && (ONBOARDING_STEPS as readonly string[]).includes(step) ? (step as OnboardingStep) : null
  return known === null || known === 'name' ? { name: 'onboarding' } : { name: 'onboarding', params: { step: known } }
}

/** Whether this learner is to go through the onboarding: the server says so; no block (an older server) - no. */
export function needsOnboarding(learner: Learner | null | undefined): boolean {
  return learner?.onboarding !== undefined && learner.onboarding.completed === false
}

/** Whether the guard looks at this navigation at all (before the learner is known). */
function guarded(to: RouteLocationNormalized): boolean {
  return typeof to.name === 'string' && TAB_ROOTS.includes(to.name) && to.query.from === undefined
}

export interface OnboardingGuardOptions {
  /** `start_param` of the launch (tests); by default the one of Telegram. */
  readStart?: () => string | null
}

/**
 * Puts the guard in front of the roots of the tabs. When the learner is not known yet (the first screen of the open), the guard waits
 * for `/auth` - the screen would ask for it first anyway - so a newcomer does not see "Today" flash and its requests go. A failed `/auth`
 * lets the screen through: it has its own state for that.
 */
export function installOnboardingGuard(router: Router, options: OnboardingGuardOptions = {}): void {
  let deepLinked: boolean | null = null

  router.beforeEach(async (to) => {
    if (!guarded(to)) return true
    deepLinked ??= (options.readStart ?? readStartParam)() !== null
    if (deepLinked) return true

    let auth: ReturnType<typeof useAuthStore>
    try {
      auth = useAuthStore()
    } catch {
      // No Pinia (a test of the router alone): nothing to decide by.
      return true
    }
    if (auth.learner === null) {
      if (!useTelegram().isInTelegram()) return true
      try {
        await auth.ensure()
      } catch {
        return true
      }
    }
    if (!needsOnboarding(auth.learner)) return true

    const target = router.resolve(onboardingLocation(auth.learner?.onboarding?.step))
    return { path: target.path, replace: true }
  })
}
