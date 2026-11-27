<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import SegmentedControl, { type SegmentOption } from '@/components/ui/SegmentedControl.vue'
import { useLocaleSwitch } from '@/composables/useLocaleSwitch'
import { LANGUAGE_TITLE, useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'

/**
 * "How it works": four short steps in a bottom sheet (docs/14 §3, the product explains itself). Opened by Home on the
 * first visit of a newcomer and by the "How it works" row of the profile. `close` comes from "Start", "Skip", the ✕ or the dimmed page.
 * The first step carries the language switch (the newcomer may not read the language Telegram guessed): a tap saves the
 * language like the settings do and the steps are redrawn at once.
 */
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { options, current, saving, ensure, change } = useLocaleSwitch()

const localeOptions = computed<SegmentOption[]>(() =>
  options.value.map((option) => ({ value: option.id, label: option.label, testid: `intro-locale-${option.id}` })),
)

onMounted(() => {
  void ensure()
})

const STEPS = [1, 2, 3, 4] as const
const step = ref(0)
const last = computed(() => step.value === STEPS.length - 1)

const title = computed(() => t(`intro.step${step.value + 1}.title` as StringKey))
const body = computed(() => t(`intro.step${step.value + 1}.text` as StringKey))

function next(): void {
  if (last.value) emit('close')
  else step.value += 1
}
</script>

<template>
  <BottomSheet :label="t('intro.title')" data-testid="intro-sheet" @close="emit('close')">
    <div class="space-y-3 pt-2">
      <!-- Clear of the ✕ in the corner of the sheet. -->
      <div v-if="step === 0 && localeOptions.length > 1 && current !== null" class="pr-11" data-testid="intro-locale">
        <SegmentedControl
          :model-value="current"
          :options="localeOptions"
          :disabled="saving"
          :ariaLabel="LANGUAGE_TITLE"
          @update:model-value="change(String($event))"
        />
      </div>
      <!-- On the centre axis of the kit: the mascot greets on the first step, then the title and the line of each step. -->
      <div class="flex flex-col items-center gap-2 text-center">
        <MascotArt v-if="step === 0" pose="hello" :height="96" />
        <p class="t-label-s text-muted">{{ t('intro.title') }}</p>
        <h2 class="t-display-m" data-testid="intro-title">{{ title }}</h2>
        <p class="t-body-m text-muted" data-testid="intro-text">{{ body }}</p>
      </div>
      <!-- The steps as the progress segments of the kit: done in ink, the current one in muhr, the rest the sunken paper. -->
      <div class="segments pt-1" aria-hidden="true" data-testid="intro-dots">
        <span
          v-for="(n, i) in STEPS"
          :key="n"
          class="segment"
          :data-state="i < step ? 'done' : i === step ? 'current' : undefined"
          :data-current="i === step ? 'true' : undefined"
        />
      </div>
    </div>
    <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="intro-next" @click="next">
      {{ t(last ? 'intro.start' : 'intro.next') }}
    </button>
    <button v-if="!last" type="button" class="btn btn-ghost btn-block" data-testid="intro-skip" @click="emit('close')">
      {{ t('intro.skip') }}
    </button>
  </BottomSheet>
</template>
