<script setup lang="ts">
/**
 * Chip of the kit Muhrla (COMPONENTS.md "Chip"): 26 px, a pill, Label/S; meta only (a level, a state). The `.chip` of the design system.
 *
 * - `tone` — `neutral` (default: the sunken paper), `accent` (the soft accent), `success`, `ink` (the ink with the light text).
 *
 * @example <AppChip tone="ink">A1–A2</AppChip>
 */
withDefaults(defineProps<{ tone?: 'neutral' | 'accent' | 'success' | 'ink' }>(), { tone: 'neutral' })
</script>

<template>
  <span class="chip" :class="`chip-${tone}`" :data-tone="tone"><slot /></span>
</template>
