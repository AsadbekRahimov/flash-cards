<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import QuizOption from '@/components/QuizOption.vue'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useTelegram } from '@/composables/useTelegram'
import { useTimer } from '@/composables/useTimer'

const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const exam = useExamStore()
const { close, showBackButton, haptic, notificationHaptic } = useTelegram()

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

let hideBack: (() => void) | null = null
let feedbackTimeout: ReturnType<typeof setTimeout> | null = null

onMounted(async () => {
  hideBack = showBackButton(() => close())
  try {
    await auth.ensure()
    await exam.join(props.sessionId)
  } catch {
    /* auth or join error → handled via store.handleApiError */
  }
})

onBeforeUnmount(() => {
  hideBack?.()
  if (feedbackTimeout) clearTimeout(feedbackTimeout)
})

// Restart per-question timer whenever a fresh question arrives.
watch(currentQuestion, (q) => {
  if (q !== null && status.value === 'answering') {
    questionTimer.start(secondsPerQuestion.value)
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
  void exam.loadQuestion(currentIndex.value)
}
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <header class="flex items-center justify-between mb-4">
      <div class="text-sm font-mono" data-testid="overall-timer">⏱ {{ overallLabel }}</div>
      <div class="text-sm text-tg-hint" data-testid="progress">
        {{ Math.min(currentIndex + 1, totalQuestions) }} / {{ totalQuestions }}
      </div>
      <div class="text-sm font-semibold" data-testid="score">★ {{ totalScore }}</div>
    </header>

    <div v-if="status === 'joining' || status === 'loading'"
         class="flex-1 flex items-center justify-center"
         data-testid="spinner">
      <p class="text-tg-hint">Загрузка…</p>
    </div>

    <div v-else-if="status === 'closed'"
         class="flex-1 flex items-center justify-center text-center"
         data-testid="closed">
      <div class="space-y-4">
        <p class="text-lg font-semibold">🔒 Экзамен завершён учителем</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" @click="close()">Закрыть</button>
      </div>
    </div>

    <div v-else-if="status === 'error'"
         class="flex-1 flex items-center justify-center text-center"
         data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ Ошибка</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" @click="onRetry">Повторить</button>
      </div>
    </div>

    <div v-else-if="currentQuestion" class="flex flex-col gap-5 flex-1">
      <!-- Per-question timer bar -->
      <div class="h-2 rounded-full overflow-hidden bg-tg-secondary-bg">
        <div
          class="h-full transition-[width] duration-1000 ease-linear"
          :class="questionTimer.remaining.value <= 3 ? 'bg-red-500' : 'bg-tg-button'"
          :style="{ width: `${Math.max(0, (questionTimer.remaining.value / Math.max(1, secondsPerQuestion)) * 100)}%` }"
          data-testid="question-timer"
        ></div>
      </div>

      <div class="text-center py-6">
        <h2 class="text-4xl font-bold break-words" data-testid="word">{{ currentQuestion.word }}</h2>
      </div>

      <div class="flex flex-col gap-3">
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
