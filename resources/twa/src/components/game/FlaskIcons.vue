<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/i18n'

/**
 * The flasks of a run: `max` of them, the first `flasks` full, the rest empty (an outline). A flask is a state, so it is told by shape
 * (filled / outline) and, for a screen reader, by one sentence on the whole row ("Flasks: 2 of 3"); the icons are decorative.
 * The beaker is the Heroicons v2.2.0 `beaker` (MIT, see `components/ui/icons.ts`), drawn here so that the shared icon set stays as it is.
 */
const props = defineProps<{ flasks: number; max: number }>()

const { t } = useI18n()

const cells = computed((): boolean[] => Array.from({ length: Math.max(0, props.max) }, (_, index) => index < props.flasks))
const label = computed((): string => t('gameRun.flasks', { left: Math.max(0, props.flasks), max: props.max }))

const OUTLINE =
  'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5'
const SOLID =
  'M10.5 3.798v5.02a3 3 0 0 1-.879 2.121l-2.377 2.377a9.845 9.845 0 0 1 5.091 1.013 8.315 8.315 0 0 0 5.713.636l.285-.071-3.954-3.955a3 3 0 0 1-.879-2.121v-5.02a23.614 23.614 0 0 0-3 0Zm4.5.138a.75.75 0 0 0 .093-1.495A24.837 24.837 0 0 0 12 2.25a25.048 25.048 0 0 0-3.093.191A.75.75 0 0 0 9 3.936v4.882a1.5 1.5 0 0 1-.44 1.06l-6.293 6.294c-1.62 1.621-.903 4.475 1.471 4.88 2.686.46 5.447.698 8.262.698 2.816 0 5.576-.239 8.262-.697 2.373-.406 3.092-3.26 1.47-4.881L15.44 9.879A1.5 1.5 0 0 1 15 8.818V3.936Z'
</script>

<template>
  <span class="flex items-center gap-0.5" role="img" :aria-label="label" data-testid="flasks">
    <svg
      v-for="(full, index) in cells"
      :key="index"
      viewBox="0 0 24 24"
      width="22"
      height="22"
      class="shrink-0"
      :class="full ? 'text-accent' : 'text-tg-hint'"
      aria-hidden="true"
      focusable="false"
      :data-full="full ? 'true' : 'false'"
      data-testid="flask"
      :fill="full ? 'currentColor' : 'none'"
      :stroke="full ? undefined : 'currentColor'"
      :stroke-width="full ? undefined : 1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path :d="full ? SOLID : OUTLINE" :fill-rule="full ? 'evenodd' : undefined" :clip-rule="full ? 'evenodd' : undefined" />
    </svg>
  </span>
</template>
