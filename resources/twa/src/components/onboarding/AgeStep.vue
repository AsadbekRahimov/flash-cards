<script setup lang="ts">
import { computed } from 'vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import OnboardingActions from './OnboardingActions.vue'
import SelectableTile from './SelectableTile.vue'
import StepIntro from './StepIntro.vue'
import { AGE_RANGES, type AgeRange } from '@/api/onboardingTypes'
import { useOnboardingStore } from '@/stores/onboarding'
import { useTelegram } from '@/composables/useTelegram'
import { useOnboardingI18n } from '@/i18n/onboarding'
import type { OnboardingStringKey } from '@/i18n/onboarding'

/**
 * O3 "Age" (kit 02-onboarding/O3): six tiles, one choice, the chosen one in ink. The age is only stored: nobody sees it, nothing is
 * limited by it (docs/40 §3, В5) - the line under the tiles says so.
 */
const emit = defineEmits<{ next: []; exit: [] }>()
const store = useOnboardingStore()
const { selectionHaptic } = useTelegram()
const { t } = useOnboardingI18n()

/** The ranges of the server, the ones this client has words for (an unknown one from a newer server is left out). */
const ranges = computed((): readonly AgeRange[] => (store.data?.options.age_ranges ?? AGE_RANGES).filter((range) => AGE_RANGES.includes(range)))
const busy = computed(() => store.saving === 'age')

function pick(range: AgeRange): void {
  if (store.draft.age === range) return
  store.draft.age = range
  store.clearError()
  selectionHaptic()
}

async function onContinue(): Promise<void> {
  const outcome = await store.saveStep('age')
  if (outcome === 'ok') emit('next')
  else if (outcome === 'completed') emit('exit')
}
</script>

<template>
  <div class="flex flex-1 flex-col">
    <StepIntro :title="t('onboarding.age.title')" :lead="t('onboarding.age.lead')">
      <MascotArt pose="thinking" :height="92" loading="eager" />
    </StepIntro>
    <div class="mt-6 grid grid-cols-3 gap-2.5" role="radiogroup" :aria-label="t('onboarding.age.title')">
      <SelectableTile
        v-for="range in ranges"
        :key="range"
        :selected="store.draft.age === range"
        look="ink"
        class="age-tile h-[60px] rounded-md px-1 whitespace-nowrap"
        :data-testid="`age-${range}`"
        @click="pick(range)"
      >
        {{ t(`onboarding.age.${range}` as OnboardingStringKey) }}
      </SelectableTile>
    </div>
    <p class="t-label-s mt-5 text-center text-balance text-muted" data-testid="age-note">{{ t('onboarding.age.note') }}</p>
    <OnboardingActions
      :label="t('onboarding.continue')"
      :disabled="store.draft.age === null"
      :busy="busy"
      :error="store.saveErrorStep === 'age' ? store.saveError : ''"
      @primary="onContinue"
    />
  </div>
</template>

<style scoped>
/* Numeric/L of the kit (22 px Unbounded); on a 320 px screen the three tiles of a row are ~87 px wide - "14–17" takes 18 px there. */
.age-tile {
  font: 600 22px/26px var(--font-display);
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
}
@media (max-width: 359px) {
  .age-tile {
    font-size: 18px;
  }
}
</style>
