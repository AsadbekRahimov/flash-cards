<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AnswerVerdict from '@/components/AnswerVerdict.vue'
import AudioCard from '@/components/AudioCard.vue'
import ClosedState from '@/components/ClosedState.vue'
import FlashCard from '@/components/FlashCard.vue'
import GapFillCard from '@/components/GapFillCard.vue'
import ReverseCard from '@/components/ReverseCard.vue'
import SpellingCard from '@/components/SpellingCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { useAuthStore } from '@/stores/auth'
import { useTrainingStore } from '@/stores/training'
import { useBackButton } from '@/composables/useBackButton'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { Quality } from '@/api/types'

/**
 * The training screen (docs/14 §6.2): an immersive column without the tab bar — the header (close, progress, counter),
 * the card in the body and the actions of the exercise in the sticky block at the bottom (each exercise component
 * draws its own). `prompt.type` of the served card decides which exercise it is.
 */
const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const training = useTrainingStore()
const { close } = useTelegram()
const { t } = useI18n()

const {
  status,
  card,
  progress,
  lesson,
  mode,
  errorMessage,
  restartRequest,
  opening,
  openError,
  exercise,
  verdict,
  opened,
  serveSeq,
} = storeToRefs(training)

/** The text of the last typed answer, for the verdict screen. */
const lastAnswer = ref('')

const isBusy = computed(() => status.value === 'submitting' || status.value === 'loading')
const showSpinner = computed(
  () =>
    status.value === 'idle'
    || status.value === 'starting'
    // A finished session is on its way to the finish screen (the summary is requested first).
    || status.value === 'finished'
    || (status.value === 'loading' && card.value === null),
)

const progressPercent = computed((): number =>
  progress.value.total > 0 ? Math.min(100, Math.round((progress.value.done / progress.value.total) * 100)) : 0,
)

/** «Модуль 2 · Урок 4 · Еда и напитки»; a lesson without a module is just «Урок 4». */
const lessonHeader = computed((): string => {
  const current = lesson.value
  if (current === null) return ''
  const head =
    current.stage === null
      ? t('training.lesson_header_no_stage', { lesson: current.lesson })
      : t('training.lesson_header', { stage: current.stage, lesson: current.lesson })
  return current.title ? `${head} · ${current.title}` : head
})

/**
 * A personal session (lesson / daily review, or one this run has just opened) saves every answer as it goes, so "Close"
 * and the system "Back" return to the home tab (docs/14 §5.3, N3). A group lesson (opened from a chat) and a session
 * this run knows nothing about close the Mini App, as they always did.
 */
const isPersonal = computed(
  (): boolean =>
    mode.value === 'lesson' || mode.value === 'daily_review' || opened.value?.sessionId === props.sessionId,
)

function leave(): void {
  if (isPersonal.value) void router.replace({ name: 'today' })
  else close()
}

useBackButton(leave)

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
  void begin()
})

// "Start again" replaces the route with a new session id; the component is reused.
watch(() => props.sessionId, () => void begin())

onBeforeUnmount(() => {
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
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    data-testid="training-view"
  >
    <ClosedState v-if="status === 'closed'" icon="lock-closed" :title="t('training.closed_title')" :text="errorMessage">
      <InlineAlert v-if="openError" tone="danger" :message="openError" data-testid="open-error" />
      <button
        v-if="restartRequest"
        type="button"
        class="btn btn-primary btn-block"
        :disabled="opening"
        data-testid="restart-btn"
        @click="onRestart"
      >
        {{ t('training.restart') }}
      </button>
      <button v-else type="button" class="btn btn-primary btn-block" data-testid="close-btn" @click="close()">
        {{ t('common.close') }}
      </button>
    </ClosedState>

    <template v-else>
      <header class="px-4 pt-3">
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="-ml-1 flex size-11 shrink-0 items-center justify-center"
            :aria-label="t('common.close')"
            data-testid="close-btn"
            @click="leave"
          >
            <span class="flex size-9 items-center justify-center rounded-full bg-track text-tg-subtitle">
              <AppIcon name="x-mark" :size="20" />
            </span>
          </button>
          <div class="progress flex-1" aria-hidden="true" data-testid="progress-bar">
            <div class="progress-fill" :style="{ width: `${progressPercent}%` }" data-testid="progress-fill" />
          </div>
          <span
            class="min-w-14 text-right text-[15px] leading-5 font-medium text-tg-subtitle tabular-nums"
            :class="{ invisible: progress.total === 0 }"
            data-testid="progress"
          >
            {{ progress.done }} / {{ progress.total }}
          </span>
        </div>
        <p v-if="lesson" class="mt-1 text-center text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="lesson-header">
          {{ lessonHeader }}
        </p>
        <p v-else-if="mode === 'daily_review'" class="mt-1 text-center text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="daily-header">
          {{ t('training.daily_header') }}
        </p>
      </header>

      <LoadingState v-if="showSpinner" variant="center" />

      <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="onRetry" />

      <AnswerVerdict v-else-if="status === 'verdict' && verdict" :verdict="verdict" :answer="lastAnswer" @continue="onContinue" />

      <!-- The exercise of the serve (docs/13 §2.5): `prompt.type` decides the screen; the key restarts the component per serve. -->
      <template v-else-if="card">
        <ReverseCard v-if="exercise === 'reverse'" :key="`reverse-${serveSeq}`" :card="card" :disabled="isBusy" @grade="onGrade" />
        <AudioCard v-else-if="exercise === 'audio'" :key="`audio-${serveSeq}`" :card="card" :disabled="isBusy" @grade="onGrade" />
        <SpellingCard v-else-if="exercise === 'spelling'" :key="`spelling-${serveSeq}`" :card="card" :disabled="isBusy" @answer="onAnswer" />
        <GapFillCard v-else-if="exercise === 'gap_fill'" :key="`gap-${serveSeq}`" :card="card" :disabled="isBusy" @answer="onAnswer" />
        <FlashCard
          v-else
          :card="card"
          :show-translation="training.showTranslation"
          :disabled="isBusy"
          @reveal="training.reveal()"
          @grade="onGrade"
        />
      </template>
    </template>
  </section>
</template>
