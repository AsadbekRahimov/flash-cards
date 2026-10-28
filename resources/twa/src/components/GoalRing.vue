<script setup lang="ts">
import { computed } from 'vue'

/**
 * Ring of the daily goal (88 px): `done` in the middle, `/ target` under it. The track is `--app-track`, the arc is
 * the accent colour and turns green once the goal is `met`. A met goal is a full ring even when `done` overshoots
 * the target (or `target` is odd); a ring with nothing done draws no arc (a round cap would leave a dot).
 */
const props = defineProps<{
  done: number
  target: number
  met: boolean
}>()

const RADIUS = 42
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

/** Share of the goal done, 0..1. */
const fraction = computed((): number => {
  if (props.met) return 1
  return props.target > 0 ? Math.min(1, props.done / props.target) : 0
})
</script>

<template>
  <div class="relative size-22 shrink-0" data-testid="goal-ring" :data-met="met ? 'true' : 'false'">
    <svg viewBox="0 0 100 100" class="size-full -rotate-90" aria-hidden="true">
      <circle cx="50" cy="50" :r="RADIUS" fill="none" stroke-width="10" class="stroke-track" />
      <circle
        v-if="fraction > 0"
        cx="50"
        cy="50"
        :r="RADIUS"
        fill="none"
        stroke-width="10"
        stroke-linecap="round"
        :class="met ? 'stroke-success' : 'stroke-accent'"
        :stroke-dasharray="CIRCUMFERENCE"
        :stroke-dashoffset="CIRCUMFERENCE * (1 - fraction)"
        data-testid="goal-ring-arc"
      />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center">
      <span class="text-2xl leading-7 font-bold" data-testid="goal-done">{{ done }}</span>
      <span class="text-xs leading-4 text-tg-hint">/ {{ target }}</span>
    </div>
  </div>
</template>
