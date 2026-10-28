<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'

/**
 * "This session is over" instead of the training / exam content (a 410 from the server): an icon in a circle, a
 * title, an optional explanation and the buttons in the default slot (one `btn-primary` at most). The look of
 * `EmptyState`, but centered in the free height of the immersive screen. `data-testid="closed"` on the root
 * (a parent's `data-testid` replaces it).
 *
 * @example
 * <ClosedState icon="lock-closed" :title="t('training.closed_title')" :text="errorMessage">
 *   <button class="btn btn-primary btn-block" @click="close()">…</button>
 * </ClosedState>
 */
defineProps<{
  icon: IconName
  title: string
  text?: string
}>()
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center" data-testid="closed">
    <span class="mb-1 flex size-14 items-center justify-center rounded-full bg-track text-tg-hint">
      <AppIcon :name="icon" :size="28" />
    </span>
    <h2 class="text-[17px] leading-[22px] font-semibold">{{ title }}</h2>
    <p v-if="text" class="text-[15px] leading-5 text-tg-hint">{{ text }}</p>
    <div class="mt-3 flex w-full max-w-64 flex-col gap-2">
      <slot />
    </div>
  </div>
</template>
