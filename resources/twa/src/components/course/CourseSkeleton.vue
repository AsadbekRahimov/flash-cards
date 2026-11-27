<script setup lang="ts">
import { useI18n } from '@/i18n'

/**
 * The placeholder of the course screen (kit Muhrla 03) while it loads: the shapes of the screen itself - the heading with "All courses",
 * the card of the course with its chip, line and mascot, the heading "Modules" and the rows of the path with their pictures - in sunken
 * blocks (ANIMATIONS.md §6), so nothing jumps when it fills in. Shared by the course screen and the "Course" tab that looks up the learner's course first (the same shapes on both).
 *
 * Announced like `LoadingState`: `role="status"` with the hidden `common.loading`, `data-testid="spinner"`.
 */
const { t } = useI18n()
</script>

<template>
  <div role="status" aria-busy="true" class="pt-2" data-testid="spinner">
    <span class="sr-only">{{ t('common.loading') }}</span>
    <div aria-hidden="true">
      <div class="flex items-center justify-between gap-3 pb-5">
        <div class="skeleton h-9 w-1/2 rounded-md" />
        <div class="skeleton h-4 w-20" />
      </div>
      <div class="flex items-center gap-3 rounded-lg border border-hairline bg-surface p-5">
        <div class="min-w-0 flex-1 space-y-2.5">
          <div class="skeleton h-[26px] w-16 rounded-full" />
          <div class="skeleton h-5 w-3/4" />
          <div class="skeleton h-3.5 w-2/3" />
          <div class="skeleton mt-4 h-1.5 w-full rounded-full" />
        </div>
        <div class="skeleton h-[84px] w-14 shrink-0 rounded-md" />
      </div>
      <div class="skeleton mt-6 h-7 w-1/3 rounded-md" />
      <div class="mt-3 space-y-1">
        <div v-for="row in 4" :key="row" class="flex items-center gap-3 px-3 py-3">
          <span class="skeleton size-16 shrink-0 rounded-md" />
          <span class="flex-1 space-y-2">
            <span class="skeleton block h-4" :class="row % 2 === 1 ? 'w-1/2' : 'w-2/3'" />
            <span class="skeleton block h-3.5 w-1/3" />
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
