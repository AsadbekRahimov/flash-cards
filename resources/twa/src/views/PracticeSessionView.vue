<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { storeToRefs } from 'pinia'
import ClosedState from '@/components/ClosedState.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import StickyActions from '@/components/StickyActions.vue'
import AnswerReveal from '@/components/practice/AnswerReveal.vue'
import AudioTrouble from '@/components/practice/AudioTrouble.vue'
import PracticeSummary from '@/components/practice/PracticeSummary.vue'
import TimedBar from '@/components/practice/TimedBar.vue'
import { modeComponentFor } from '@/components/practice/modeComponents'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { progressPercent } from '@/composables/courseLabels'
import { hideErrorToast, showErrorToast } from '@/composables/errorToast'
import { lessonHeader, verdictLabel } from '@/composables/practiceLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useChromeSurface } from '@/composables/useChromeSurface'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { usePracticeStore } from '@/stores/practice'
import { useI18n } from '@/i18n'
import type { PracticeAnswerPayload } from '@/api/types'

/**
 * A solo practice session (docs/16 §11.2, docs/14 §6.2): an immersive column without the tab bar — the header (✕, the
 * progress, "3 / 5", and under it the module / lesson), the element in the body and its actions at the bottom.
 *
 * `status` of the store decides what is drawn:
 * - `idle` / `starting` — a spinner; `error` — the message with "Retry" and the way back; `closed` (410) — "Practice is
 *   over" and the way back;
 * - `ready` / `submitting` — the component of the mode (`modeComponents.ts`), disabled while the answer is on its way;
 * - `verdict` — `AnswerReveal`: the verdict (a symbol, a colour and words), the explanation and "Next";
 * - `finished` — `PracticeSummary`.
 * A dictation whose clip does not load or play opens `AudioTrouble` over it; a refusal that leaves the learner on the
 * element (no network, a rule of the server) is a toast. The `PaywallSheet` opens over the session when the server says
 * `practice_pro_required`; "Again" on the summary opens the one of the free limit (`limitHit`) when today's free sessions are spent
 * (a repeat of mistakes spends nothing, so it is never stopped by it). "No sound for now" - in that sheet, and the button the dictation
 * always has - leads to the hub with the session dropped on purpose (`practice.abandonSession()`): the mode chosen there starts over it
 * without asking.
 *
 * ✕ and the system "Back" lead to the hub of the session (docs/16 §11.1): the answers are saved as they go, nothing is
 * asked. When the store knows nothing yet (a link straight to the session) they lead to the courses.
 */
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()
const practice = usePracticeStore()
const audio = usePracticeAudio()
const { close, haptic, notificationHaptic } = useTelegram()
const { t } = useI18n()

const { status, session, outcome, errorMessage, actionError, paywall, limitHit, retryUnavailable, opening, elementSeq, progress, current, timed, timedDeadline, timeUp, timedStarting, penalty } =
  storeToRefs(practice)

/** The hub the session belongs to; the courses while nothing is known about it. */
function hubLocation(): RouteLocationRaw {
  const scope = practice.scope
  if (scope === null) return { name: 'courses' }
  return {
    name: 'practice-hub',
    params: { slug: scope.course },
    query: scope.lessonId !== null ? { lesson: String(scope.lessonId) } : {},
  }
}

/** Back to the hub: one step back in the history when that is where the learner came from, so the hub keeps its place. */
function leave(): void {
  const target = hubLocation()
  if (router.options.history.state.back === router.resolve(target).fullPath) router.back()
  else void router.replace(target)
}

useBackButton(leave)

// White (`bg-surface`) while a task is on it, the page colour for the summary: Telegram's header and window background follow
// (docs/14 §4.1).
useChromeSurface(() => status.value !== 'finished')

/**
 * Set when the learner leaves the screen: whatever comes back after that (the login, the session, a new session of
 * "Repeat mistakes") must not carry them anywhere from the screen they are on now.
 */
let left = false
let beginSequence = 0

async function begin(): Promise<void> {
  const sequence = ++beginSequence
  const sessionId = props.id
  try {
    await auth.ensure()
    if (left || sequence !== beginSequence) return
    await practice.load(sessionId)
  } catch (err) {
    // load() handles its own errors; this is a failed login
    if (!left && sequence === beginSequence) practice.handleError(err)
  }
}

onMounted(() => {
  void begin()
  document.addEventListener('visibilitychange', onBack)
  window.addEventListener('online', onBack)
})

/**
 * The app is back (visible again, network back): a timed game may have ended meanwhile, so the session is read and shown as the server has it.
 * The clock does not guess what happened while the page slept.
 */
function onBack(): void {
  if (document.visibilityState === 'visible') void practice.resync()
}

// "Repeat mistakes" and "Again" replace the route with a new session id; the component is reused.
watch(
  () => props.id,
  () => void begin(),
)

onBeforeUnmount(() => {
  left = true
  document.removeEventListener('visibilitychange', onBack)
  window.removeEventListener('online', onBack)
  audio.revoke()
  // What a repeat / "Again" asked for may still come back: a refusal of it is not told to the next screen.
  practice.leaveOpen()
})

// ---- the header ----

const progressWidth = computed((): number => progressPercent(progress.value))

/** "Module 2 · Lesson 4 · Food"; the course title when the session is not about one lesson. */
const caption = computed((): string => {
  const open = session.value
  if (open === null) return ''
  return (open.lesson ? lessonHeader(open.lesson) : '') || open.course.title
})

// ---- the element ----

const busy = computed(() => status.value === 'submitting' || timeUp.value)
/** A timed game that waits for "Start": the clock has not begun (the server starts it with `start`). */
const isTimed = computed(() => timed.value !== null || session.value?.mode === 'pairs_timed')
/** The board is not read here: a server that hides it before the start sends `current: null`, an older one sends the first round. */
const waitingStart = computed(
  () => status.value === 'ready' && timed.value !== null && !timed.value.started && session.value?.status === 'active',
)
const element = computed(() => (status.value === 'ready' || status.value === 'submitting' ? current.value : null))
const lastElement = computed(() => session.value?.status === 'finished')

function onAnswer(payload: PracticeAnswerPayload): void {
  haptic('medium')
  void practice.answer(payload)
}

function onSkip(): void {
  haptic('medium')
  void practice.skip()
}

// The verdict is told by a symbol and words on screen, by a haptic tap to the hand and by a live region to a screen
// reader (a region that is always there announces what it is given; one that appears with its text may stay silent).
const liveText = computed((): string => {
  const done = outcome.value
  if (done === null || status.value !== 'verdict') return ''
  if (done.element.kind === 'pairs_round') {
    return t('practice.session.round_done', { round: done.element.round, rounds: done.element.rounds })
  }
  const words = verdictLabel(done.element.kind, done.verdict)
  return done.earlier ? `${words}. ${t('practice.reveal.earlier')}` : words
})

watch(outcome, (done) => {
  // A match of pairs is the board's own business (its tap, its colour); the other kinds and the end of a round are ours.
  if (done === null || (done.element.kind === 'pairs_round' && !done.roundDone)) return
  if (done.verdict === 'correct') notificationHaptic('success')
  else if (done.verdict === 'typo') notificationHaptic('warning')
  else notificationHaptic('error')
})

// A refusal that leaves the learner on the element is a toast: the buttons are at the bottom, the alert would be at the top.
watch(actionError, (message) => {
  if (message === '') return
  showErrorToast(message)
  practice.clearActionError()
})

watch(
  () => practice.openError,
  (message) => {
    if (message === '') return
    showErrorToast(message)
    practice.clearOpenError()
  },
)

// The toast tells about an action that failed. The learner's next try (an answer, "I don't know", a swap of the task, "Repeat mistakes",
// "Again") takes it off at once: when that goes through, the review must not stand under a refusal that was dealt with; when it fails
// again, the toast comes back with its own words (the watchers above). "Next" does the same: nothing of the old refusal belongs to the next task.
watch(busy, (isBusy) => {
  if (isBusy) hideErrorToast()
})
watch(opening, (now) => {
  if (now !== null) hideErrorToast()
})

function onContinue(): void {
  hideErrorToast()
  practice.continueAfterVerdict()
}

// ---- the clip of a dictation ----

/** The clip of the element on screen (the next one is fetched while the verdict is read). */
watch(
  () => (current.value?.kind === 'dictation' ? current.value.audio_url : null),
  (url) => {
    if (url === null) audio.revoke()
    else void audio.load(url)
  },
  { immediate: true },
)

const troubleDismissed = ref(false)
const troubleOpen = computed(
  () => practice.audio.state === 'failed' && element.value?.kind === 'dictation' && !troubleDismissed.value,
)

// A new try (or a new element) asks the question again.
watch(
  () => [practice.audio.state, current.value?.id] as const,
  ([state]) => {
    if (state !== 'failed') troubleDismissed.value = false
  },
)

const replacementsLeft = computed(() => (element.value?.kind === 'dictation' ? element.value.replacements_left : 0))

function retryAudio(): void {
  const clip = element.value
  troubleDismissed.value = false
  if (clip?.kind === 'dictation') void audio.load(clip.audio_url, { force: true })
}

async function replaceTask(): Promise<void> {
  troubleDismissed.value = false
  await practice.replace()
}

/**
 * "No sound for now" (the sheet of the sound trouble, and the button the dictation always has): the learner walks out of the
 * dictation to the hub to choose another mode. The dictation is dropped on purpose, so the hub starts the next mode over it
 * without asking "Continue or start a new one" about a session they have just left.
 */
function silent(): void {
  practice.abandonSession()
  leave()
}

// ---- the summary ----

async function goTo(id: number | null): Promise<void> {
  if (!left && id !== null) await router.replace({ name: 'practice-session', params: { id } })
}

function onStart(): void {
  haptic('medium')
  void practice.startTimed()
}

async function onRetry(): Promise<void> {
  await goTo(await practice.retry())
}

async function onAgain(): Promise<void> {
  await goTo(await practice.again())
}

function toCourse(): void {
  const slug = session.value?.course.slug
  void router.push(slug ? { name: 'course', params: { slug } } : { name: 'courses' })
}

const { reviewFromPaywall } = useReviewFromPaywall(() => practice.dismissPaywall(), { toastErrors: true })
</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    :class="status === 'finished' ? 'bg-page' : 'bg-surface'"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    data-testid="practice-session-view"
  >
    <!-- Always there, so that what is put into it is announced. -->
    <p class="sr-only" role="status" aria-live="polite" data-testid="verdict-live">{{ liveText }}</p>

    <ClosedState v-if="status === 'closed'" icon="lock-closed" :title="t('practice.session.closed_title')" :text="errorMessage">
      <button type="button" class="btn btn-primary btn-block" data-testid="hub-btn" @click="leave">{{ t('practice.session.to_hub') }}</button>
    </ClosedState>

    <template v-else>
      <header class="px-5 pt-3">
        <div class="flex items-center gap-3.5">
          <button
            type="button"
            class="-ml-3 flex size-11 shrink-0 items-center justify-center text-muted"
            :aria-label="t('common.close')"
            data-testid="close-btn"
            @click="leave"
          >
            <AppIcon name="x-mark" :size="18" />
          </button>
          <!-- A timed game has no use for the rounds counter: the clock and the score tell how it goes. -->
          <div
            v-if="!isTimed"
            class="progress progress-primary h-[3px] flex-1"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="progressWidth"
            :aria-label="t('practice.session.progress', { done: progress.done, total: progress.total })"
            data-testid="progress-bar"
          >
            <div class="progress-fill" :style="{ width: `${progressWidth}%` }" data-testid="progress-fill" />
          </div>
          <span
            v-if="!isTimed"
            class="min-w-12 text-right font-mono text-[13px] leading-[18px] text-muted tabular-nums"
            :class="{ invisible: progress.total === 0 }"
            data-testid="progress"
          >
            {{ progress.done }} / {{ progress.total }}
          </span>
        </div>
        <p v-if="caption" class="mt-1 flex items-center justify-center gap-2 text-center text-[13px] leading-[18px] text-muted" data-testid="caption">
          {{ caption }}
          <span v-if="session?.retry_of" class="chip chip-accent" data-testid="retry-chip">{{ t('practice.session.retry_chip') }}</span>
        </p>
      </header>

      <LoadingState v-if="status === 'idle' || status === 'starting'" variant="center" />

      <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="begin">
        <button type="button" class="btn btn-plain btn-block" data-testid="hub-btn" @click="leave">{{ t('practice.session.to_hub') }}</button>
      </ErrorState>

      <PracticeSummary
        v-else-if="status === 'finished' && session?.summary"
        :summary="session.summary"
        :mode="session.mode"
        :busy="opening !== null"
        :retry-unavailable="retryUnavailable"
        @retry="onRetry"
        @again="onAgain"
        @course="toCourse"
        @close="close()"
        @hub="leave"
      />

      <ClosedState v-else-if="status === 'finished'" icon="lock-closed" :title="t('practice.session.closed_title')">
        <button type="button" class="btn btn-primary btn-block" data-testid="hub-btn" @click="leave">{{ t('practice.session.to_hub') }}</button>
      </ClosedState>

      <AnswerReveal
        v-else-if="status === 'verdict' && outcome"
        :outcome="outcome"
        :last="lastElement"
        @continue="onContinue"
      />

      <!-- A timed game before its start: a short "Ready?" - the first tap of the learner must not eat the time. -->
      <div v-else-if="waitingStart && timed" class="flex flex-1 flex-col" data-testid="timed-ready">
        <div class="flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center">
          <h1 class="text-[32px] leading-[38px] font-semibold tracking-[-0.4px]" data-testid="timed-ready-title">{{ t('practice.timed.ready_title') }}</h1>
          <p class="text-base leading-[22px] text-muted" data-testid="timed-ready-text">{{ t('practice.timed.ready_text', { seconds: timed.seconds }) }}</p>
        </div>
        <StickyActions surface>
          <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="timedStarting" data-testid="timed-start-btn" @click="onStart">
            {{ timedStarting ? t('practice.timed.starting') : t('practice.timed.go') }}
          </button>
        </StickyActions>
      </div>

      <template v-else-if="element">
        <TimedBar v-if="timed" :deadline="timedDeadline" :seconds="timed.seconds" :score="timed.score" :penalty="penalty" @expired="practice.expire()" />
        <p v-if="timed && timeUp" class="px-5 pt-2 text-center text-base leading-[22px] font-semibold text-danger-ink" role="status" data-testid="timed-time-up">
          {{ t('practice.timed.time_up') }}
        </p>
        <!-- The component of the mode (modeComponents.ts); the key makes every element a new one, with no pick left over. -->
        <component
          :is="modeComponentFor(element)"
          :key="`${element.kind}-${elementSeq}`"
          :element="element"
          mode="solo"
          :disabled="busy"
          v-bind="timed ? { showRound: false } : {}"
          @answer="onAnswer"
          @skip="onSkip"
          @silent="silent"
        />
      </template>
    </template>

    <AudioTrouble
      v-if="troubleOpen"
      :replacements-left="replacementsLeft"
      :busy="busy"
      @retry="retryAudio"
      @replace="replaceTask"
      @silent="silent"
      @close="troubleDismissed = true"
    />

    <PaywallSheet
      v-if="paywall || limitHit"
      :reason="limitHit ? 'practice_limit' : 'practice'"
      :limit-hit="limitHit"
      @close="practice.dismissPaywall()"
      @review="reviewFromPaywall"
    />
  </section>
</template>
