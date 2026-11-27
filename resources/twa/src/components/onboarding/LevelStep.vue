<script setup lang="ts">
import { computed } from 'vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import OnboardingActions from './OnboardingActions.vue'
import SelectableTile from './SelectableTile.vue'
import StepIntro from './StepIntro.vue'
import { useOnboardingStore } from '@/stores/onboarding'
import { useTelegram } from '@/composables/useTelegram'
import { useOnboardingI18n } from '@/i18n/onboarding'
import type { OnboardingStringKey } from '@/i18n/onboarding'
import type { OnboardingLevelOption } from '@/api/onboardingTypes'

/**
 * O5 "Level" (kit 02-onboarding/O5): the rows are the COURSES the levels lead to (`options.levels`, docs/40 §2.1) - the chip of the
 * course on the left, what the level means in words, the radio on the right; "Start with B1". No "Phrases" (it is never the main course,
 * docs/40 §3, В2) - the server does not offer it. "Take the 2-minute test" is hidden until the placement test is released (В6).
 */
const emit = defineEmits<{ next: []; exit: [] }>()
const store = useOnboardingStore()
const { selectionHaptic } = useTelegram()
const { t } = useOnboardingI18n()

/** The levels that have words of their own. */
const DESCRIBED = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']

const rows = computed((): OnboardingLevelOption[] => store.data?.options.levels ?? [])
const chosen = computed(() => rows.value.find((row) => row.level === store.draft.level) ?? null)
const busy = computed(() => store.saving === 'level')

/** What the level of a row means ("Starting from zero"); a level without words - none. */
function describe(row: OnboardingLevelOption): string {
  return DESCRIBED.includes(row.level) ? t(`onboarding.level.desc.${row.level}` as OnboardingStringKey) : ''
}

function pick(row: OnboardingLevelOption): void {
  if (store.draft.level === row.level) return
  store.draft.level = row.level
  store.clearError()
  selectionHaptic()
}

async function onContinue(): Promise<void> {
  const outcome = await store.saveStep('level')
  if (outcome === 'ok') emit('next')
  else if (outcome === 'completed') emit('exit')
}
</script>

<template>
  <div class="flex flex-1 flex-col">
    <StepIntro :title="t('onboarding.level.title')" :lead="t('onboarding.level.lead')" />
    <div v-if="rows.length > 0" class="mt-5 flex flex-col gap-2.5" role="radiogroup" :aria-label="t('onboarding.level.title')">
      <SelectableTile
        v-for="row in rows"
        :key="row.course_slug"
        :selected="store.draft.level === row.level"
        class="flex min-h-[50px] items-center gap-3 rounded-md py-2 pr-4 pl-2.5 text-left"
        :data-testid="`level-${row.level}`"
        @click="pick(row)"
      >
        <span
          class="t-label-s inline-flex h-8 min-w-16 shrink-0 items-center justify-center rounded-sm px-2 transition-colors duration-200"
          :class="store.draft.level === row.level ? 'bg-inverse text-on-inverse' : 'bg-sunken text-body'"
          data-testid="level-chip"
        >{{ row.level_label }}</span>
        <span
          class="t-body-m-strong min-w-0 flex-1"
          :class="store.draft.level === row.level ? 'text-body' : 'text-muted'"
        >{{ describe(row) }}</span>
        <span
          class="level-radio inline-flex size-[22px] shrink-0 items-center justify-center rounded-full"
          :data-on="store.draft.level === row.level ? 'true' : undefined"
          aria-hidden="true"
        />
      </SelectableTile>
    </div>
    <InlineAlert v-else-if="store.data !== null" tone="info" :message="t('onboarding.level.empty')" class="mt-5" data-testid="level-empty" />
    <OnboardingActions
      :label="chosen ? t('onboarding.level.start', { level: chosen.level_label }) : t('onboarding.continue')"
      :disabled="chosen === null"
      :busy="busy"
      :error="store.saveErrorStep === 'level' ? store.saveError : ''"
      @primary="onContinue"
    />
  </div>
</template>

<style scoped>
.level-radio {
  box-shadow: inset 0 0 0 1.5px var(--app-hairline);
  transition: background-color var(--dur-state) var(--ease-out), box-shadow var(--dur-state) var(--ease-out);
}
.level-radio[data-on='true'] {
  background-color: var(--app-inverse);
  box-shadow: inset 0 0 0 1.5px var(--app-inverse);
}
</style>
