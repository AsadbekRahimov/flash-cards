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

/**
 * Set when the learner leaves the screen. Whatever comes back after that (the login, the reply to the last grade, the
 * summary of the session) must not carry them anywhere from the screen they are on now.
 */
let left = false

async function begin(): Promise<void> {
  try {
    await auth.ensure({ type: 'training', sessionId: props.sessionId })
    // Left while the login was on its way: nobody would see the session start.
    if (left) return
    await training.start(props.sessionId)
  } catch (err) {
    // start() handles its own errors; this is an auth failure.
    if (!left) training.handleApiError(err)
  }
}

onMounted(() => {
  void begin()
})

// "Start again" replaces the route with a new session id; the component is reused.
watch(() => props.sessionId, () => void begin())

onBeforeUnmount(() => {
  left = true
  training.clearOpenError()
})

/**
 * The session is over — however the store got to `finished` (the last grade, a verdict, "Next", "Retry", or a session
 * that has no card to start with) — and the finish screen follows, once. Not the usual batched watcher: a session that
 * is finished from its very first card goes `finished` (the last visit) -> `starting` -> `finished` within one tick, and
 * a batched watcher would see no change. It stops with the screen, so a reply that comes back after the learner left
 * does nothing.
 */
watch(
  status,
  (current) => {
    if (current === 'finished') void redirectToFinished()
  },
  { flush: 'sync' },
)

/** A flash card: the grade is the learner's own. */
function onGrade(q: Quality): void {
  void training.submitReview(q)
}

/**
 * Reverse / audio: the grade goes to the server at the pick, so it is saved even if the learner leaves at once; the
 * card it answers with is held back until the learner has seen the right answer (`onChoiceAdvance`).
 */
function onChoiceGrade(q: Quality): void {
  void training.submitChoice(q)
}

/** The pause after a right pick is over, or "Next" was pressed after a wrong one: show the held card. */
function onChoiceAdvance(): void {
  training.continueAfterChoice()
}

/** Spelling / gap-fill: the server grades; the verdict is shown before the next card. */
function onAnswer(text: string): void {
  lastAnswer.value = text
  void training.submitAnswer(text)
}

function onContinue(): void {
  training.continueAfterVerdict()
}

async function redirectToFinished(): Promise<void> {
  await training.finalize()
  // ✕ or "Back" while the summary was being asked: the session is closed on the server, but the learner is elsewhere.
  if (left) return
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
      <!-- The same way out as ✕ and the system "Back": Home for a personal session, the end of the Mini App for a group one. -->
      <button v-else type="button" class="btn btn-primary btn-block" :data-testid="isPersonal ? 'home-btn' : 'close-btn'" @click="leave">
        {{ isPersonal ? t('finished.home') : t('common.close') }}
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
        <ReverseCard
          v-if="exercise === 'reverse'"
          :key="`reverse-${serveSeq}`"
          :card="card"
          :disabled="isBusy"
          @grade="onChoiceGrade"
          @advance="onChoiceAdvance"
        />
        <AudioCard
          v-else-if="exercise === 'audio'"
          :key="`audio-${serveSeq}`"
          :card="card"
          :disabled="isBusy"
          @grade="onChoiceGrade"
          @advance="onChoiceAdvance"
        />
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
