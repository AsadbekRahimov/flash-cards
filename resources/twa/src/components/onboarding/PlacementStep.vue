<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import QuizOption from '@/components/QuizOption.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import SegmentProgress from '@/components/ui/SegmentProgress.vue'
import OnboardingActions from './OnboardingActions.vue'
import StepIntro from './StepIntro.vue'
import type { OptionState } from '@/composables/useChoice'
import { useTelegram } from '@/composables/useTelegram'
import { prefersReducedMotion } from '@/composables/motion'
import { useOnboardingStore } from '@/stores/onboarding'
import { useI18n } from '@/i18n'
import { useOnboardingI18n } from '@/i18n/onboarding'

/**
 * The placement test of O5, "Take the 2-minute test" (docs/40 §2.1a, docs/04 A.27, T15): up to 10 questions "English word -> 2-4
 * translations", the segments of the questions above, no timer; "I don't know" skips a question (null). The key of the answers is the server's: a pick is marked as chosen (the ink frame) and the next question
 * comes; the answers go to the server all at once after the last one, and it says the level. The result - «Ваш уровень: B1», the course
 * it leads to, «верных ответов: N из 10» - is put into the choice of O5 at once (the learner can change it there); "Continue with B1" and
 * Telegram's Back lead to O5.
 *
 * - Leaving in the middle (Back) keeps the set: coming back before it expires resumes at the next question without a request; later, or
 *   after a closed app, a new set is asked. 410 `placement_expired` on the answers - «Время теста вышло», "Start again" (a new set) or
 *   "Choose the level" (back to O5).
 * - A failed request: the start - the error with "Retry"; the answers - `InlineAlert` with "Retry" (the answers are kept).
 * - 422 `placement_unavailable` (the catalog cannot give a test now) - says so and leads back to O5 to choose by hand.
 * - 409 `onboarding_completed` - the onboarding is over elsewhere: "Today" (`exit`).
 */
const emit = defineEmits<{ back: []; exit: [] }>()

const store = useOnboardingStore()
const { selectionHaptic } = useTelegram()
const { t: at } = useI18n()
const { t } = useOnboardingI18n()

const placement = computed(() => store.placement)
const questions = computed(() => placement.value.test?.questions ?? [])
const total = computed(() => questions.value.length)
/** The question on screen: the first one without an answer (the last one while the answers are sent). */
const index = computed(() => Math.min(placement.value.answers.length, Math.max(0, total.value - 1)))
const question = computed(() => questions.value[index.value] ?? null)

/** `picked` of "I don't know": no option is marked. */
const SKIP = -1

/** The option just picked: marked as chosen for a moment before the next question comes. */
const picked = ref<number | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
/** How long the chosen option stays in view before the next question (none without motion: at once). */
const PICK_PAUSE_MS = 280

const sending = computed(() => placement.value.phase === 'submitting')
const answered = computed(() => total.value > 0 && placement.value.answers.length >= total.value)

function stateOf(option: number): OptionState {
  if (picked.value === option) return 'selected'
  if (answered.value && placement.value.answers[total.value - 1] === option) return 'selected'
  return 'idle'
}

function pick(option: number | null): void {
  if (picked.value !== null || sending.value || answered.value || placement.value.phase !== 'question') return
  picked.value = option ?? SKIP
  selectionHaptic()
  const go = (): void => {
    timer = null
    picked.value = null
    void store.answerPlacement(option).then(settle)
  }
  if (prefersReducedMotion()) go()
  else timer = setTimeout(go, PICK_PAUSE_MS)
}

function settle(outcome: 'ok' | 'completed' | 'error'): void {
  if (outcome === 'completed') emit('exit')
}

async function start(): Promise<void> {
  settle(await store.startPlacement())
}

async function retrySend(): Promise<void> {
  settle(await store.submitPlacement())
}

/** The row of O5 the result leads to, for its course line. */
const resultRow = computed(() => {
  const result = placement.value.result
  if (result === null) return null
  const rows = store.data?.options.levels ?? []
  return rows.find((row) => row.course_slug === result.course_slug) ?? rows.find((row) => row.levels.includes(result.level)) ?? null
})

const correct = computed(() => Object.values(placement.value.result?.correct_by_level ?? {}).reduce((sum, value) => sum + value, 0))

onMounted(() => {
  // A result of an earlier test stays until the learner asks again: O5 opens the test anew (a new set) - only from its button.
  if (placement.value.phase !== 'result') void start()
})

onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="placement" :data-phase="placement.phase">
    <template v-if="placement.phase === 'result' && placement.result">
      <StepIntro :title="t('onboarding.placement.result_title', { level: resultRow?.level_label ?? placement.result.level })" :lead="t('onboarding.placement.result_lead')">
        <MascotArt pose="celebrate" :height="120" loading="eager" />
      </StepIntro>
      <div v-if="resultRow" class="card mt-6 flex items-center gap-3" data-testid="placement-course">
        <span class="t-label-s inline-flex h-8 min-w-16 shrink-0 items-center justify-center rounded-sm bg-inverse px-2 text-on-inverse">{{ resultRow.level_label }}</span>
        <span class="t-body-m-strong min-w-0 flex-1">{{ resultRow.title }}</span>
      </div>
      <p v-if="total > 0" class="t-body-m mt-3 text-center text-muted" data-testid="placement-correct">
        {{ t('onboarding.placement.correct', { count: correct, total }) }}
      </p>
      <OnboardingActions
        :label="t('onboarding.placement.choose', { level: resultRow?.level_label ?? placement.result.level })"
        @primary="emit('back')"
      />
    </template>

    <template v-else-if="placement.phase === 'expired'">
      <StepIntro :title="t('onboarding.placement.expired_title')" :lead="t('onboarding.placement.expired_text')">
        <MascotArt pose="thinking" :height="120" loading="eager" />
      </StepIntro>
      <OnboardingActions
        :label="t('onboarding.placement.restart')"
        :arrow="false"
        :secondary="t('onboarding.placement.back')"
        @primary="start"
        @secondary="emit('back')"
      />
    </template>

    <template v-else-if="placement.phase === 'unavailable'">
      <StepIntro :title="t('onboarding.placement.unavailable_title')" :lead="t('onboarding.placement.unavailable_text')">
        <MascotArt pose="thinking" :height="120" loading="eager" />
      </StepIntro>
      <OnboardingActions :label="t('onboarding.placement.back')" :arrow="false" @primary="emit('back')" />
    </template>

    <ErrorState
      v-else-if="placement.phase === 'idle' && placement.error"
      :message="placement.error"
      data-testid="placement-error"
      @retry="start"
    />

    <div v-else-if="question === null" class="flex flex-col items-center gap-3 pt-4" role="status" aria-busy="true" data-testid="placement-skeleton">
      <span class="sr-only">{{ t('onboarding.placement.loading') }}</span>
      <span class="skeleton h-1.5 w-full rounded-full" aria-hidden="true" />
      <span class="skeleton mt-8 h-9 w-40" aria-hidden="true" />
      <span class="skeleton h-5 w-32" aria-hidden="true" />
      <span v-for="n in 4" :key="n" class="skeleton mt-1 h-[60px] w-full rounded-md" aria-hidden="true" />
    </div>

    <template v-else>
      <div class="flex items-center gap-3 pt-2 pb-4" data-testid="placement-progress">
        <SegmentProgress
          :total="total"
          :current="index"
          :label="t('onboarding.placement.progress_label', { n: index + 1, total })"
          class="flex-1"
        />
        <span class="t-label-s font-mono shrink-0 text-muted" aria-hidden="true" data-testid="placement-counter">{{ index + 1 }} / {{ total }}</span>
      </div>

      <header class="flex flex-col items-center pt-4 text-center">
        <p class="t-label-s text-muted">{{ t('onboarding.placement.title') }}</p>
        <h1 class="t-display-l mt-3 break-words" lang="en" data-testid="placement-word">{{ question.word }}</h1>
        <p class="t-body-m mt-1.5 text-muted">{{ t('onboarding.placement.prompt') }}</p>
      </header>

      <div :key="String(question.id)" class="mt-6 flex flex-col gap-2.5" role="radiogroup" :aria-label="question.word" data-testid="placement-options">
        <QuizOption
          v-for="(option, i) in question.options"
          :key="i"
          :label="option"
          :index="i"
          :state="stateOf(i)"
          :disabled="picked !== null || sending || answered"
          @pick="pick"
        />
      </div>
      <button
        type="button"
        class="btn btn-plain btn-block mt-2"
        :aria-disabled="picked !== null || sending || answered ? 'true' : undefined"
        data-testid="placement-skip"
        @click="pick(null)"
      >
        {{ t('onboarding.placement.skip') }}
      </button>

      <div class="mt-auto pt-4 pb-4">
        <InlineAlert v-if="placement.error && answered && !sending" tone="danger" data-testid="placement-send-error">
          {{ placement.error }}
          <button type="button" class="ml-1 font-semibold underline underline-offset-2" data-testid="placement-retry" @click="retrySend">
            {{ at('common.retry') }}
          </button>
        </InlineAlert>
        <p v-else-if="sending" class="t-body-m flex items-center justify-center gap-2 text-muted" role="status" data-testid="placement-sending">
          <span class="btn-dots" aria-hidden="true"><span /><span /><span /></span>
          <span>{{ t('onboarding.placement.checking') }}</span>
        </p>
      </div>
    </template>
  </div>
</template>
