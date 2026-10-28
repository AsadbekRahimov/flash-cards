<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'

/**
 * "Nothing here yet": an icon, a short text and an optional action.
 *
 * - `icon` — Heroicons name shown in a circle above the text.
 * - `title` — optional bold line above `text`.
 * - `text` — the explanation, already localized.
 * - slot `action` — the button that fixes the emptiness (`btn-tinted`, e.g. "Choose a course").
 *
 * `data-testid="empty"` on the root (a parent's `data-testid` replaces it).
 *
 * @example
 * <EmptyState icon="rectangle-stack" :text="t('decks.empty')">
 *   <template #action><button class="btn btn-tinted">…</button></template>
 * </EmptyState>
 */
defineProps<{
  icon?: IconName
  title?: string
  text: string
}>()
</script>

<template>
  <div class="flex flex-col items-center gap-2 px-6 py-10 text-center" data-testid="empty">
    <span v-if="icon" class="mb-1 flex size-14 items-center justify-center rounded-full bg-track text-tg-hint">
      <AppIcon :name="icon" :size="28" />
    </span>
    <h2 v-if="title" class="text-[17px] leading-[22px] font-semibold">{{ title }}</h2>
    <p class="text-[15px] leading-5 text-tg-hint">{{ text }}</p>
    <div v-if="$slots.action" class="mt-3">
      <slot name="action" />
    </div>
  </div>
</template>
