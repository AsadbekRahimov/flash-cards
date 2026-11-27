<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import { useI18n } from '@/i18n'

/**
 * F5 "Нет сети" of the kit Muhrla (docs/40 §2.6, 03-new-features/F5-net-seti.png): over the whole app, on the page colour - the thinking
 * mascot, «Нет соединения», a line of text, the chip of what is saved (only when there is something true to say) and «Повторить» in the
 * bottom zone. No «Повторять слова офлайн» (docs/40 §3, В7: there is no offline mode).
 *
 * - `chip` - the text of the chip («Урок 3 · сохранено 4 из 8», «Прогресс сохранён на сервере»); null - no chip.
 * - `pendingAnswer` - the last answer of the learner waits on the device: the text says it will be sent.
 * - event `retry` - «Повторить» was pressed.
 *
 * The button takes the focus when the screen appears; the title and the text are a polite live region and describe the button, so a
 * screen reader says what happened together with what to do.
 */
defineProps<{ chip: string | null; pendingAnswer: boolean }>()
defineEmits<{ retry: [] }>()

const { t } = useI18n()

const button = ref<HTMLButtonElement | null>(null)

onMounted(() => {
  void nextTick(() => button.value?.focus())
})
</script>

<template>
  <div class="fixed inset-0 z-[60] flex flex-col bg-page" data-testid="offline-screen">
    <div class="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-6 pt-6 pb-4 text-center">
      <MascotArt pose="thinking" :height="150" loading="eager" class="mb-6" />
      <div id="offline-message" role="status" aria-live="polite" class="flex flex-col items-center gap-3">
        <h2 class="t-display-l" data-testid="offline-title">{{ t('offline.title') }}</h2>
        <p class="t-body-l max-w-80 font-normal text-muted" data-testid="offline-text">
          {{ pendingAnswer ? t('offline.text_pending') : t('offline.text') }}
        </p>
      </div>
      <span
        v-if="chip"
        class="chip chip-neutral mt-5 h-auto max-w-full gap-2 px-4 py-2 text-[15px] leading-5 whitespace-normal"
        data-testid="offline-chip"
      >
        <span class="size-2 shrink-0 rounded-full bg-muhr" aria-hidden="true" />
        <span class="min-w-0">{{ chip }}</span>
      </span>
    </div>
    <div class="mx-auto w-full max-w-xl px-5 pt-3" style="padding-bottom: calc(0.75rem + var(--app-safe-b))">
      <button
        ref="button"
        type="button"
        class="btn btn-primary btn-lg btn-block"
        aria-describedby="offline-message"
        data-testid="offline-retry"
        @click="$emit('retry')"
      >
        {{ t('common.retry') }}
      </button>
    </div>
  </div>
</template>
