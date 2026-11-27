<script setup lang="ts">
import { ref } from 'vue'
import LanguageSheet from '@/components/LanguageSheet.vue'
import { BRAND } from '@/brand'
import { LANGUAGE_TITLE, useI18n } from '@/i18n'
import AppIcon from './AppIcon.vue'

/**
 * Screen heading (docs/14 §4.2, docs/design/2026-11-25-redesign): the title, an optional line above it and an optional line under
 * it, with room on the right for a badge or a button; on a tab root, the brand row of the mock-ups above it all.
 *
 * - `title` — the screen name; Unbounded (Display/L of the kit when `large`, Display/M - 22 px - otherwise).
 * - `eyebrow` — a short line ABOVE the title (13 px, secondary colour): the date and the course, the week of the league.
 * - `subtitle` — a line UNDER the title (13 px, secondary colour): a count, a hint.
 * - `large` — 30 px (Display/L) for a tab root; without it 22 px for an inner screen.
 * - `brand` — the row of the tab roots: the logo and the product name (`BRAND`, src/brand.ts) on the left, the language pill
 *   ("RU" / "UZ") on the right, which opens the language sheet (`LanguageSheet`). Off by default: a screen turns it on when it
 *   drops its own language button.
 * - slot `trailing` — content aligned to the right of the title (e.g. a plan badge).
 *
 * @example
 * <PageHeader brand :eyebrow="`${date} · ${course}`" :title="t('today.greeting', { name })" large />
 * <PageHeader :title="t('practice.hub.title')" :subtitle="t('practice.hub.words', { count })" />
 */
defineProps<{
  title: string
  eyebrow?: string
  subtitle?: string
  large?: boolean
  brand?: boolean
}>()

const { locale } = useI18n()
const languageOpen = ref(false)
</script>

<template>
  <header class="pb-[18px]" :class="brand ? 'pt-2.5' : 'pt-3.5'">
    <div v-if="brand" class="mb-4 flex items-center justify-between gap-3" data-testid="brand-row">
      <div class="flex min-w-0 items-center gap-2">
        <!-- The logo of the mock-ups: an open book with lines of text, in the accent. -->
        <svg
          aria-hidden="true"
          width="34"
          height="30"
          viewBox="0 0 34 30"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="shrink-0 text-accent"
          data-testid="brand-logo"
        >
          <path d="M17 7C13 4 8 3 3 3v19c5 0 10 1 14 4 4-3 9-4 14-4V3c-5 0-10 1-14 4zM17 7v19" />
          <path d="M7 9c2 0 4 .5 6 1.5M7 14c2 0 4 .5 6 1.5M27 9c-2 0-4 .5-6 1.5M27 14c-2 0-4 .5-6 1.5" stroke-width="1.5" />
        </svg>
        <span class="truncate font-display text-[20px] leading-7 font-semibold tracking-[-0.02em]" data-testid="brand-name">{{ BRAND }}</span>
      </div>
      <!-- A 36 px pill; the invisible layer of .tap-target makes the touch area 44 px. -->
      <button
        type="button"
        class="tap-target flex h-9 shrink-0 items-center gap-1 rounded-full bg-sunken px-3 text-sm font-semibold text-body"
        :aria-label="LANGUAGE_TITLE"
        aria-haspopup="dialog"
        data-testid="lang-btn"
        @click="languageOpen = true"
      >
        {{ locale.toUpperCase() }}
        <AppIcon name="chevron-down" :size="14" />
      </button>
    </div>

    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p v-if="eyebrow" class="mb-1 text-[13px] leading-[18px] text-muted" data-testid="eyebrow">{{ eyebrow }}</p>
        <h1 :class="large ? 't-display-l' : 'font-display text-[22px] leading-7 font-semibold tracking-[-0.02em]'">
          {{ title }}
        </h1>
        <p v-if="subtitle" class="mt-1 text-[13px] leading-[18px] text-muted" data-testid="subtitle">{{ subtitle }}</p>
      </div>
      <div v-if="$slots.trailing" class="shrink-0 pt-1">
        <slot name="trailing" />
      </div>
    </div>

    <LanguageSheet v-if="languageOpen" @close="languageOpen = false" />
  </header>
</template>
