<script setup lang="ts">
/**
 * The sticky bottom block of the immersive screens (training, exam result; docs/14 §6.2): the colour of the screen under it,
 * so the content that scrolls under it stays readable, with the home-indicator inset below (`--app-safe-b`). Sits at the
 * bottom of a column that fills the screen (`flex flex-1 flex-col`), and sticks to the bottom edge of the viewport
 * when the content is taller than the screen.
 *
 * - `surface` — the screen is white (`bg-surface`, as the lesson and Pro mock-ups): the block takes the card colour instead of
 *   the page colour.
 *
 * Put the buttons of the screen in the default slot (they are stacked with a gap): one `btn-primary`; a second action is a
 * text button (`btn-plain`) under it, or beside it in a row of your own. `data-testid="actions"`.
 *
 * The block covers the bottom edge of the window, where the toast stands (a refusal such as "No connection"): while it is on
 * screen it publishes its height (`--app-actions-h`, see `trackActionsHeight`) and the toast stands on it, not on the buttons.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { trackActionsHeight } from '@/composables/stickyInset'

defineProps<{ surface?: boolean }>()

const root = ref<HTMLElement | null>(null)
let stopTracking: (() => void) | null = null

onMounted(() => {
  if (root.value) stopTracking = trackActionsHeight(root.value)
})
onBeforeUnmount(() => {
  stopTracking?.()
  stopTracking = null
})
</script>

<template>
  <div
    ref="root"
    class="sticky bottom-0 z-10 mt-auto flex flex-col gap-1.5 px-5 pt-3"
    :class="surface ? 'bg-surface' : 'bg-page'"
    style="padding-bottom: calc(0.75rem + var(--app-safe-b))"
    data-testid="actions"
  >
    <slot />
  </div>
</template>
