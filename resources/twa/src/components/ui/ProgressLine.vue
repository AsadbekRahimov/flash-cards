<script setup lang="ts">
import { computed } from 'vue'

/**
 * A thin progress line (kit Muhrla ProgressLine): a 4 px sunken track with the fill in a colour of the palette —
 * the `.progress` / `.progress-fill` pair of `style.css`, with the share clamped and the accessibility settled in one place.
 *
 * - `value` — the share in per cent; clamped to 0..100 and rounded.
 * - `tone` — `accent` (default: the daily goal, the league, the course - the fill in ink, as in the kit), `primary` (the header of a
 *   lesson, ink as well), `success` (words mastered), `muhr` (the progress of an achievement, a current segment).
 * - `label` — what the line measures, already localized. With it the line is a `role="progressbar"` with its value; without it
 *   the line is decoration (`aria-hidden`), for when the same number is written next to it in words.
 *
 * `class` (`flex-1`, `mt-3`, `h-[3px]` for the 3 px line of a lesson header), `data-testid` and other attributes go to the track.
 *
 * @example <ProgressLine :value="goalPercent" :label="t('today.goal.title')" />
 * @example <ProgressLine :value="percent" tone="primary" class="h-[3px] flex-1" />
 */
const props = withDefaults(defineProps<{ value: number; tone?: 'accent' | 'primary' | 'success' | 'muhr'; label?: string }>(), {
  tone: 'accent',
  label: undefined,
})

const percent = computed(() => (Number.isFinite(props.value) ? Math.round(Math.min(100, Math.max(0, props.value))) : 0))
</script>

<template>
  <div
    class="progress"
    :role="label ? 'progressbar' : undefined"
    :aria-label="label"
    :aria-valuenow="label ? percent : undefined"
    :aria-valuemin="label ? 0 : undefined"
    :aria-valuemax="label ? 100 : undefined"
    :aria-hidden="label ? undefined : 'true'"
  >
    <div class="progress-fill" :class="tone === 'accent' ? undefined : `progress-${tone}`" :style="{ width: `${percent}%` }" />
  </div>
</template>
