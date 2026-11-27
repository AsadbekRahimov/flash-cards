<script setup lang="ts">
import { computed, ref } from 'vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import TopicArt from '@/components/ui/TopicArt.vue'
import OnboardingActions from './OnboardingActions.vue'
import SelectableTile from './SelectableTile.vue'
import StepIntro from './StepIntro.vue'
import { useOnboardingStore } from '@/stores/onboarding'
import { useTelegram } from '@/composables/useTelegram'
import { useOnboardingI18n } from '@/i18n/onboarding'
import type { OnboardingStringKey } from '@/i18n/onboarding'

/**
 * O6 "Goal of the day" (kit 02-onboarding/O6): the goals of the settings, 10 / 20 / 40 CARDS a day (docs/40 §3, В3 - not the kit's
 * 5 / 10 / 20 / 30 words), with the kit's words ("Calm / Serious / Intense"), the minutes and "we recommend" on the recommended one. A
 * goal above the plan's limit (`daily_goal_max`: Free without the trial) carries a lock: a tap opens the paywall and chooses nothing.
 * A 403 `daily_goal_pro_required` (the trial ended meanwhile) puts the choice back to the limit, says so and opens the paywall.
 */
const emit = defineEmits<{ next: []; exit: [] }>()
const store = useOnboardingStore()
const { selectionHaptic } = useTelegram()
const { t, tn } = useOnboardingI18n()

/** About 0.6 minute a card (the kit: 10 words - 6 minutes). */
const MINUTES_PER_CARD = 0.6
/** The goals that have the kit's words. */
const LABELLED = [10, 20, 40]

const goals = computed((): number[] => store.data?.options.daily_goals ?? [])
const recommended = computed((): number | null => store.data?.options.recommended_daily_goal ?? null)
const busy = computed(() => store.saving === 'daily_goal')
const locked = (goal: number): boolean => store.goalMax !== null && goal > store.goalMax

const paywallOpen = ref(false)

function label(goal: number): string {
  return LABELLED.includes(goal) ? t(`onboarding.daily_goal.label.${goal}` as OnboardingStringKey) : ''
}

function pick(goal: number): void {
  if (locked(goal)) {
    paywallOpen.value = true
    return
  }
  if (store.draft.dailyGoal === goal) return
  store.draft.dailyGoal = goal
  store.goalProRequired = false
  store.clearError()
  selectionHaptic()
}

async function onContinue(): Promise<void> {
  const outcome = await store.saveStep('daily_goal')
  if (outcome === 'ok') emit('next')
  else if (outcome === 'completed') emit('exit')
  else if (outcome === 'pro_required') paywallOpen.value = true
}
</script>

<template>
  <div class="flex flex-1 flex-col">
    <StepIntro :title="t('onboarding.daily_goal.title')" :lead="t('onboarding.daily_goal.lead')">
      <TopicArt course="english-a1-a2" :stage="4" :size="88" />
    </StepIntro>
    <div class="mt-5 flex flex-col gap-2.5" role="radiogroup" :aria-label="t('onboarding.daily_goal.title')">
      <SelectableTile
        v-for="goal in goals"
        :key="goal"
        :selected="store.draft.dailyGoal === goal"
        :locked="locked(goal)"
        class="flex min-h-[56px] items-center gap-3 rounded-md py-2.5 pr-4 pl-4 text-left"
        :aria-label="locked(goal) ? t('onboarding.daily_goal.locked', { value: goal }) : undefined"
        :data-testid="`daily-goal-${goal}`"
        @click="pick(goal)"
      >
        <span class="t-display-m font-mono w-12 shrink-0">{{ goal }}</span>
        <span class="flex min-w-0 flex-1 flex-col">
          <!-- "We recommend" stands next to the words, not at the right edge: "N cards a day · ~M min" needs the width of the row. -->
          <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span v-if="label(goal)" class="t-body-m-strong">{{ label(goal) }}</span>
            <span v-if="goal === recommended && !locked(goal)" class="chip chip-accent" data-testid="daily-goal-recommended">
              {{ t('onboarding.daily_goal.recommended') }}
            </span>
          </span>
          <span class="t-label-s" :class="locked(goal) ? '' : 'text-muted'">
            {{ tn('onboarding.daily_goal.unit', goal, { minutes: Math.round(goal * MINUTES_PER_CARD) }) }}
          </span>
        </span>
        <AppIcon v-if="locked(goal)" name="lock-closed" :size="20" class="shrink-0" data-testid="daily-goal-lock" />
      </SelectableTile>
    </div>
    <InlineAlert
      v-if="store.goalProRequired && store.goalMax !== null"
      tone="info"
      class="mt-4"
      :message="t('onboarding.daily_goal.pro_required', { max: store.goalMax })"
      data-testid="daily-goal-pro-required"
    />
    <OnboardingActions
      :label="t('onboarding.continue')"
      :disabled="store.draft.dailyGoal === null || locked(store.draft.dailyGoal)"
      :busy="busy"
      :error="store.saveErrorStep === 'daily_goal' ? store.saveError : ''"
      @primary="onContinue"
    />
    <PaywallSheet v-if="paywallOpen" reason="daily_goal" @close="paywallOpen = false" @review="paywallOpen = false" />
  </div>
</template>
