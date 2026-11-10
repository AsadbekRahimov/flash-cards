import { computed, ref, type Ref } from 'vue'

/**
 * "Say the word aloud" (docs/25 §3.11, docs/06 §12): a quiet line next to the speaker of a flash / audio card, on every
 * {@see SAY_ALOUD_EVERY}-th card the learner sees — the production effect is stronger when only a part of the words is
 * read aloud — and never when the learner switched it off in the settings. Client-only: nothing is sent to the server.
 *
 * The counter and the switch live in `localStorage` (the project's only client store); a storage that is not available
 * (private mode) leaves the hint off for the visit rather than breaking the card.
 */
export const SAY_ALOUD_EVERY = 3

export const SAY_ALOUD_KEY = 'lexiflow.say_aloud'

const COUNTER_KEY = 'lexiflow.say_aloud.count'

const enabled = ref<boolean>(readEnabled())

function readEnabled(): boolean {
  try {
    return window.localStorage.getItem(SAY_ALOUD_KEY) !== 'off'
  } catch {
    return false
  }
}

export function isSayAloudEnabled(): boolean {
  return enabled.value
}

export function setSayAloudEnabled(on: boolean): void {
  enabled.value = on
  try {
    if (on) window.localStorage.removeItem(SAY_ALOUD_KEY)
    else window.localStorage.setItem(SAY_ALOUD_KEY, 'off')
  } catch {
    // the in-memory switch holds for this visit
  }
}

/** Advances the card counter and says whether this card is one of the "say it aloud" cards. */
export function takeSayAloudTurn(): boolean {
  if (!enabled.value) return false
  try {
    const next = (Number(window.localStorage.getItem(COUNTER_KEY)) || 0) + 1
    window.localStorage.setItem(COUNTER_KEY, String(next))
    return next % SAY_ALOUD_EVERY === 0
  } catch {
    return false
  }
}

/** For tests: forgets the counter and the switch. */
export function resetSayAloud(): void {
  enabled.value = true
  try {
    window.localStorage.removeItem(SAY_ALOUD_KEY)
    window.localStorage.removeItem(COUNTER_KEY)
  } catch {
    // nothing to forget
  }
}

/**
 * Decided once per mounted card (a new serve is a new component): whether the hint is shown.
 */
export function useSayAloudHint(): { show: Ref<boolean>; enabled: Ref<boolean> } {
  const show = ref(takeSayAloudTurn())

  return { show: computed(() => show.value && enabled.value), enabled }
}
