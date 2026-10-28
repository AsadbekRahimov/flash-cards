<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import { useI18n } from '@/i18n'

/**
 * Full-screen error with a "Retry" button, instead of the screen's content.
 *
 * - `message` — what went wrong, already localized (e.g. `errorMessage(err, 'today')`).
 * - `title` — heading; defaults to `common.error_title`.
 * - event `retry` — the button was pressed (reload the data).
 * - slot (default) — extra buttons below "Retry" (e.g. "Back to courses"); use `btn-tinted` / `btn-plain`.
 *
 * `data-testid="error"` on the root, `data-testid="retry-btn"` on the button (a parent's `data-testid` replaces the first).
 *
 * @example <ErrorState v-else-if="!data" :message="loadError" @retry="load" />
 */
defineProps<{
  message: string
  title?: string
}>()

defineEmits<{ retry: [] }>()

const { t } = useI18n()
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-10 text-center" role="alert" data-testid="error">
    <span class="flex size-14 items-center justify-center rounded-full bg-danger-soft text-danger">
      <AppIcon name="exclamation-triangle" :size="28" />
    </span>
    <h2 class="text-[17px] leading-[22px] font-semibold">{{ title ?? t('common.error_title') }}</h2>
    <p class="text-[15px] leading-5 text-tg-hint">{{ message }}</p>
    <div class="mt-2 flex w-full max-w-64 flex-col gap-2">
      <button type="button" class="btn btn-primary btn-block" data-testid="retry-btn" @click="$emit('retry')">
        {{ t('common.retry') }}
      </button>
      <slot />
    </div>
  </div>
</template>
