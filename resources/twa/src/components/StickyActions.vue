<script setup lang="ts">
/**
 * The sticky bottom block of the immersive screens (training, exam result; docs/14 §6.2): the colour of the screen under it,
 * so the content that scrolls under it stays readable, with the home-indicator inset below (`--app-safe-b`). Sits at the
 * bottom of a column that fills the screen (`flex flex-1 flex-col`), and sticks to the bottom edge of the viewport
 * when the content is taller than the screen. The zone of the kit Muhrla (DESIGN.md §7, TELEGRAM.md `.bottom-cta`): 12 px above,
 * 20 px at the sides, 12 px and the home indicator below.
 *
 * - `surface` — a board shared by the lesson screens (the practice boards, the tasks of the game, the room and result of a battle): the
 *   block takes the colour the screen names with the CSS variable `--app-screen` on its root. Every such screen (lesson, exam, practice,
 *   battle, cup round, game run) stands on the kit's canvas and names `var(--app-page)`; without the variable the block falls back to
 *   the card colour.
 * - `tone` — the feedback sheet of a lesson (COMPONENTS.md "Feedback sheet", ANIMATIONS.md §5): the soft success / error colour, the top
 *   corners 28, padding 20, and it slides up from the bottom edge (260 ms) without dimming the screen - the question stays in view.
 *
 * Put the buttons of the screen in the default slot (they are stacked with a gap): one `btn-primary`; a second action is a
 * text button (`btn-plain`) under it, or beside it in a row of your own. `data-testid="actions"`.
 *
 * The block covers the bottom edge of the window, where the toast stands (a refusal such as "No connection"): while it is on
 * screen it publishes its height (`--app-actions-h`, see `trackActionsHeight`) and the toast stands on it, not on the buttons.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { trackActionsHeight } from '@/composables/stickyInset'

const props = defineProps<{ surface?: boolean; tone?: 'success' | 'danger' }>()

const root = ref<HTMLElement | null>(null)
let stopTracking: (() => void) | null = null

const look = computed((): string => {
  if (props.tone === 'success') return 'rounded-t-xl bg-success-soft px-5 pt-5'
  if (props.tone === 'danger') return 'rounded-t-xl bg-danger-soft px-5 pt-5'
  return props.surface ? 'bg-[var(--app-screen,var(--app-surface))] px-5 pt-3' : 'bg-page px-5 pt-3'
})

/** The sheet slides up once, when it appears (transform only; none under `prefers-reduced-motion`). */
function slideIn(el: HTMLElement): void {
  if (typeof el.animate !== 'function') return
  if (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  el.animate([{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }], { duration: 260, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)' })
}

onMounted(() => {
  if (!root.value) return
  stopTracking = trackActionsHeight(root.value)
  if (props.tone) slideIn(root.value)
})
onBeforeUnmount(() => {
  stopTracking?.()
  stopTracking = null
})
</script>

<template>
  <div
    ref="root"
    class="sticky bottom-0 z-10 mt-auto flex flex-col gap-1.5"
    :class="look"
    style="padding-bottom: calc(0.75rem + var(--app-safe-b))"
    :data-tone="tone"
    data-testid="actions"
  >
    <slot />
  </div>
</template>
