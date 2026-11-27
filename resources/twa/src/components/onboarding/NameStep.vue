<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import OnboardingActions from './OnboardingActions.vue'
import StepIntro from './StepIntro.vue'
import { nameProblem, nameProblemText, NAME_MAX } from './nameRules'
import { useOnboardingStore } from '@/stores/onboarding'
import { useOnboardingI18n } from '@/i18n/onboarding'

/**
 * O2 "Name" (kit 02-onboarding/O2): the field, prefilled with the first name Telegram gives the page ("Taken from Telegram - you can
 * change it"); outside Telegram it is empty. The name is shown on the learner's own screens only (docs/40 §3, В1). A name the server
 * would refuse is marked at once and "Continue" waits; the server's refusal (422 `display_name_invalid`) is marked the same way.
 */
const emit = defineEmits<{ next: []; exit: [] }>()
const store = useOnboardingStore()
const { t } = useOnboardingI18n()

const name = computed({
  get: () => store.draft.name,
  set: (value: string) => {
    store.draft.name = value
    store.draft.nameFromTelegram = false
    store.clearError()
  },
})

/** What is wrong with the name as typed; an empty field is not an error to tell, only no answer yet. */
const problem = computed((): string | null => store.nameError ?? nameProblem(name.value))
const shownProblem = computed((): string | null => (problem.value === null || problem.value === 'empty' ? null : nameProblemText(problem.value)))
const busy = computed(() => store.saving === 'name')

async function onContinue(): Promise<void> {
  const outcome = await store.saveStep('name')
  if (outcome === 'ok') emit('next')
  else if (outcome === 'completed') emit('exit')
}
</script>

<template>
  <div class="flex flex-1 flex-col">
    <StepIntro :title="t('onboarding.name.title')" :lead="t('onboarding.name.lead')">
      <MascotArt pose="hello" :height="92" loading="eager" />
    </StepIntro>
    <label class="mt-6 flex flex-col gap-2">
      <span class="t-label-s text-muted">{{ t('onboarding.name.label') }}</span>
      <input
        v-model="name"
        type="text"
        class="field t-body-l"
        :class="{ 'name-invalid': shownProblem !== null }"
        :placeholder="t('onboarding.name.placeholder')"
        :maxlength="NAME_MAX * 4"
        autocomplete="given-name"
        enterkeyhint="next"
        :aria-invalid="shownProblem !== null ? 'true' : undefined"
        aria-describedby="onboarding-name-hint"
        data-testid="name-input"
        @keydown.enter.prevent="problem === null && onContinue()"
      />
    </label>
    <p id="onboarding-name-hint" class="t-label-s mt-2 flex min-h-5 items-start gap-1.5" aria-live="polite">
      <template v-if="shownProblem !== null">
        <AppIcon name="exclamation-circle" :size="18" class="shrink-0 text-danger-ink" />
        <span class="text-danger-ink" data-testid="name-error">{{ shownProblem }}</span>
      </template>
      <template v-else-if="store.draft.nameFromTelegram">
        <AppIcon name="check-circle" :size="18" class="shrink-0 text-success-ink" />
        <span class="text-muted" data-testid="name-from-telegram">{{ t('onboarding.name.from_telegram') }}</span>
      </template>
    </p>
    <OnboardingActions
      :label="t('onboarding.continue')"
      :disabled="problem !== null"
      :busy="busy"
      :error="store.saveErrorStep === 'name' ? store.saveError : ''"
      @primary="onContinue"
    />
  </div>
</template>

<style scoped>
.name-invalid,
.name-invalid:focus,
.name-invalid:focus-visible {
  border-color: var(--app-danger);
  box-shadow: inset 0 0 0 1px var(--app-danger);
}
</style>
