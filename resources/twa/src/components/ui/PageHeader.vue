<script setup lang="ts">
/**
 * Screen heading (docs/14 §4.2): the title, an optional line above it and an optional line under it, with room on the right for
 * a badge or a button.
 *
 * - `title` — the screen name; semibold serif.
 * - `eyebrow` — a short line ABOVE the title (13 px, secondary colour): the date and the course, the week of the league.
 * - `subtitle` — a line UNDER the title (13 px, secondary colour): a count, a hint.
 * - `large` — 26 px for a tab root; without it 22 px for an inner screen.
 * - slot `trailing` — content aligned to the right of the title (e.g. a plan badge).
 *
 * @example
 * <PageHeader :eyebrow="`${date} · ${course}`" :title="t('today.greeting', { name })" large />
 * <PageHeader :title="t('practice.hub.title')" :subtitle="t('practice.hub.words', { count })" />
 */
defineProps<{
  title: string
  eyebrow?: string
  subtitle?: string
  large?: boolean
}>()
</script>

<template>
  <header class="flex items-start justify-between gap-3 pt-3.5 pb-[18px]">
    <div class="min-w-0">
      <p v-if="eyebrow" class="mb-1 text-[13px] leading-[18px] text-muted" data-testid="eyebrow">{{ eyebrow }}</p>
      <h1
        class="font-serif font-semibold"
        :class="large ? 'text-[26px] leading-[30px] tracking-[-0.2px]' : 'text-[22px] leading-7'"
      >
        {{ title }}
      </h1>
      <p v-if="subtitle" class="mt-1 text-[13px] leading-[18px] text-muted" data-testid="subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.trailing" class="shrink-0 pt-1">
      <slot name="trailing" />
    </div>
  </header>
</template>
