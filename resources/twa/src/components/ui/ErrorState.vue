<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import MascotArt from './MascotArt.vue'
import { useI18n } from '@/i18n'
import type { MascotPose } from '@/composables/illustrations'

/**
 * Full-screen error with a "Retry" button, instead of the screen's content; in the kit Muhrla the mascot thinks above it (ASSETS.md:
 * `thinking` for F5 "Нет сети" and the errors), the title in Title/M, the one Primary under the text.
 *
 * - `message` — what went wrong, already localized (e.g. `errorMessage(err, 'today')`).
 * - `title` — heading; defaults to `common.error_title`.
 * - `mascot` — the pose above the title, default `thinking`; `false` — the plain warning icon in the danger colour instead (a small
 *   error inside a sheet).
 * - event `retry` — the button was pressed (reload the data).
 * - slot (default) — extra buttons below "Retry" (e.g. "Back to courses"); use `btn-secondary` / `btn-plain`.
 *
 * `data-testid="error"` on the root, `data-testid="retry-btn"` on the button (a parent's `data-testid` replaces the first).
 *
 * @example <ErrorState v-else-if="!data" :message="loadError" @retry="load" />
 */
withDefaults(
  defineProps<{
    message: string
    title?: string
    mascot?: MascotPose | false
  }>(),
  { title: undefined, mascot: 'thinking' },
)

defineEmits<{ retry: [] }>()

const { t } = useI18n()
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center" role="alert" data-testid="error">
    <MascotArt v-if="mascot" :pose="mascot" :height="112" class="mb-2" />
    <AppIcon v-else name="exclamation-triangle" :size="28" class="text-danger" />
    <h2 class="t-title-m">{{ title ?? t('common.error_title') }}</h2>
    <p class="t-body-m text-muted">{{ message }}</p>
    <div class="mt-3 flex w-full max-w-64 flex-col gap-2">
      <button type="button" class="btn btn-primary btn-block" data-testid="retry-btn" @click="$emit('retry')">
        {{ t('common.retry') }}
      </button>
      <slot />
    </div>
  </div>
</template>
