<script setup lang="ts">
import { computed } from 'vue'
import TopicArt from '@/components/ui/TopicArt.vue'
import OnboardingActions from './OnboardingActions.vue'
import SelectableTile from './SelectableTile.vue'
import StepIntro from './StepIntro.vue'
import { ONBOARDING_GOALS, type OnboardingGoal } from '@/api/onboardingTypes'
import { useOnboardingStore } from '@/stores/onboarding'
import { useTelegram } from '@/composables/useTelegram'
import { useOnboardingI18n } from '@/i18n/onboarding'
import type { OnboardingStringKey } from '@/i18n/onboarding'

/**
 * O4 "Why English" (kit 02-onboarding/O4): six tiles with the pictures of topics, several may be chosen (a frame and an ink check);
 * "Continue" counts them ("Continue · 2 chosen").
 */
const emit = defineEmits<{ next: []; exit: [] }>()
const store = useOnboardingStore()
const { selectionHaptic } = useTelegram()
const { t, tn } = useOnboardingI18n()

/** The picture of each goal: a module of a platform course whose topic tells it (kit O4). */
const ART: Readonly<Record<OnboardingGoal, { course: string; stage: number }>> = {
  career: { course: 'english-a1-a2', stage: 7 },
  study: { course: 'english-a1-a2', stage: 15 },
  travel: { course: 'english-a1-a2', stage: 11 },
  relocation: { course: 'english-b1', stage: 6 },
  communication: { course: 'english-b1', stage: 2 },
  media: { course: 'english-b1', stage: 11 },
}

const goals = computed((): readonly OnboardingGoal[] =>
  (store.data?.options.goals ?? ONBOARDING_GOALS).filter((goal) => ONBOARDING_GOALS.includes(goal)),
)
const chosen = computed(() => store.draft.goals.length)
const busy = computed(() => store.saving === 'goals')

function toggle(goal: OnboardingGoal): void {
  const current = store.draft.goals
  // In the order of the tiles, so the summary of O8 reads the same way every time.
  store.draft.goals = current.includes(goal)
    ? current.filter((item) => item !== goal)
    : goals.value.filter((item) => item === goal || current.includes(item))
  store.clearError()
  selectionHaptic()
}

async function onContinue(): Promise<void> {
  const outcome = await store.saveStep('goals')
  if (outcome === 'ok') emit('next')
  else if (outcome === 'completed') emit('exit')
}
</script>

<template>
  <div class="flex flex-1 flex-col">
    <StepIntro :title="t('onboarding.goals.title')" :lead="t('onboarding.goals.lead')" />
    <div class="mt-5 grid grid-cols-2 gap-2.5" role="group" :aria-label="t('onboarding.goals.title')">
      <SelectableTile
        v-for="goal in goals"
        :key="goal"
        :selected="store.draft.goals.includes(goal)"
        multiple
        class="flex min-h-[112px] flex-col items-center justify-center gap-1.5 rounded-lg px-2 pt-3 pb-3"
        :data-testid="`goal-${goal}`"
        @click="toggle(goal)"
      >
        <TopicArt :course="ART[goal].course" :stage="ART[goal].stage" :size="60" />
        <span
          class="t-body-m-strong text-center"
          :class="store.draft.goals.includes(goal) ? 'text-body' : 'text-muted'"
        >{{ t(`onboarding.goals.${goal}` as OnboardingStringKey) }}</span>
      </SelectableTile>
    </div>
    <OnboardingActions
      :label="chosen > 0 ? tn('onboarding.continue_selected', chosen) : t('onboarding.continue')"
      :arrow="chosen === 0"
      :disabled="chosen === 0"
      :busy="busy"
      :error="store.saveErrorStep === 'goals' ? store.saveError : ''"
      @primary="onContinue"
    />
  </div>
</template>
