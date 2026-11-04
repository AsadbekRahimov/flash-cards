<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { storeToRefs } from 'pinia'
import ClosedState from '@/components/ClosedState.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import BoosterRow from '@/components/game/BoosterRow.vue'
import EventBanner from '@/components/game/EventBanner.vue'
import GameReveal from '@/components/game/GameReveal.vue'
import RunHeader from '@/components/game/RunHeader.vue'
import RunSummary from '@/components/game/RunSummary.vue'
import { taskComponentFor } from '@/components/game/gameTasks'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { hideErrorToast, showErrorToast } from '@/composables/errorToast'
import { lessonHeader, verdictLabel } from '@/composables/practiceLabels'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { api } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useGameStore } from '@/stores/game'
import { useGameRunStore } from '@/stores/gameRun'
import { useI18n } from '@/i18n'
import type { GameAnswerPayload } from '@/api/gameRunTypes'
import type { GameBoosterEffect } from '@/api/gameShopTypes'

/**
 * A run of "Karvon" (docs/05 §5b.3, docs/18 §12.9): an immersive column without the tab bar - the header (✕, "3 / 10", the flasks, the
 * score, the combo from the third answer in a row), the task in the body and its actions at the bottom.
 *
 * `status` of the store decides what is drawn:
 * - `idle` / `loading` - a spinner; `error` - the message with "Retry" and the way to the map;
 * - `event` - the banner of a sandstorm / oasis / bazaar for ~1.5 s, then the task;
 * - `playing` / `submitting` - the task (`gameTasks.ts`), disabled while the answer is on its way; at the bazaar - "Hint for N";
 * - `reveal` - the verdict and the explanation, "Next";
 * - `summary` - `RunSummary` (completed / failed); `closed` (410, a run that was left or ran out of time) - "The run is over".
 *
 * ✕ and the system "Back" ask "Leave the run? It will not count" (`abandon`) while the run is on; on the summary and the closed screen they
 * lead to the map. "Again" and "To the map" lead to `/game?course=&stop=` and `/game?course=`: the course and the stop come with the run
 * (`course.slug`, `lesson_id`), the `course` / `stop` of the route are the fallback; the map opens the sheet of the stop - starting a run is
 * the map's business, not ours. The caravan of the day has no "Again" (one a day).
 */
const props = defineProps<{
  id: number
  /** The slug of the course, from the route: used until the run itself (`course.slug`) is read, and for a closed run. */
  course?: string | null
  /** The lesson of the stop (likewise: `lesson_id`). */
  stop?: number | null
  /** The stage of the boss that this run fights (the screen of the boss puts it in the address): the mark of the card of the city needs it. */
  boss?: number | null
}>()

/** How long the banner of an event stands before the task. */
const EVENT_BANNER_MS = 1500

const router = useRouter()
const auth = useAuthStore()
const game = useGameRunStore()
const audio = usePracticeAudio({ fetchBlob: (url, signal) => api.gameRuns.audioBlob(url, signal) })
const { haptic, notificationHaptic, shareUrl } = useTelegram()
const cities = useGameStore()
const { t } = useI18n()

const { status, run, errorMessage, actionError, outcome, elementSeq, element, coins, closedReason, boosterLimit } = storeToRefs(game)

// ---- the way out ----

/** The course and the stop of the run: the run itself tells (G2.7b), the query of the route is the fallback (a run not read yet, a 410). */
const courseSlug = computed((): string | null => run.value?.course?.slug ?? props.course ?? null)
const stopId = computed((): number | null => run.value?.lesson_id ?? props.stop ?? null)

/** `/game?course=` (and `&stop=` for "Again"): the map of the course; without a course - the map of the active one. */
function mapLocation(withStop = false): RouteLocationRaw {
  const query: Record<string, string> = {}
  if (courseSlug.value) {
    query.course = courseSlug.value
    if (withStop && stopId.value) query.stop = String(stopId.value)
  }
  return { path: '/game', query }
}

/**
 * Set when the learner leaves the screen: whatever comes back after that (the login, the run, the abandon) must not carry them anywhere
 * from the screen they are on now.
 */
let left = false
let beginSequence = 0

function goTo(location: RouteLocationRaw): void {
  if (!left) void router.replace(location)
}

const exitOpen = ref(false)

/** ✕ and "Back": a run that is on asks first; one that is over (or not read) just leaves. */
function requestLeave(): void {
  const live = status.value === 'event' || status.value === 'playing' || status.value === 'submitting' || status.value === 'reveal'
  if (live) exitOpen.value = true
  else goTo(mapLocation())
}

useBackButton(requestLeave)

async function confirmLeave(): Promise<void> {
  if (await game.abandon()) {
    exitOpen.value = false
    goTo(mapLocation())
  } else {
    exitOpen.value = false
  }
}

// ---- loading ----

async function begin(): Promise<void> {
  const sequence = ++beginSequence
  const runId = props.id
  try {
    await auth.ensure()
    if (left || sequence !== beginSequence) return
    await game.load(runId)
  } catch (err) {
    // load() handles its own errors; this is a failed login
    if (!left && sequence === beginSequence) game.handleError(err)
  }
}

onMounted(() => {
  void begin()
})

watch(
  () => props.id,
  () => void begin(),
)

let eventTimer: ReturnType<typeof setTimeout> | null = null

function clearEventTimer(): void {
  if (eventTimer !== null) clearTimeout(eventTimer)
  eventTimer = null
}

onBeforeUnmount(() => {
  left = true
  clearEventTimer()
  audio.revoke()
  hideErrorToast()
})

// ---- the header ----

/** "Samarkand · Module 2 · Lesson 4 · Food". */
const caption = computed((): string => {
  const current = run.value
  if (current === null) return ''
  const lesson = lessonHeader({ stage: current.stage_number, number: current.lesson_number, title: current.lesson_title })
  return [current.city, lesson].filter(Boolean).join(' · ')
})

// ---- the banner of an event ----

watch(
  status,
  (now) => {
    clearEventTimer()
    if (now === 'event') eventTimer = setTimeout(() => game.dismissEvent(), EVENT_BANNER_MS)
  },
  { immediate: true },
)

// ---- the task ----

const busy = computed(() => status.value === 'submitting')
const task = computed(() => (status.value === 'playing' || status.value === 'submitting' ? element.value : null))
const lastElement = computed(() => run.value?.status === 'finished')

function onAnswer(payload: GameAnswerPayload): void {
  haptic('medium')
  void game.answer(payload)
}

function onSkip(): void {
  haptic('medium')
  void game.skip()
}

/** The hint of the bazaar: the button is there while the server says it can be bought. */
const hintOffer = computed(() => {
  const current = run.value
  if (current === null || current.current === null || !current.hint.available) return null
  return status.value === 'playing' || status.value === 'submitting' ? { price: current.hint.price } : null
})

function onHint(): void {
  haptic('light')
  void game.hint()
}

/** A booster of the row (G2.5b, docs/05 §5b.5); the hint it gives lands on `current.element.hint`, drawn as the bazaar's. */
function onBooster(key: string, effect: GameBoosterEffect): void {
  haptic('light')
  void game.useBooster(key, effect)
}

/** The element on screen has a hint (the bazaar's or a booster's). */
const hinted = computed(() => {
  const current = task.value
  return current !== null && 'hint' in current && current.hint !== undefined
})

/** The free boosters of the week are used up: the paywall of the game, with the numbers of the refusal. */
const paywallOpen = ref(false)
watch(boosterLimit, (hit) => {
  if (hit !== null) paywallOpen.value = true
})

function closePaywall(): void {
  paywallOpen.value = false
  game.clearBoosterLimit()
}

function onContinue(): void {
  hideErrorToast()
  game.continueAfterReveal()
}

// The verdict is told by a symbol and words on screen, by a haptic tap and by a live region (one that is always there announces what
// it is given; one that appears with its text may stay silent).
const liveText = computed((): string => {
  const done = outcome.value
  if (done === null || status.value !== 'reveal') return ''
  const words = verdictLabel('collocation', done.verdict)
  return done.earlier ? `${words}. ${t('practice.reveal.earlier')}` : words
})

watch(outcome, (done) => {
  // A match of pairs is the board's own business; the other kinds and the end of a round are ours.
  if (done === null || (done.element.kind === 'pairs_round' && !done.roundDone && status.value === 'playing')) return
  if (done.verdict === 'correct') notificationHaptic('success')
  else if (done.verdict === 'typo') notificationHaptic('warning')
  else notificationHaptic('error')
})

// A refusal that leaves the learner on the task is a toast: the buttons are at the bottom, the alert would be at the top.
watch(actionError, (message) => {
  if (message === '') return
  showErrorToast(message)
  game.clearActionError()
})

// The next try takes the old refusal off at once; when it fails again the toast comes back with its own words.
watch(busy, (isBusy) => {
  if (isBusy) hideErrorToast()
})

// ---- the clip of `listen` and `dictation` ----

/** The clip of the element on screen (the next one is fetched while the verdict is read). */
watch(
  () => {
    const current = element.value
    return current !== null && (current.kind === 'listen' || current.kind === 'dictation') ? current.audio_url : null
  },
  (url) => {
    if (url === null) audio.revoke()
    else void audio.load(url)
  },
  { immediate: true },
)

// ---- the end ----

const closedText = computed((): string => {
  const reason = closedReason.value
  if (reason === 'abandoned') return t('gameRun.closed.abandoned')
  if (reason === 'expired') return t('gameRun.closed.expired')
  return t('gameRun.closed.default')
})

let shareMarked = false

/**
 * "Share" on the card of the city (docs/05 §5b.8): the dialog of Telegram with the link and the text of the server, then the mark that it
 * was opened (once; a refusal is silent). Without the stage of the boss in the address (a reload) the dialog opens and no mark is put.
 */
function onShare(): void {
  const share = run.value?.summary?.boss?.share
  if (!share) return
  haptic('light')
  shareUrl(share.link, share.text)
  if (!shareMarked && props.boss) {
    shareMarked = true
    void cities.markCityShared(props.boss)
  }
}

const { reviewFromPaywall } = useReviewFromPaywall(() => undefined, { toastErrors: true })
</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    :data-status="status"
    data-testid="game-run-view"
  >
    <!-- Always there, so that what is put into it is announced. -->
    <p class="sr-only" role="status" aria-live="polite" data-testid="verdict-live">{{ liveText }}</p>

    <ClosedState v-if="status === 'closed'" icon="lock-closed" :title="t('gameRun.closed.title')" :text="closedText">
      <button type="button" class="btn btn-primary btn-block" data-testid="map-btn" @click="goTo(mapLocation())">{{ t('gameRun.to_map') }}</button>
    </ClosedState>

    <LoadingState v-else-if="status === 'idle' || status === 'loading'" variant="center" />

    <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="begin">
      <button type="button" class="btn btn-plain btn-block" data-testid="map-btn" @click="goTo(mapLocation())">{{ t('gameRun.to_map') }}</button>
    </ErrorState>

    <RunSummary
      v-else-if="status === 'summary' && run?.summary"
      :summary="run.summary"
      :failed="run.finish_reason === 'failed'"
      :can-again="run.kind === 'stop'"
      @review="reviewFromPaywall"
      @share="onShare"
      @again="goTo(mapLocation(true))"
      @map="goTo(mapLocation())"
    />

    <ClosedState v-else-if="status === 'summary'" icon="lock-closed" :title="t('gameRun.closed.title')" :text="t('gameRun.closed.default')">
      <button type="button" class="btn btn-primary btn-block" data-testid="map-btn" @click="goTo(mapLocation())">{{ t('gameRun.to_map') }}</button>
    </ClosedState>

    <template v-else-if="run">
      <RunHeader
        :position="game.position"
        :total="run.total"
        :flasks="run.flasks"
        :flasks-max="run.flasks_max"
        :score="run.score"
        :combo="run.combo"
        :caption="caption"
        @close="requestLeave"
      />

      <EventBanner v-if="status === 'event' && run.current?.event" :event="run.current.event" />

      <GameReveal v-else-if="status === 'reveal' && outcome" :outcome="outcome" :last="lastElement" @continue="onContinue" />

      <template v-else-if="task">
        <!-- The boosters of the learner's stock: not drawn while there are none. -->
        <BoosterRow v-if="run.boosters.available.length > 0" :boosters="run.boosters" :element-id="task.id" :hinted="hinted" :disabled="busy" @use="onBooster" />

        <!-- The bazaar: a hint for coins; the balance is shown once the server has told it. -->
        <div v-if="hintOffer" class="flex items-center justify-center gap-3 px-4 pt-3" data-testid="hint-offer">
          <button type="button" class="btn btn-tinted" :disabled="busy" data-testid="hint-btn" @click="onHint">{{ t('gameRun.hint.buy', { price: hintOffer.price }) }}</button>
          <span v-if="coins !== null" class="text-[15px] leading-5 text-tg-subtitle tabular-nums" data-testid="hint-coins">{{ t('gameRun.hint.coins', { coins }) }}</span>
        </div>

        <!-- The component of the kind (gameTasks.ts); the key makes every element a new one, with no pick left over. -->
        <component
          :is="taskComponentFor(task)"
          :key="`${task.kind}-${elementSeq}`"
          :element="task"
          :disabled="busy"
          @answer="onAnswer"
          @skip="onSkip"
          @silent="requestLeave"
        />
      </template>
    </template>

    <PaywallSheet v-if="paywallOpen" reason="game" :booster-hit="boosterLimit" @close="closePaywall" @review="reviewFromPaywall" />

    <BottomSheet v-if="exitOpen" :label="t('gameRun.exit.title')" @close="exitOpen = false">
      <div class="flex flex-col gap-3 px-4 pt-2 pb-4" data-testid="exit-sheet">
        <h2 class="text-[22px] leading-7 font-bold">{{ t('gameRun.exit.title') }}</h2>
        <p class="text-[15px] leading-5 text-tg-hint">{{ t('gameRun.exit.text') }}</p>
        <button type="button" class="btn btn-primary btn-block" data-testid="stay-btn" @click="exitOpen = false">{{ t('gameRun.exit.stay') }}</button>
        <button type="button" class="btn btn-danger btn-block" :disabled="game.abandoning" data-testid="leave-btn" @click="confirmLeave">{{ t('gameRun.exit.confirm') }}</button>
      </div>
    </BottomSheet>
  </section>
</template>
