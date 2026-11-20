<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import ClosedState from '@/components/ClosedState.vue'
import QuizOption from '@/components/QuizOption.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useBackButton } from '@/composables/useBackButton'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import { useTimer } from '@/composables/useTimer'

/**
 * The exam screen (docs/14 §6.4), group exam and personal mock: an immersive column without the tab bar. The header
 * holds the countdown of the whole exam, the question counter and the score (a mock has the "Mock test" chip instead);
 * under it a group exam shows the bar of the time left for the question, then the word in a card and the four options.
 */
const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const exam = useExamStore()

// A personal IELTS / SAT mock (docs/13 §8.4) is reached from the course screen with `?mode=mock`:
// one timer for the whole exam, no per-question countdown, and its own "time is over" screen.
const isMock = computed((): boolean => route.query.mode === 'mock' || exam.mode === 'personal_mock')
// The test of the week (docs/04 A.20) is reached from the readiness screen with `?mode=quiz`; it plays like a mock.
const isQuiz = computed((): boolean => route.query.mode === 'quiz' || exam.mode === 'level_quiz')
const singleTimer = computed((): boolean => isMock.value || isQuiz.value)
const { close, haptic, notificationHaptic } = useTelegram()
const { t } = useI18n()

const {
  status,
  currentQuestion,
  currentIndex,
  totalQuestions,
  selectedIndex,
  lastAnswer,
  totalScore,
  errorMessage,
  secondsPerQuestion,
} = storeToRefs(exam)

// Per-question countdown. On expiry → submit "no answer" (null).
const questionTimer = useTimer(() => {
  if (exam.status === 'answering') {
    void exam.submitAnswer(null)
  }
})

// Overall countdown (tenth-of-seconds precision not needed — show MM:SS).
const overallTimer = useTimer(() => {
  // Server will close the session; client gracefully falls through to finish.
  if (exam.status !== 'finished' && exam.status !== 'closed' && exam.status !== 'error') {
    void exam.finish()
  }
})

const overallLabel = computed((): string => {
  const s = overallTimer.remaining.value
  const mm = String(Math.floor(s / 60)).padStart(2, '0')
  const ss = String(s % 60).padStart(2, '0')
  return `${mm}:${ss}`
})

/** The last half minute of the exam: the countdown chip turns red. */
const overallLow = computed((): boolean => overallTimer.remaining.value < 30)

/** The last seconds of the question: its bar turns red. */
const questionLow = computed((): boolean => questionTimer.remaining.value <= 3)

const questionBarWidth = computed((): string =>
  `${Math.max(0, (questionTimer.remaining.value / Math.max(1, secondsPerQuestion.value)) * 100)}%`,
)

const isInteractive = computed((): boolean => status.value === 'answering')

function optionState(index: number): 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal' {
  if (status.value === 'answering' || status.value === 'submitting') {
    return selectedIndex.value === index ? 'selected' : 'idle'
  }
  if (status.value === 'feedback' && lastAnswer.value) {
    if (index === lastAnswer.value.correct_option) {
      return selectedIndex.value === index ? 'correct' : 'reveal'
    }
    if (selectedIndex.value === index) return 'incorrect'
    return 'idle'
  }
  return 'idle'
}

// "Back" closes the Mini App: an exam is not left halfway for a tab (docs/14 §5.3).
useBackButton(() => close())

let feedbackTimeout: ReturnType<typeof setTimeout> | null = null

let left = false
let beginSequence = 0

async function begin(): Promise<void> {
  const sequence = ++beginSequence
  const sessionId = props.sessionId
  try {
    await auth.ensure({ type: 'exam', sessionId })
    if (left || sequence !== beginSequence) return
    await exam.join(sessionId, route.query.mode === 'mock' ? 'personal_mock' : route.query.mode === 'quiz' ? 'level_quiz' : 'group_exam')
  } catch (err) {
    // join() handles its own errors; this is an auth failure.
    if (!left && sequence === beginSequence) exam.handleApiError(err)
  }
}

onMounted(() => void begin())
watch(() => props.sessionId, () => {
  questionTimer.stop()
  overallTimer.stop()
  if (feedbackTimeout) clearTimeout(feedbackTimeout)
  void begin()
})

onBeforeUnmount(() => {
  left = true
  if (feedbackTimeout) clearTimeout(feedbackTimeout)
})

// Restart per-question timer whenever a fresh question arrives (a mock has only the overall timer).
watch(currentQuestion, (q) => {
  if (q !== null && status.value === 'answering' && !singleTimer.value) {
    questionTimer.start(Math.min(secondsPerQuestion.value, q.seconds_left))
  }
})

// Start overall countdown once we know endsAt.
watch(
  () => exam.endsAt,
  (endsAt) => {
    if (endsAt === null) return
    const left = Math.max(0, Math.floor((endsAt - Date.now()) / 1000))
    overallTimer.start(left)
  },
)

// Show feedback for ~800 ms, then advance. Navigate to result on finish.
watch(status, (s) => {
  if (s === 'feedback') {
    questionTimer.stop()
    notificationHaptic(lastAnswer.value?.is_correct ? 'success' : 'error')
    if (feedbackTimeout) clearTimeout(feedbackTimeout)
    feedbackTimeout = setTimeout(() => void exam.advance(), 800)
  }
  if (s === 'finished') {
    void router.replace({ name: 'exam-result', params: { sessionId: exam.sessionId ?? 0 } })
  }
})

async function pick(optionIndex: number): Promise<void> {
  if (!isInteractive.value) return
  haptic('medium')
  await exam.submitAnswer(optionIndex)
}

function onRetry(): void {
  if (exam.sessionId !== props.sessionId || exam.totalQuestions === 0) void begin()
  else void exam.loadQuestion(currentIndex.value)
}
</script>

<template>
  <!-- A white immersive screen, as a question of a lesson (docs/design Question.html): no cards, the word large, the options below. -->
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col bg-surface"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    data-testid="exam-view"
  >
    <header
      v-if="totalQuestions > 0 && status !== 'closed'"
      class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 px-5 pt-3"
    >
      <div class="flex justify-start">
        <span
          v-if="exam.endsAt !== null"
          class="chip font-mono"
          :class="overallLow ? 'chip-danger' : 'chip-neutral'"
          data-testid="overall-timer"
        >
          <AppIcon name="clock" :size="14" />{{ overallLabel }}
        </span>
      </div>
      <span class="font-mono text-[13px] leading-[18px] text-muted" data-testid="progress">
        {{ Math.min(currentIndex + 1, totalQuestions) }} / {{ totalQuestions }}
      </span>
      <div class="flex justify-end">
        <span v-if="isQuiz" class="chip chip-accent" data-testid="quiz-header">{{ t('exam.quiz_header') }}</span>
        <span v-else-if="isMock" class="chip chip-accent" data-testid="mock-header">{{ t('exam.mock_header') }}</span>
        <span v-else class="chip chip-neutral font-mono" data-testid="score">
          <AppIcon name="star" solid :size="14" class="text-warning" />{{ totalScore }}
        </span>
      </div>
    </header>

    <LoadingState v-if="status === 'joining' || status === 'loading'" variant="center" />

    <ClosedState
      v-else-if="status === 'closed'"
      :icon="singleTimer ? 'clock' : 'lock-closed'"
      :title="isQuiz ? t('exam.quiz_closed_title') : isMock ? t('exam.mock_closed_title') : t('exam.closed_title')"
      :text="singleTimer ? undefined : errorMessage"
    >
      <button
        v-if="singleTimer"
        type="button"
        class="btn btn-primary btn-block"
        :data-testid="isQuiz ? 'quiz-results-btn' : 'mock-results-btn'"
        @click="router.replace({ name: 'exam-result', params: { sessionId: props.sessionId } })"
      >
        {{ isQuiz ? t('result.quiz_title') : t('result.mock_title') }}
      </button>
      <button v-else type="button" class="btn btn-primary btn-block" data-testid="close-btn" @click="close()">
        {{ t('common.exit_app') }}
      </button>
    </ClosedState>

    <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="onRetry" />

    <div
      v-else-if="currentQuestion"
      class="flex flex-1 flex-col px-5 pt-3"
      style="padding-bottom: calc(1.5rem + var(--app-safe-b))"
    >
      <!-- The time left for the question (group exams only: a mock has one timer for the whole test). -->
      <div v-if="!singleTimer" class="progress h-[3px]" aria-hidden="true">
        <div
          class="progress-fill duration-1000 ease-linear"
          :class="{ 'bg-danger': questionLow }"
          :style="{ width: questionBarWidth }"
          data-testid="question-timer"
        ></div>
      </div>

      <div class="mt-12 text-center short:mt-8">
        <h2 class="text-[40px] leading-[46px] font-semibold tracking-[-0.6px] break-words" data-testid="word">{{ currentQuestion.word }}</h2>
      </div>

      <div class="mt-10 flex flex-col gap-2.5 short:mt-6">
        <QuizOption
          v-for="(label, i) in currentQuestion.options"
          :key="i"
          :label="label"
          :index="i"
          :state="optionState(i)"
          :disabled="!isInteractive"
          @pick="pick"
        />
      </div>
    </div>
  </section>
</template>
