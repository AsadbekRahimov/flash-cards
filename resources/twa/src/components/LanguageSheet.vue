<script setup lang="ts">
import { onMounted } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import { useLocaleSwitch } from '@/composables/useLocaleSwitch'
import { LANGUAGE_SAMPLES, LANGUAGE_TITLE, useI18n } from '@/i18n'

/**
 * "Til · Язык": the interface language in one tap, from Home, the Profile and the first-run intro (docs/14 §6.1). The
 * title is bilingual on purpose - it must be readable in the language the learner cannot read. The list is the one of
 * the settings (labels in the language itself), drawn as F6 of the kit Muhrla: the reading mascot, then a selectable card per language —
 * its code on a plate, the name and a sample of how a word is translated in it ("tea — чай"), the current one framed in 2 px of
 * `border/strong` with an ink check; a tap on another one saves it through
 * the settings store, switches the app at once, says "Saved" in the new language and closes the sheet. A refusal is a
 * toast and the language stays (the sheet stays open too).
 */
const emit = defineEmits<{ close: [] }>()

/** The sample of a language of the list (`LANGUAGE_SAMPLES`); a language this build has no sample for shows none. */
const sample = (id: string): string => (LANGUAGE_SAMPLES as Readonly<Record<string, string>>)[id] ?? ''

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
    <!-- F6 of the kit: the reading mascot and the bilingual title on the centre axis. -->
    <div class="flex flex-col items-center gap-2 pt-1 text-center">
      <MascotArt pose="reading" :height="96" />
      <h2 class="t-display-m" data-testid="language-title">{{ LANGUAGE_TITLE }}</h2>
    </div>

    <!-- The languages as selectable cards: the code on a plate, the name with a sample word, the chosen one framed 2 px with a check. -->
    <div v-if="options.length > 0" class="space-y-3 pb-1" role="radiogroup" :aria-label="LANGUAGE_TITLE" data-testid="language-list">
      <button
        v-for="option in options"
        :key="option.id"
        type="button"
        class="pressable flex min-h-[72px] w-full items-center gap-3 rounded-lg bg-surface px-3 py-3 text-left disabled:opacity-50"
        :class="option.id === current ? 'shadow-[inset_0_0_0_2px_var(--app-border-strong)]' : 'shadow-[inset_0_0_0_1.5px_var(--app-hairline)]'"
        role="radio"
        :aria-checked="option.id === current"
        :disabled="saving"
        :data-testid="`language-${option.id}`"
        @click="pick(option.id)"
      >
        <span
          class="flex size-10 shrink-0 items-center justify-center rounded-sm text-sm leading-none font-semibold uppercase"
          :class="option.id === current ? 'bg-inverse text-on-inverse' : 'bg-sunken text-body'"
          aria-hidden="true"
        >{{ option.id }}</span>
        <span class="flex min-w-0 flex-1 flex-col">
          <span class="t-body-m-strong" data-testid="language-label">{{ option.label }}</span>
          <span v-if="sample(option.id)" class="text-[13px] leading-[18px] text-muted" lang="en" data-testid="language-sample">{{ sample(option.id) }}</span>
        </span>
        <span
          v-if="option.id === current"
          class="flex size-[22px] shrink-0 items-center justify-center rounded-full bg-inverse text-on-inverse"
          data-testid="language-check"
        ><AppIcon name="check" :size="14" /></span>
        <span v-else class="size-[22px] shrink-0 rounded-full shadow-[inset_0_0_0_1.5px_var(--app-hairline)]" aria-hidden="true" />
      </button>
    </div>

    <LoadingState v-else-if="status === 'idle' || status === 'loading'" variant="list" />

    <div v-else class="space-y-3 pb-2 text-center">
      <p class="t-body-m text-muted">{{ t('error.generic') }}</p>
      <button type="button" class="btn btn-secondary btn-block" data-testid="language-retry" @click="ensure">{{ t('common.retry') }}</button>
    </div>
  </BottomSheet>
</template>
