<script setup lang="ts">
import { computed } from 'vue'
import { ICONS, type IconName, type IconPath, type IconSet } from './icons'

/**
 * Icon of the vendored set (`./icons.ts`: Lucide of the kit 2026-11-27, a few earlier Heroicons drawings); it takes the colour of
 * the surrounding text (`currentColor`).
 *
 * - `name` — the name of the icon (kebab-case, typed by `IconName`).
 * - `solid` — the filled style (statuses: a check in a circle, a star); an icon that has no filled drawing gets its outline with a
 *   2 px stroke (the active tab of the kit). Default: the outline with the 1.75 stroke of the kit.
 * - `size` — width and height in px, default 20.
 *
 * Decorative (`aria-hidden`): give the control around it a text or an `aria-label`.
 *
 * @example <AppIcon name="trophy" solid :size="24" class="text-warning" />
 */
const props = withDefaults(
  defineProps<{
    name: IconName
    solid?: boolean
    size?: number
  }>(),
  { solid: false, size: 20 },
)

const set = computed<IconSet>(() => ICONS[props.name] as IconSet)

/** Filled: `solid` asked for and the icon has a filled drawing. */
const filled = computed(() => props.solid && set.value.solid !== undefined)

const paths = computed<readonly IconPath[]>(() => (filled.value ? (set.value.solid ?? []) : set.value.outline))
</script>

<template>
  <svg
    viewBox="0 0 24 24"
    :width="size"
    :height="size"
    class="inline-block shrink-0"
    aria-hidden="true"
    focusable="false"
    :fill="filled ? 'currentColor' : 'none'"
    :stroke="filled ? undefined : 'currentColor'"
    :stroke-width="filled ? undefined : solid ? 2 : 1.75"
    :stroke-linecap="filled ? undefined : 'round'"
    :stroke-linejoin="filled ? undefined : 'round'"
  >
    <path v-for="(path, index) in paths" :key="index" :d="path.d" :fill-rule="path.fillRule" :clip-rule="path.clipRule" />
  </svg>
</template>
