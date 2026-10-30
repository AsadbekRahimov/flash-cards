<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { storeToRefs } from 'pinia'
import ClosedState from '@/components/ClosedState.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AnswerReveal from '@/components/practice/AnswerReveal.vue'
import AudioTrouble from '@/components/practice/AudioTrouble.vue'
import PracticeSummary from '@/components/practice/PracticeSummary.vue'
import { modeComponentFor } from '@/components/practice/modeComponents'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { progressPercent } from '@/composables/courseLabels'
import { showErrorToast } from '@/composables/errorToast'
import { lessonHeader, verdictLabel } from '@/composables/practiceLabels'
import { useBackButton } from '@/composables/useBackButton'
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
 * `practice_pro_required`.
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

const { status, session, outcome, errorMessage, actionError, paywall, retryUnavailable, opening, elementSeq, progress, current } = storeToRefs(practice)

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
})

// "Repeat mistakes" and "Again" replace the route with a new session id; the component is reused.
watch(
  () => props.id,
  () => void begin(),
)

onBeforeUnmount(() => {
  left = true
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

const busy = computed(() => status.value === 'submitting')
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
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    data-testid="practice-session-view"
  >
    <!-- Always there, so that what is put into it is announced. -->
    <p class="sr-only" role="status" aria-live="polite" data-testid="verdict-live">{{ liveText }}</p>

    <ClosedState v-if="status === 'closed'" icon="lock-closed" :title="t('practice.session.closed_title')" :text="errorMessage">
      <button type="button" class="btn btn-primary btn-block" data-testid="hub-btn" @click="leave">{{ t('practice.session.to_hub') }}</button>
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
          <div
            class="progress flex-1"
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
            class="min-w-14 text-right text-[15px] leading-5 font-medium text-tg-subtitle tabular-nums"
            :class="{ invisible: progress.total === 0 }"
            data-testid="progress"
          >
            {{ progress.done }} / {{ progress.total }}
          </span>
        </div>
        <p v-if="caption" class="mt-1 flex items-center justify-center gap-2 text-center text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="caption">
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
      />

      <ClosedState v-else-if="status === 'finished'" icon="lock-closed" :title="t('practice.session.closed_title')">
        <button type="button" class="btn btn-primary btn-block" data-testid="hub-btn" @click="leave">{{ t('practice.session.to_hub') }}</button>
      </ClosedState>

      <AnswerReveal
        v-else-if="status === 'verdict' && outcome"
        :outcome="outcome"
        :last="lastElement"
        @continue="practice.continueAfterVerdict()"
      />

      <!-- The component of the mode (modeComponents.ts); the key makes every element a new one, with no pick left over. -->
      <component
        :is="modeComponentFor(element)"
        v-else-if="element"
        :key="`${element.kind}-${elementSeq}`"
        :element="element"
        mode="solo"
        :disabled="busy"
        @answer="onAnswer"
        @skip="onSkip"
        @silent="silent"
      />
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

    <PaywallSheet v-if="paywall" reason="practice" @close="practice.dismissPaywall()" @review="reviewFromPaywall" />
  </section>
</template>
