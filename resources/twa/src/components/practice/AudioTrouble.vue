<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { useI18n } from '@/i18n'

/**
 * "Sound is not available" (docs/16 §10.4, §11.2): the sheet that opens when the clip of a dictation does not load or
 * does not play. Three ways on, none of them a penalty:
 * - "Retry" — load the clip again (the one primary button);
 * - "Replace the task" — another dictation takes its place; hidden when the replacements are used up;
 * - "No sound for now" — leave the dictation (to the hub, to choose another mode).
 * The ✕ and the dimmed page only close the sheet: the dictation stays as it is, with its own button to try again.
 *
 * Events: `retry`, `replace`, `silent`, `close`. `busy` disables the buttons while a retry or a swap is on its way.
 */
defineProps<{
  /** Replacements the session still allows (`replacements_left` of the element). */
  replacementsLeft: number
  busy?: boolean
}>()

const emit = defineEmits<{ retry: []; replace: []; silent: []; close: [] }>()

const { t } = useI18n()
</script>

<template>
  <BottomSheet :label="t('practice.audio.trouble.title')" data-testid="audio-trouble" @close="emit('close')">
    <header class="flex flex-col items-center gap-2 pt-1 text-center">
      <span class="flex size-14 items-center justify-center rounded-full bg-warning-soft text-warning-ink">
        <AppIcon name="exclamation-triangle" solid :size="28" />
      </span>
      <h2 class="text-[22px] leading-7 font-bold" data-testid="trouble-title">{{ t('practice.audio.trouble.title') }}</h2>
      <p class="text-[15px] leading-5" data-testid="trouble-text">
        {{ replacementsLeft > 0 ? t('practice.audio.trouble.text') : t('practice.audio.trouble.text_no_replace') }}
      </p>
      <p v-if="replacementsLeft > 0" class="text-[13px] leading-[18px] text-tg-hint" data-testid="replacements-left">
        {{ t('practice.audio.replacements_left', { count: replacementsLeft }) }}
      </p>
    </header>

    <div class="space-y-2 pb-1">
      <button type="button" class="btn btn-primary btn-block" :disabled="busy" data-testid="trouble-retry" @click="emit('retry')">
        {{ t('practice.audio.retry') }}
      </button>
      <button
        v-if="replacementsLeft > 0"
        type="button"
        class="btn btn-tinted btn-block"
        :disabled="busy"
        data-testid="trouble-replace"
        @click="emit('replace')"
      >
        {{ t('practice.audio.replace') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="trouble-silent" @click="emit('silent')">
        {{ t('practice.audio.silent') }}
      </button>
    </div>
  </BottomSheet>
</template>
