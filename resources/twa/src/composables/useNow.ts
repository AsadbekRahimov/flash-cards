import { getCurrentScope, onScopeDispose, readonly, ref, type Ref } from 'vue'

/**
 * The device clock as a ref that moves (epoch milliseconds, like `Date.now()`), so that a countdown on screen redraws
 * by itself: the timer of a round, "Next round in 3…", "Invitation expires in 4:12". It ticks every `intervalMs` (250 ms by
 * default: a second shown late by a quarter of a second is not noticed, a second skipped is) for as long as the
 * component or effect scope that called it lives.
 *
 * The deadlines of a battle are the server's: the countdown reads `deadline − (now + offset)` (see `remainingMs`).
 *
 * @example
 * const now = useNow()
 * const left = computed(() => remainingMs(round.deadline_at, now.value, battle.serverOffsetMs))
 */
export function useNow(intervalMs = 250): Readonly<Ref<number>> {
  const now = ref(Date.now())
  const handle = setInterval(() => {
    now.value = Date.now()
  }, intervalMs)
  if (getCurrentScope()) onScopeDispose(() => clearInterval(handle))
  return readonly(now)
}
