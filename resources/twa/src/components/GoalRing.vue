<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  done: number
  target: number
  met: boolean
}>()

const RADIUS = 42
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

/** Share of the goal done, 0..1 (a met goal is a full ring even when `target` is odd). */
const fraction = computed((): number => {
  if (props.met) return 1
  return props.target > 0 ? Math.min(1, props.done / props.target) : 0
})
</script>

<template>
  <div class="relative h-28 w-28" data-testid="goal-ring" :data-met="met ? 'true' : 'false'">
    <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90" aria-hidden="true">
      <circle cx="50" cy="50" :r="RADIUS" fill="none" stroke="currentColor" stroke-opacity="0.12" stroke-width="9" />
      <circle
        cx="50"
        cy="50"
        :r="RADIUS"
        fill="none"
        stroke="var(--tg-button)"
        stroke-width="9"
        stroke-linecap="round"
        :stroke-dasharray="CIRCUMFERENCE"
        :stroke-dashoffset="CIRCUMFERENCE * (1 - fraction)"
        data-testid="goal-ring-arc"
      />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center">
      <span class="text-2xl font-bold" data-testid="goal-done">{{ done }}</span>
      <span class="text-xs text-tg-hint">/ {{ target }}</span>
    </div>
  </div>
</template>
