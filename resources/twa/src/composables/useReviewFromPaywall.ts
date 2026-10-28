import { useRouter } from 'vue-router'
import { showErrorToast } from '@/composables/errorToast'
import { useTrainingStore } from '@/stores/training'

export interface ReviewFromPaywallOptions {
  /**
   * Show a failed open as a toast and forget it (screens where the message under the main button would be out of
   * sight). Without it the failure stays in `training.openError`, which the screen draws itself (Home, Finished).
   */
  toastErrors?: boolean
}

/**
 * "Repeat words" of the paywall sheet (`@review`): reviews are never limited on Free, so a wall in front of new words
 * is not a dead end. Closes the sheet first, then opens a personal daily review and goes to the training screen.
 * A refused open leaves the learner where they are.
 *
 * One handler for every screen that shows `PaywallSheet`: Home, Finished, Course, My decks, Deck, Subscription.
 *
 * @param close hides the sheet of the calling screen (each screen keeps its own flag)
 *
 * @example
 * const { reviewFromPaywall } = useReviewFromPaywall(() => (paywallOpen.value = false))
 * // <PaywallSheet v-if="paywallOpen" @close="paywallOpen = false" @review="reviewFromPaywall" />
 */
export function useReviewFromPaywall(close: () => void, options: ReviewFromPaywallOptions = {}) {
  const router = useRouter()
  const training = useTrainingStore()

  async function reviewFromPaywall(): Promise<void> {
    close()
    const sessionId = await training.open({ mode: 'daily_review', source: 'self' })
    if (sessionId !== null) {
      await router.push({ name: 'training', params: { sessionId } })
    } else if (options.toastErrors && training.openError !== '') {
      showErrorToast(training.openError)
      training.clearOpenError()
    }
  }

  return { reviewFromPaywall }
}
