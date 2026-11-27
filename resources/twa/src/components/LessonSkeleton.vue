<script setup lang="ts">
import { useI18n } from '@/i18n'

/**
 * The loading state of a lesson-like screen (a training, an exam, a practice session; kit Muhrla ANIMATIONS.md §6): the skeleton of a
 * question on the sunken paper - the place of the mascot (not on a short window), the task, the word, then four answers - pulsing 0.6 ↔ 1,
 * still under `prefers-reduced-motion`. Announced as `role="status"` with the hidden `common.loading`; `data-testid="spinner"`, the same
 * as `LoadingState` (a parent's `data-testid` replaces it).
 *
 * - `options` — how many answer blocks (default 4; 0 for a task without options).
 */
withDefaults(defineProps<{ options?: number }>(), { options: 4 })

const { t } = useI18n()
</script>

<template>
  <div class="flex flex-1 flex-col px-5 pt-5 short:pt-3" role="status" aria-busy="true" data-testid="spinner">
    <span class="sr-only">{{ t('common.loading') }}</span>
    <div class="flex flex-col items-center gap-3" aria-hidden="true">
      <span class="skeleton size-[88px] rounded-full short:hidden" />
      <span class="skeleton h-4 w-40" />
      <span class="skeleton h-11 w-48 rounded-md" />
      <span class="skeleton h-5 w-28" />
    </div>
    <div v-if="options > 0" class="mt-6 flex flex-col gap-2.5 short:mt-4 short:gap-2" aria-hidden="true">
      <span v-for="n in options" :key="n" class="skeleton block h-[60px] rounded-md short:h-12" />
    </div>
  </div>
</template>
