<script setup lang="ts">
import { computed } from 'vue'
import { ICONS, type IconName, type IconPath } from './icons'

/**
 * Icon from the vendored Heroicons set (`./icons.ts`); it takes the colour of the surrounding text (`currentColor`).
 *
 * - `name` — Heroicons name (kebab-case, typed by `IconName`).
 * - `solid` — filled style (active tab, statuses); default is the 1.5px outline.
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

const paths = computed<readonly IconPath[]>(() => ICONS[props.name][props.solid ? 'solid' : 'outline'])
</script>

<template>
  <svg
    viewBox="0 0 24 24"
    :width="size"
    :height="size"
    class="inline-block shrink-0"
    aria-hidden="true"
    focusable="false"
    :fill="solid ? 'currentColor' : 'none'"
    :stroke="solid ? undefined : 'currentColor'"
    :stroke-width="solid ? undefined : 1.5"
    :stroke-linecap="solid ? undefined : 'round'"
    :stroke-linejoin="solid ? undefined : 'round'"
  >
    <path v-for="(path, index) in paths" :key="index" :d="path.d" :fill-rule="path.fillRule" :clip-rule="path.clipRule" />
  </svg>
</template>
