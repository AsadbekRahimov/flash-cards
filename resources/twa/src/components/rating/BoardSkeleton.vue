<script setup lang="ts">
import { useI18n } from '@/i18n'

/**
 * The skeleton of a rating pane (Month, All time) while its first answer is on its way (ANIMATIONS.md §6: sunken blocks pulsing): the big
 * card of my place, then the rows of the table — the place, the 32 px avatar, the name, the points of a LeagueRow.
 *
 * Announced as `role="status"` with the hidden text `common.loading`; `data-testid="spinner"`, as `LoadingState`.
 */
const { t } = useI18n()
</script>

<template>
  <div role="status" aria-busy="true" class="space-y-4" data-testid="spinner">
    <span class="sr-only">{{ t('common.loading') }}</span>
    <div class="space-y-2.5 rounded-hero bg-highlight p-5" aria-hidden="true">
      <span class="skeleton block h-6 w-3/5" />
      <span class="skeleton block h-4 w-4/5" />
    </div>
    <div class="space-y-1" aria-hidden="true">
      <div v-for="row in 6" :key="row" class="league-row" data-testid="skeleton-row">
        <span class="skeleton h-4 w-5" />
        <span class="skeleton size-8 rounded-full" />
        <span class="skeleton h-4 flex-1" />
        <span class="skeleton h-4 w-10" />
      </div>
    </div>
  </div>
</template>
