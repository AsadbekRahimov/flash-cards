<script setup lang="ts">
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { useI18n } from '@/i18n'

/**
 * "Learn instead of the course" (docs/14 §6.5): making a deck the main one replaces the main course, so the learner is asked first.
 * `busy` switches the buttons off while the write is on its way; `confirm` runs it, `close` (the cross, the dimmed page, "Cancel") leaves.
 */
defineProps<{ busy?: boolean }>()
const emit = defineEmits<{ confirm: []; close: [] }>()

const { t } = useI18n()
</script>

<template>
  <BottomSheet :label="t('decks.activate.title')" data-testid="activate-sheet" @close="emit('close')">
    <div class="flex flex-col gap-3 px-4 pt-2 pb-4">
      <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ t('decks.activate.title') }}</h2>
      <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="activate-text">{{ t('decks.activate.text') }}</p>
      <button type="button" class="btn btn-primary btn-block" :disabled="busy" data-testid="activate-yes" @click="emit('confirm')">
        {{ t('decks.choose') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="activate-no" @click="emit('close')">
        {{ t('decks.activate.no') }}
      </button>
    </div>
  </BottomSheet>
</template>
