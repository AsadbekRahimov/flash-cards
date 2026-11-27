<script setup lang="ts">
import { useI18n } from '@/i18n'

/**
 * The placeholder of "Today" while `/today` loads (kit Muhrla, ANIMATIONS.md §6): the shapes of the screen itself - the date and the
 * greeting with the round stamp, the daftar hero with its button, the three steps of the plan - in sunken blocks, so nothing jumps when it
 * fills in. Announced like `LoadingState`: `role="status"` with the hidden `common.loading`, `data-testid="spinner"`.
 */
const { t } = useI18n()
</script>

<template>
  <div role="status" aria-busy="true" class="pt-2" data-testid="spinner">
    <span class="sr-only">{{ t('common.loading') }}</span>
    <div aria-hidden="true">
      <div class="flex items-center justify-between gap-3 pb-6">
        <div class="min-w-0 flex-1 space-y-2.5">
          <div class="skeleton h-4 w-2/5" />
          <div class="skeleton h-8 w-3/4 rounded-md" />
        </div>
        <div class="skeleton size-[92px] shrink-0 rounded-full" />
      </div>
      <div class="rounded-xl border border-hairline bg-surface py-5 pr-5 pl-11">
        <div class="skeleton h-[26px] w-24 rounded-full" />
        <div class="skeleton mt-5 h-9 w-3/5 rounded-md" />
        <div class="skeleton mt-3 h-4 w-4/5" />
        <div class="skeleton mt-5 h-[52px] w-full rounded-md" />
      </div>
      <div class="skeleton mt-8 h-6 w-1/2 rounded-md" />
      <div class="mt-3 space-y-4">
        <div v-for="row in 3" :key="row" class="flex items-center gap-3">
          <span class="skeleton size-7 shrink-0 rounded-full" />
          <span class="flex-1 space-y-2">
            <span class="skeleton block h-4 w-1/3" />
            <span class="skeleton block h-3 w-1/2" />
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
