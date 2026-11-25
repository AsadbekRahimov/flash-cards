<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AnswerVerdict from '@/components/AnswerVerdict.vue'
import AudioCard from '@/components/AudioCard.vue'
import ClosedState from '@/components/ClosedState.vue'
import FlashCard from '@/components/FlashCard.vue'
import GapFillCard from '@/components/GapFillCard.vue'
import PretestCard from '@/components/PretestCard.vue'
import PretestReveal from '@/components/PretestReveal.vue'
import ReverseCard from '@/components/ReverseCard.vue'
import SpellingCard from '@/components/SpellingCard.vue'
import WordNoteSheet from '@/components/WordNoteSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import MeaningCard from '@/components/MeaningCard.vue'
import { PRO_EXERCISES } from '@/composables/proReminders'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { COMBO_MIN, COMBO_STEP, useTrainingStore } from '@/stores/training'
import { useBackButton } from '@/composables/useBackButton'
import { useChromeSurface } from '@/composables/useChromeSurface'
import { playSound } from '@/composables/useFeedbackSound'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { Quality } from '@/api/types'

/**
 * The training screen (docs/14 §6.2, docs/design/2026-11-25-redesign Question / Feedback): an immersive white column without
 * the tab bar — the header (close, the progress in segments, «3 из 8», the pill of the session), the exercise in the body and its
 * actions in the sticky block at the bottom (each exercise component draws its own). `prompt.type` of the served card decides
 * which exercise it is.
 */
const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const training = useTrainingStore()
const { close, notificationHaptic } = useTelegram()
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
  lastExercise,
  savingNote,
  choiceResult,
  unsupportedExercise,
  combo,
  outcome,
  deferred,
} = storeToRefs(training)

/**
 * The "Pro" chip on the cards that are a feature of Pro (spelling, gap fill; the same list as the settings): shown to a learner who has Pro
 * - the trial, a paid one, a center's - so that they see this is what Pro gives; never in a group lesson. The server does not say which
 * types are paid (the ladder can open typed answers to Free, `typed_recall_in_free`), so a Free learner gets no chip: for them a typed card
 * is not a Pro one. The plan is the one `/today` or `/me` already brought; when neither did (opened from a reminder) there is no chip.
 */
const plan = computed(() => useTodayStore().data?.entitlement.plan ?? useBillingStore().entitlement?.plan ?? null)
const proChip = computed(() => plan.value === 'pro' && mode.value !== 'group_lesson' && PRO_EXERCISES.includes(exercise.value))

/** The text of the last typed answer, for the verdict screen. */
const lastAnswer = ref('')

/** "My hint" sheet (docs/06 §12.3) is open for the card on screen. */
const noteOpen = ref(false)

/** The limit of "my hint" (the server cuts to the same). */
const NOTE_MAX = 200

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

/** Up to this many cards the progress is drawn as segments (docs/design Question: 8); a longer session gets the line. */
const SEGMENTS_MAX = 12

/** The number of progress segments; 0 = the line instead (no total yet, or too many cards for segments at 320 px). */
const segments = computed((): number => (progress.value.total > 0 && progress.value.total <= SEGMENTS_MAX ? progress.value.total : 0))

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
 * The pill under the header, by the mode (the round of hard words has its own row): a lesson (personal or of a group) — its
 * name; the review of a lesson (`lesson_review`) — «Повторение · <lesson>»; the daily review — «Раунд». Nothing else is asked
 * of the server: the mode and the lesson come with the session.
 */
const subtitle = computed((): { text: string; testid: string } | null => {
  if (mode.value === 'lesson_review') {
    return {
      text: lesson.value ? t('training.lesson_review_header', { lesson: lessonHeader.value }) : t('training.lesson_review_header_plain'),
      testid: 'lesson-review-header',
    }
  }
  if (lesson.value) return { text: lessonHeader.value, testid: 'lesson-header' }
  if (mode.value === 'daily_review') return { text: t('training.daily_header'), testid: 'daily-header' }
  return null
})

/**
 * The line of the answer panel after a miss (docs/design Feedback «Слово вернётся ещё раз в этом уроке»). The queue is the
 * server's: a missed word goes back to the minute steps (`learning` / `relearning`) and the picker serves it again in the same
 * session through learn-ahead (docs/06 §4.2, step 3; the round of hard words — §14). So the promise is made only when the
 * session goes on — the answer brought the next card (`deferred.card`) — and only in the modes whose queue does that: a lesson
 * («…в этом уроке») and the daily / hard round («…в этом раунде»). Otherwise — the session is over with this answer, or the
 * review of a lesson, which serves a word at most a few times (`learning.lesson_review.max_serves_per_word`, unknown here) — a
 * neutral line that promises nothing. Not covered by the promise: a session cut by `twa.training.max_cards_per_session` or a
 * server with learn-ahead switched off (`learning.learn_ahead_minutes = 0`) — the client cannot see either.
 */
const missNote = computed((): string => {
  const continues = deferred.value !== null && deferred.value.card !== null
  if (continues && (mode.value === 'lesson' || mode.value === 'group_lesson')) return t('exercise.miss.returns_lesson')
  if (continues && (mode.value === 'daily_review' || mode.value === 'hard_review')) return t('exercise.miss.returns_round')
  return t('exercise.miss.neutral')
})

/**
 * A personal session (lesson / daily review, or one this run has just opened) saves every answer as it goes, so "Close"
 * and the system "Back" return to the home tab (docs/14 §5.3, N3). A group lesson (opened from a chat) and a session
 * this run knows nothing about close the Mini App, as they always did.
 */
const isPersonal = computed(
  (): boolean =>
    mode.value === 'lesson' || mode.value === 'daily_review' || mode.value === 'hard_review' || opened.value?.sessionId === props.sessionId,
)

function leave(): void {
  if (isPersonal.value) void router.replace({ name: 'today' })
  else close()
}

useBackButton(leave)

// The screen is white (`bg-surface`): Telegram's header and window background take the same colour (docs/14 §4.1).
useChromeSurface()

/**
 * Set when the learner leaves the screen. Whatever comes back after that (the login, the reply to the last grade, the
 * summary of the session) must not carry them anywhere from the screen they are on now.
 */
let left = false
let beginSequence = 0

async function begin(): Promise<void> {
  const sequence = ++beginSequence
  const sessionId = props.sessionId
  try {
    await auth.ensure({ type: 'training', sessionId })
    // Left while the login was on its way: nobody would see the session start.
    if (left || sequence !== beginSequence) return
    await training.start(sessionId)
  } catch (err) {
    // start() handles its own errors; this is an auth failure.
    if (!left && sequence === beginSequence) training.handleApiError(err)
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

/** The chip of the run of right answers (from `COMBO_MIN` in a row); every `COMBO_STEP`-th answer of the run is a milestone. */
const showCombo = computed(() => combo.value >= COMBO_MIN)
const comboMilestone = computed(() => showCombo.value && combo.value % COMBO_STEP === 0)

/**
 * Every answer that counted for the run: the short sound (right / wrong; "not yet" on a flash card is an honest grade, no
 * sound) and, on a milestone of the run, the success haptic — except after a four-choice card, whose own verdict has just
 * given that haptic.
 */
watch(
  () => outcome.value.seq,
  () => {
    const { kind } = outcome.value
    if (kind === 'right') playSound('right')
    else if (kind === 'wrong') playSound('wrong')
    if (comboMilestone.value && !['meaning', 'reverse', 'audio'].includes(lastExercise.value ?? '')) notificationHaptic('success')
  },
)

/** A flash card: the grade is the learner's own. */
function onGrade(q: Quality): void {
  void training.submitReview(q)
}

/**
 * Meaning / reverse / audio: the picked option goes to the server at the pick (it grades it, docs/06 §12.2), so it is saved even if
 * the learner leaves at once; the card has no right option in it, the options are coloured by `choiceResult` when the
 * server answers; the card it answers with is held back until the learner has seen the right answer (`onChoiceAdvance`).
 */
function onChoicePick(picked: string): void {
  void training.submitChoice(picked)
}

/** The pretest pick (or "I don't know" = ''): the server grades, the reveal shows the meaning. */
function onPretestAnswer(text: string): void {
  lastAnswer.value = text
  void training.submitAnswer(text)
}

async function onSaveNote(note: string): Promise<void> {
  if (await training.saveNote(note)) noteOpen.value = false
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
  const generation = training.generation
  await training.finalize()
  // ✕ or "Back" while the summary was being asked: the session is closed on the server, but the learner is elsewhere.
  if (left || generation !== training.generation || training.sessionId !== props.sessionId) return
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
  if (training.sessionId === props.sessionId && training.mode !== null) void training.loadNext()
  else void begin()
}

/** A personal session ended (finished elsewhere or expired): open the same kind again. */
async function onRestart(): Promise<void> {
  const request = restartRequest.value
  if (request === null) return
  const sessionId = await training.open(request)
  if (!left && sessionId !== null) await router.replace({ name: 'training', params: { sessionId } })
}
</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col bg-surface"
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
        {{ isPersonal ? t('finished.home') : t('common.exit_app') }}
      </button>
    </ClosedState>

    <template v-else>
      <!--
        docs/design/2026-11-25-redesign Question: ✕, the progress as segments (one per card of a short session; a line when there
        are too many for segments) and «3 из 8»; under them a pill that says what this session is (the lesson, the round…).
      -->
      <header class="px-5 pt-3">
        <div class="flex items-center gap-3">
          <button
            type="button"
            class="-ml-3 flex size-11 shrink-0 items-center justify-center rounded-full text-muted active:bg-fill"
            :aria-label="t('common.close')"
            data-testid="close-btn"
            @click="leave"
          >
            <AppIcon name="x-mark" :size="20" />
          </button>
          <div
            v-if="segments > 0"
            class="grid flex-1 gap-1"
            :style="{ gridTemplateColumns: `repeat(${segments}, minmax(0, 1fr))` }"
            aria-hidden="true"
            data-testid="progress-bar"
          >
            <span
              v-for="n in segments"
              :key="n"
              class="h-1.5 rounded-full"
              :class="n <= progress.done ? 'bg-primary' : 'bg-track'"
              :data-done="n <= progress.done ? 'true' : undefined"
              data-testid="progress-segment"
            />
          </div>
          <ProgressLine v-else :value="progressPercent" tone="primary" class="h-1.5 flex-1" data-testid="progress-bar" />
          <Transition name="combo">
            <span
              v-if="showCombo"
              :key="comboMilestone ? `milestone-${combo}` : 'combo'"
              class="chip chip-accent shrink-0"
              :class="{ 'combo-pop': comboMilestone }"
              data-testid="combo"
              :aria-label="t('training.combo_label', { count: combo })"
            >
              <span class="font-mono font-medium">{{ combo }}</span> {{ t('training.combo') }}
            </span>
          </Transition>
          <span
            class="shrink-0 text-right text-sm leading-5 font-semibold text-muted tabular-nums"
            :class="{ invisible: progress.total === 0 }"
            data-testid="progress"
          >
            {{ t('training.progress.counter', { done: progress.done, total: progress.total }) }}
          </span>
        </div>
        <!-- What this session is: one pill (docs/design Question «Урок 3 · Напитки»), its text by the mode. -->
        <div v-if="mode === 'hard_review'" class="mt-4 flex flex-wrap items-center gap-x-2.5 gap-y-1" data-testid="hard-round-header">
          <span class="block h-7 max-w-full min-w-0 truncate rounded-full bg-accent-soft px-3 text-[13px] leading-7 font-bold text-accent-ink" data-testid="hard-round-chip">{{ t('training.hard_round.chip') }}</span>
          <!-- The round of hard words: how many of its words are won (`progress.done`, docs/06 §14). -->
          <span class="text-[13px] leading-[18px] text-muted" data-testid="hard-round-progress">
            {{ t('training.hard_round.progress', { done: progress.done, total: progress.total }) }}
          </span>
        </div>
        <p v-else-if="subtitle" class="mt-4 flex" :data-testid="subtitle.testid">
          <span class="block h-7 max-w-full min-w-0 truncate rounded-full bg-accent-soft px-3 text-[13px] leading-7 font-bold text-accent-ink">{{ subtitle.text }}</span>
        </p>
      </header>

      <div class="card-stage">
      <Transition name="card-swap">
      <LoadingState v-if="showSpinner" variant="center" />

      <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="onRetry" />

      <PretestReveal
        v-else-if="status === 'verdict' && verdict && lastExercise === 'pretest' && card"
        :card="card"
        :verdict="verdict"
        @continue="onContinue"
      />

      <AnswerVerdict
        v-else-if="status === 'verdict' && verdict"
        :verdict="verdict"
        :answer="lastAnswer"
        :miss-note="missNote"
        @continue="onContinue"
      />

      <!--
        An exercise this build does not know (a newer server): it cannot be shown as a flash card — the answer is not in the card —
        so the learner is asked to reopen the Mini App, which loads the current build (the session keeps its place).
      -->
      <ClosedState
        v-else-if="card && unsupportedExercise"
        icon="arrow-path"
        :title="t('training.update.title')"
        :text="t('training.update.text')"
        data-testid="update-required"
      >
        <button type="button" class="btn btn-primary btn-block" data-testid="update-close-btn" @click="close">
          {{ t('common.exit_app') }}
        </button>
      </ClosedState>

      <!--
        The exercise of the serve (docs/13 §2.5): `prompt.type` decides the screen; the key restarts the component per serve.
        The cards swap with one short transition (`card-swap`, docs/14 §6.2); the old one fades out on top of the new one,
        so the next card is on screen the moment it is there, with nothing waited for.
      -->
      <MeaningCard
        v-else-if="card && exercise === 'meaning'"
        :key="`meaning-${serveSeq}`"
        :card="card"
        :disabled="isBusy"
        :result="choiceResult"
        :miss-note="missNote"
        @pick="onChoicePick"
        @advance="onChoiceAdvance"
      />
      <ReverseCard
        v-else-if="card && exercise === 'reverse'"
        :key="`reverse-${serveSeq}`"
        :card="card"
        :disabled="isBusy"
        :result="choiceResult"
        :miss-note="missNote"
        @pick="onChoicePick"
        @advance="onChoiceAdvance"
      />
      <AudioCard
        v-else-if="card && exercise === 'audio'"
        :key="`audio-${serveSeq}`"
        :card="card"
        :disabled="isBusy"
        :result="choiceResult"
        :miss-note="missNote"
        @pick="onChoicePick"
        @advance="onChoiceAdvance"
      />
      <SpellingCard v-else-if="card && exercise === 'spelling'" :key="`spelling-${serveSeq}`" :card="card" :disabled="isBusy" :pro-chip="proChip" @answer="onAnswer" />
      <GapFillCard v-else-if="card && exercise === 'gap_fill'" :key="`gap-${serveSeq}`" :card="card" :disabled="isBusy" :pro-chip="proChip" @answer="onAnswer" />
      <PretestCard v-else-if="card && exercise === 'pretest'" :key="`pretest-${serveSeq}`" :card="card" :disabled="isBusy" @answer="onPretestAnswer" />
      <FlashCard
        v-else-if="card"
        :key="`flash-${serveSeq}`"
        :card="card"
        :show-translation="training.showTranslation"
        :disabled="isBusy"
        @reveal="training.reveal()"
        @grade="onGrade"
        @edit-note="noteOpen = true"
      />
    </Transition>
    </div>

      <WordNoteSheet
        v-if="noteOpen && card"
        :word="card.word"
        :note="card.note ?? null"
        :saving="savingNote"
        :max="NOTE_MAX"
        @save="onSaveNote"
        @close="noteOpen = false"
      />
    </template>
  </section>
</template>
