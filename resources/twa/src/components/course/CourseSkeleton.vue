<script setup lang="ts">
import { useI18n } from '@/i18n'

/**
 * The placeholder of the course screen (docs/design/2026-11-25-redesign/Path.html) while it loads: the shapes of the screen itself -
 * the title, the tinted card of the course, a thin heading of a module and the rows of the lessons - so nothing jumps when it fills
 * in. Shared by the course screen and the "Course" tab that looks up the learner's course first (the same shapes on both).
 *
 * Announced like `LoadingState`: `role="status"` with the hidden `common.loading`, `data-testid="spinner"`.
 */
const { t } = useI18n()
</script>

<template>
  <div role="status" aria-busy="true" class="pt-3.5" data-testid="spinner">
    <span class="sr-only">{{ t('common.loading') }}</span>
    <div aria-hidden="true">
      <div class="skeleton h-9 w-1/2 rounded-control" />
      <div class="mt-[18px] space-y-3 rounded-hero bg-highlight px-4 py-[18px]">
        <div class="skeleton h-4 w-2/3" />
        <div class="skeleton h-5 w-1/3" />
        <div class="skeleton h-2.5 w-full rounded-full" />
      </div>
      <div class="skeleton mt-6 h-3 w-1/3" />
      <div class="mt-2.5 space-y-2">
        <div v-for="row in 4" :key="row" class="flex min-h-[54px] items-center gap-3.5 rounded-option border border-hairline bg-surface px-4">
          <span class="skeleton size-[26px] shrink-0 rounded-full" />
          <span class="skeleton block h-4" :class="row % 2 === 1 ? 'w-1/2' : 'w-2/3'" />
        </div>
      </div>
    </div>
  </div>
</template>
