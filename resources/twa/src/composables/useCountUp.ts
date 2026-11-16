import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'
import { prefersReducedMotion } from '@/composables/motion'

export const COUNT_UP_MS = 600

/**
 * A number that "counts up" from 0 to `target` once, when the screen opens (the tiles of the finish screen). With reduced
 * motion, or without `requestAnimationFrame`, it is the target from the start. Only the shown number is animated: the
 * value itself is known at once.
 */
export function useCountUp(target: () => number, duration: number = COUNT_UP_MS): Ref<number> {
  const shown = ref(target())
  let frame: number | null = null

  function stop(): void {
    if (frame !== null && typeof cancelAnimationFrame === 'function') cancelAnimationFrame(frame)
    frame = null
  }

  onMounted(() => {
    const end = target()
    if (end <= 0 || prefersReducedMotion() || typeof requestAnimationFrame !== 'function') return
    const startedAt = performance.now()
    shown.value = 0
    const tick = (): void => {
      const progress = Math.min(1, (performance.now() - startedAt) / duration)
      // ease-out cubic: fast at first, settles on the number
      shown.value = progress >= 1 ? target() : Math.round(target() * (1 - (1 - progress) ** 3))
      frame = progress >= 1 ? null : requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
  })

  onBeforeUnmount(stop)

  return shown
}
