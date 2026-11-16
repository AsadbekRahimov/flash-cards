<script setup lang="ts">
import { onMounted } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { useLocaleSwitch } from '@/composables/useLocaleSwitch'
import { LANGUAGE_TITLE, useI18n } from '@/i18n'

/**
 * "Til · Язык": the interface language in one tap, from Home, the Profile and the first-run intro (docs/14 §6.1). The
 * title is bilingual on purpose - it must be readable in the language the learner cannot read. The list is the one of
 * the settings (labels in the language itself), the current language has a check; a tap on another one saves it through
 * the settings store, switches the app at once, says "Saved" in the new language and closes the sheet. A refusal is a
 * toast and the language stays (the sheet stays open too).
 */
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { options, current, status, saving, ensure, change } = useLocaleSwitch()

async function pick(id: string): Promise<void> {
  if (saving.value) return
  if (await change(id)) emit('close')
}

onMounted(() => {
  void ensure()
})
</script>

<template>
  <BottomSheet :label="LANGUAGE_TITLE" data-testid="language-sheet" @close="emit('close')">
    <div class="space-y-3 pt-2 pr-10">
      <h2 class="text-[22px] leading-7 font-bold" data-testid="language-title">{{ LANGUAGE_TITLE }}</h2>
    </div>

    <ListGroup v-if="options.length > 0" role="radiogroup" :aria-label="LANGUAGE_TITLE" data-testid="language-list">
      <button
        v-for="option in options"
        :key="option.id"
        type="button"
        class="list-row"
        role="radio"
        :aria-checked="option.id === current"
        :disabled="saving"
        :data-testid="`language-${option.id}`"
        @click="pick(option.id)"
      >
        <span class="min-w-0 flex-1 text-left text-base leading-[22px]" :class="{ 'font-semibold': option.id === current }">{{ option.label }}</span>
        <AppIcon v-if="option.id === current" name="check" :size="20" class="shrink-0 text-accent" data-testid="language-check" />
      </button>
    </ListGroup>

    <LoadingState v-else-if="status === 'idle' || status === 'loading'" variant="list" />

    <div v-else class="space-y-3 pb-2 text-center">
      <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('error.generic') }}</p>
      <button type="button" class="btn btn-tinted btn-block" data-testid="language-retry" @click="ensure">{{ t('common.retry') }}</button>
    </div>
  </BottomSheet>
</template>
