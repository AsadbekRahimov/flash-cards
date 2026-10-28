<script setup lang="ts">
/**
 * Screen heading (docs/14 §4.2): the title and an optional subtitle, with room on the right for a chip or a button.
 *
 * - `title` — the screen name.
 * - `subtitle` — a line under it (a date, a hint).
 * - `large` — 28px for a tab root; without it 22px for an inner screen.
 * - slot `trailing` — content aligned to the right of the title (e.g. a plan chip).
 *
 * @example
 * <PageHeader :title="t('nav.profile')" large>
 *   <template #trailing><span class="chip chip-accent">Pro</span></template>
 * </PageHeader>
 */
defineProps<{
  title: string
  subtitle?: string
  large?: boolean
}>()
</script>

<template>
  <header class="flex items-start justify-between gap-3 pt-1 pb-3">
    <div class="min-w-0">
      <h1
        class="font-bold"
        :class="large ? 'text-[28px] leading-[34px] tracking-tight' : 'text-[22px] leading-7'"
      >
        {{ title }}
      </h1>
      <p v-if="subtitle" class="mt-0.5 text-[15px] leading-5 text-tg-subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.trailing" class="shrink-0 pt-1">
      <slot name="trailing" />
    </div>
  </header>
</template>
