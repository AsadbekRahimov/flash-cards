<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AnswerVerdict from '@/components/AnswerVerdict.vue'
import AudioCard from '@/components/AudioCard.vue'
import FlashCard from '@/components/FlashCard.vue'
import GapFillCard from '@/components/GapFillCard.vue'
import ReverseCard from '@/components/ReverseCard.vue'
import SpellingCard from '@/components/SpellingCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useTrainingStore } from '@/stores/training'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { Quality } from '@/api/types'

const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const training = useTrainingStore()
const { close, showBackButton } = useTelegram()
const { t } = useI18n()

const { status, card, progress, lesson, mode, errorMessage, restartRequest, opening, openError, exercise, verdict } =
  storeToRefs(training)

/** The text of the last typed answer, for the verdict screen. */
const lastAnswer = ref('')

const isBusy = computed(() => status.value === 'submitting' || status.value === 'loading')
const showSpinner = computed(() =>
  status.value === 'starting' || (status.value === 'loading' && card.value === null),
)

let hideBack: (() => void) | null = null

async function begin(): Promise<void> {
  try {
    await auth.ensure({ type: 'training', sessionId: props.sessionId })
    await training.start(props.sessionId)
    if (training.status === 'finished') {
      void redirectToFinished()
    }
  } catch (err) {
    // start() handles its own errors; this is an auth failure.
    training.handleApiError(err)
  }
}

onMounted(() => {
  hideBack = showBackButton(() => close())
  void begin()
})

// "Start again" replaces the route with a new session id; the component is reused.
watch(() => props.sessionId, () => void begin())

onBeforeUnmount(() => {
  hideBack?.()
  training.clearOpenError()
})

async function onGrade(q: Quality): Promise<void> {
  await training.submitReview(q)
  if (training.status === 'finished') {
    await redirectToFinished()
  }
}

/** Spelling / gap-fill: the server grades; the verdict is shown before the next card. */
async function onAnswer(text: string): Promise<void> {
  lastAnswer.value = text
  await training.submitAnswer(text)
  if (training.status === 'finished') {
    await redirectToFinished()
  }
}

async function onContinue(): Promise<void> {
  training.continueAfterVerdict()
  if (training.status === 'finished') {
    await redirectToFinished()
  }
}

async function redirectToFinished(): Promise<void> {
  await training.finalize()
  await router.replace({
    name: 'finished',
    query: {
      // The server counts the whole personal session, also what was graded before this visit.
      reviewed: String(training.summary?.reviewed ?? training.reviewedCount),
      next: String(training.lastIntervalDays),
      ...(training.doneReason ? { reason: training.doneReason } : {}),
    },
  })
}

function onRetry(): void {
  void training.loadNext()
}

/** A personal session ended (finished elsewhere or expired): open the same kind again. */
async function onRestart(): Promise<void> {
  const request = restartRequest.value
  if (request === null) return
  const sessionId = await training.open(request)
  if (sessionId !== null) await router.replace({ name: 'training', params: { sessionId } })
}
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <header class="flex items-center justify-between mb-4">
      <div class="text-sm text-tg-hint" data-testid="progress">
        {{ progress.done }} / {{ progress.total }}
      </div>
      <div v-if="lesson" class="text-sm font-semibold">
        {{ t('training.lesson_header', { stage: lesson.stage ?? '—', lesson: lesson.lesson }) }}
      </div>
      <div v-else-if="mode === 'daily_review'" class="text-sm font-semibold" data-testid="daily-header">
        {{ t('training.daily_header') }}
      </div>
      <button
        type="button"
        class="text-tg-hint"
        :aria-label="t('common.close')"
        @click="close()"
      >
        ✕
      </button>
    </header>

    <div v-if="showSpinner" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="status === 'closed'" class="flex-1 flex items-center justify-center text-center" data-testid="closed">
      <div class="space-y-4">
        <p class="text-lg font-semibold">{{ t('training.closed_title') }}</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <p v-if="openError" class="text-sm text-red-600" data-testid="open-error">{{ openError }}</p>
        <button
          v-if="restartRequest"
          class="btn btn-primary"
          :disabled="opening"
          data-testid="restart-btn"
          @click="onRestart"
        >
          {{ t('training.restart') }}
        </button>
        <button
          v-else
          class="btn btn-primary"
          data-testid="close-btn"
          @click="close()"
        >
          {{ t('common.close') }}
        </button>
      </div>
    </div>

    <div v-else-if="status === 'error'" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="onRetry">{{ t('common.retry') }}</button>
      </div>
    </div>

    <AnswerVerdict v-else-if="status === 'verdict' && verdict" :verdict="verdict" :answer="lastAnswer" @continue="onContinue" />

    <!-- The exercise of the serve (docs/13 §2.5): `prompt.type` decides the screen; the key restarts the component per card. -->
    <template v-else-if="card">
      <ReverseCard v-if="exercise === 'reverse'" :key="`reverse-${card.word_id}`" :card="card" :disabled="isBusy" @grade="onGrade" />
      <AudioCard v-else-if="exercise === 'audio'" :key="`audio-${card.word_id}`" :card="card" :disabled="isBusy" @grade="onGrade" />
      <SpellingCard v-else-if="exercise === 'spelling'" :key="`spelling-${card.word_id}`" :card="card" :disabled="isBusy" @answer="onAnswer" />
      <GapFillCard v-else-if="exercise === 'gap_fill'" :key="`gap-${card.word_id}`" :card="card" :disabled="isBusy" @answer="onAnswer" />
      <FlashCard
        v-else
        :card="card"
        :show-translation="training.showTranslation"
        :disabled="isBusy"
        @reveal="training.reveal()"
        @grade="onGrade"
      />
    </template>
  </section>
</template>
