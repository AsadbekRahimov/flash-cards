<script setup lang="ts">
/**
 * The sticky bottom block of the immersive screens (training, exam result; docs/14 §6.2): page-coloured, so the
 * content that scrolls under it stays readable, with the home-indicator inset below (`--app-safe-b`). Sits at the
 * bottom of a column that fills the screen (`flex flex-1 flex-col`), and sticks to the bottom edge of the viewport
 * when the content is taller than the screen.
 *
 * Put the buttons of the screen in the default slot (they are stacked with a gap). `data-testid="actions"`.
 */
</script>

<template>
  <div
    class="sticky bottom-0 z-10 mt-auto flex flex-col gap-2 bg-page px-4 pt-3"
    style="padding-bottom: calc(0.75rem + var(--app-safe-b))"
    data-testid="actions"
  >
    <slot />
  </div>
</template>
