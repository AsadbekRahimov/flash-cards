<script setup lang="ts">
import { useI18n } from '@/i18n'

/**
 * Loading placeholder shown instead of a screen's content (replaces the "Loading…" text).
 *
 * - `variant="cards"` (default) — skeleton cards, for screens made of cards;
 * - `variant="list"` — a skeleton grouped list, for list screens;
 * - `variant="center"` — a spinner in the middle of the free space, for a screen with one block of content.
 *
 * Announced as `role="status"` with the hidden text `common.loading`; `data-testid="spinner"` (a parent's
 * `data-testid` replaces it).
 *
 * @example <LoadingState v-if="status === 'loading'" variant="list" />
 */
withDefaults(defineProps<{ variant?: 'cards' | 'list' | 'center' }>(), { variant: 'cards' })

const { t } = useI18n()
</script>

<template>
  <div
    role="status"
    aria-busy="true"
    data-testid="spinner"
    :class="variant === 'center' ? 'flex flex-1 items-center justify-center py-10' : 'space-y-3'"
  >
    <span class="sr-only">{{ t('common.loading') }}</span>

    <span
      v-if="variant === 'center'"
      class="size-8 animate-spin rounded-full border-[3px] border-track border-t-accent motion-reduce:animate-none"
      aria-hidden="true"
    />

    <div v-else-if="variant === 'list'" class="list" aria-hidden="true">
      <div v-for="row in 5" :key="row" class="list-row list-row-icon">
        <span class="skeleton size-[30px] shrink-0 rounded-[9px]" />
        <span class="flex-1 space-y-2">
          <span class="skeleton block h-4 w-2/3" />
          <span v-if="row % 2 === 1" class="skeleton block h-3 w-1/3" />
        </span>
      </div>
    </div>

    <template v-else>
      <div v-for="card in 3" :key="card" class="card space-y-3" aria-hidden="true">
        <div class="skeleton h-5 w-1/2" />
        <div class="skeleton h-4 w-full" />
        <div class="skeleton h-4 w-3/4" />
      </div>
    </template>
  </div>
</template>
