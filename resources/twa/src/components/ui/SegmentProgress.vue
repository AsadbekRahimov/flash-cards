<script setup lang="ts">
import { computed } from 'vue'

/**
 * The segments of a lesson or of the onboarding (kit Muhrla, "Progress segmentlari"): `total` equal segments, 6 px, radius 3; the ones
 * passed in ink, the current one in the muhr colour, the rest on the sunken paper (`.segments` / `.segment`).
 *
 * - `total` — the number of segments (at least 1); `current` — the index of the current one, 0-based (`total` and more: all passed).
 * - `label` — what it measures, localized: with it the row is a `progressbar` ("2 of 5"); without it, decoration.
 *
 * @example <SegmentProgress :total="10" :current="index" :label="t('exercise.progress')" />
 */
const props = defineProps<{ total: number; current: number; label?: string }>()

const count = computed(() => Math.max(1, Math.floor(props.total)))
const at = computed(() => Math.min(count.value, Math.max(0, Math.floor(props.current))))

function stateOf(index: number): 'done' | 'current' | 'todo' {
  if (index < at.value) return 'done'
  return index === at.value ? 'current' : 'todo'
}
</script>

<template>
  <div
    class="segments"
    :role="label ? 'progressbar' : undefined"
    :aria-label="label"
    :aria-valuenow="label ? at : undefined"
    :aria-valuemin="label ? 0 : undefined"
    :aria-valuemax="label ? count : undefined"
    :aria-hidden="label ? undefined : 'true'"
    data-testid="segments"
  >
    <span v-for="index in count" :key="index" class="segment" :data-state="stateOf(index - 1)" />
  </div>
</template>
