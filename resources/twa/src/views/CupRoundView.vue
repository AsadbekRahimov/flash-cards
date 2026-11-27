<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import ClosedState from '@/components/ClosedState.vue'
import StickyActions from '@/components/StickyActions.vue'
import GameReveal from '@/components/game/GameReveal.vue'
import RunHeader from '@/components/game/RunHeader.vue'
import { taskComponentFor } from '@/components/game/gameTasks'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { nextDayStart, nextRound, revealText, type NextRound } from '@/composables/cupInfo'
import { hideErrorToast, showErrorToast } from '@/composables/errorToast'
import { formatDate } from '@/composables/format'
import { gameVerdictLabel } from '@/composables/practiceLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useChromeSurface } from '@/composables/useChromeSurface'
import { useNow } from '@/composables/useNow'
import { useServerClock } from '@/composables/useServerClock'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupRoundStore } from '@/stores/cupRound'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { GameAnswerPayload } from '@/api/gameRunTypes'
import type { PracticeVerdict } from '@/api/types'

/**
 * The round of the day of a cup (docs/33 §5.2, §7.1, docs/05 §5d): an immersive column like the run of the game - the header (✕, "3 / 10",
 * the points) with "Playing for: <group> · Day 3" and the time left; the tasks are the components of the game (`gameTasks.ts`: four
 * options, the typed word, the gap; `listen` plays `audio_url` of the attempt); the verdict after each answer (`GameReveal`: the right
 * answer comes only with the verdict); the summary - the points of the day, the place, every task with its right answer.
 *
 * Before a new attempt - the confirmation "One attempt, 10 tasks, 10 minutes". An attempt that is on (another tab, a reload) goes on where
 * it was; one that is over opens on its summary. The time is the server's: the timer counts to `expires_at` by `server_now`, and when it
 * runs out the attempt is read again - the server ends it (the screen never does). 404 (a stranger's or a missing attempt) leads to the
 * cup. ✕ and "Back" ask first while the round is on: the time does not stop.
 */
const props = defineProps<{ id: number }>()

/** The server takes an answer until `expires_at` + 1.5 s: the attempt is read again a moment after that. */
const EXPIRY_GRACE_MS = 2000

const router = useRouter()
const auth = useAuthStore()
const store = useCupRoundStore()
const boards = useCupBoardStore()
const today = useTodayStore()
const clock = useServerClock()
const now = useNow(500)
const { haptic, notificationHaptic } = useTelegram()
const { t, tn, locale } = useI18n()

const { status, round, board, outcome, actionError, elementSeq, notice, failureKind } = storeToRefs(store)

let left = false

function toCup(): void {
  if (!left) void router.replace({ name: 'cup', params: { id: props.id } })
}

const live = computed((): boolean => ['playing', 'submitting', 'reveal'].includes(status.value))
const exitOpen = ref(false)

function requestLeave(): void {
  if (live.value) exitOpen.value = true
  else toCup()
}

useBackButton(requestLeave)

async function begin(): Promise<void> {
  try {
    await auth.ensure()
  } catch {
    store.reset(props.id)
    store.notice = t('error.generic')
    store.status = 'error'
    return
  }
  if (!left) await store.enter(props.id)
}

onMounted(() => {
  void begin()
})

watch(() => props.id, () => void begin())

onBeforeUnmount(() => {
  left = true
  hideErrorToast()
  store.reset(null)
})

// 404: a stranger's or a missing attempt - the cup.
watch(status, (next) => {
  if (next === 'not_found') toCup()
})

// ---- the header and the timer ----

const cup = computed(() => board.value?.cup ?? null)
const caption = computed((): string => {
  const c = cup.value
  const day = round.value?.day ?? c?.day ?? null
  if (c === null) return ''
  return day !== null ? t('cup.round.playing_for_day', { group: c.group_title, day }) : t('cup.round.playing_for', { group: c.group_title })
})

watch(
  () => round.value?.server_now,
  (serverNow) => clock.sync(serverNow),
  { immediate: true },
)

/** Milliseconds left by the server's clock (the device clock moved by the offset of `server_now`). */
const leftMs = computed((): number => {
  void now.value
  return round.value ? clock.remaining(round.value.expires_at) : 0
})

const timerText = computed((): string => {
  const seconds = Math.max(0, Math.ceil(leftMs.value / 1000))
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
})
const timerLow = computed((): boolean => leftMs.value <= 60_000)

watch(
  () => status.value === 'playing' && round.value !== null && leftMs.value <= -EXPIRY_GRACE_MS,
  (over) => {
    // Never after the screen is left: the store is reset then, and a late tick must not ask about an attempt nobody looks at.
    if (over && !left) void store.checkExpiry()
  },
)

// ---- the task ----

const white = computed((): boolean => live.value)
useChromeSurface(white)

const busy = computed((): boolean => status.value === 'submitting')
const task = computed(() => (status.value === 'playing' || status.value === 'submitting' ? store.element : null))
const lastTask = computed((): boolean => round.value?.status === 'finished')

function onAnswer(payload: GameAnswerPayload): void {
  haptic('medium')
  void store.answer(payload)
}

function onSkip(): void {
  haptic('medium')
  void store.skip()
}

function onContinue(): void {
  hideErrorToast()
  store.continueAfterReveal()
}

const liveText = computed((): string => {
  const done = outcome.value
  if (done === null || status.value !== 'reveal') return ''
  const words = gameVerdictLabel(done.verdict)
  return done.earlier ? `${words}. ${t('practice.reveal.earlier')}` : words
})

watch(outcome, (done) => {
  if (done === null) return
  if (done.verdict === 'correct') notificationHaptic('success')
  else if (done.verdict === 'typo') notificationHaptic('warning')
  else notificationHaptic('error')
})

watch(actionError, (message) => {
  if (message === '') return
  showErrorToast(message)
  store.clearActionError()
})

watch(busy, (isBusy) => {
  if (isBusy) hideErrorToast()
})

// ---- the confirmation ----

function start(): void {
  haptic('light')
  void store.start()
}

// ---- the summary ----

const myRank = computed((): number | null => {
  const b = boards.board
  if (b === null || b.cup.id !== props.id || boards.loadedAt === null) return null
  return b.standings.find((row) => row.me)?.rank ?? null
})

// The table is read again once the attempt is over: the place after this round.
watch(status, (next) => {
  if (next === 'summary' && !left) void boards.load(props.id)
})

const expired = computed((): boolean => round.value?.finish_reason === 'expired')

/** The verdict of a task in the review: right - success with a check, wrong - danger with a cross (never the colour alone, kit Muhrla). */
const INKS: Record<PracticeVerdict, string> = {
  correct: 'text-success-ink',
  typo: 'text-accent-ink',
  wrong: 'text-danger-ink',
  skipped: 'text-muted',
}
const inkOf = (verdict: string | null): string => (verdict !== null && verdict in INKS ? INKS[verdict as PracticeVerdict] : 'text-muted')
const markOf = (verdict: string | null): 'check' | 'x-mark' | null => (verdict === 'correct' ? 'check' : verdict === 'wrong' ? 'x-mark' : null)
const verdictOf = (verdict: string | null): string =>
  verdict === 'correct' || verdict === 'typo' || verdict === 'wrong' || verdict === 'skipped' ? gameVerdictLabel(verdict) : t('cup.round.no_answer')

/**
 * When the round of the next day opens (`next_day_at` of the attempt - the end of its day; shown in the learner's zone): "tomorrow at
 * HH:MM", "the last day", or - an attempt of an earlier day - "the round of the new day is available". An older server without the field:
 * the client's count of the cup's next midnight, only for an attempt of the cup's current day.
 */
const next = computed((): NextRound | null => {
  const r = round.value
  if (r === null) return null
  const c = cup.value
  return nextRound(r.next_day_at, r.server_now, () => (c !== null && c.day === r.day ? nextDayStart(c) : undefined))
})

const nextText = computed((): string => {
  const n = next.value
  if (n === null) return ''
  if (n.kind === 'last') return t('cup.round.next_last')
  if (n.kind === 'open') return t('cup.round.next_open')
  return t('cup.round.next_at', { time: formatDate(n.at, 'time', locale.value, today.data?.timezone || undefined) })
})

/** The title of a refusal: 403 `cup_not_member` (a stranger, one who left the chat) - "The cup is not available", as on the cup screen. */
const failureTitle = computed((): string | undefined => {
  switch (failureKind.value) {
    case 'unavailable':
      return t('cup.invite.unavailable_title')
    case 'off':
      return t('cup.off_title')
    case 'telegram':
    case 'busy':
      return t('cup.invite.check_title')
    case 'bot_not_admin':
      return t('cup.invite.bot_title')
    case 'paused':
      return t('cup.invite.paused_title')
    default:
      return undefined
  }
})

const expiresAtText = computed((): string => (round.value ? formatDate(round.value.expires_at, 'time', locale.value, today.data?.timezone || undefined) : ''))
</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    :class="white ? 'bg-surface' : ''"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    :data-status="status"
    data-testid="cup-round-view"
  >
    <p class="sr-only" role="status" aria-live="polite" data-testid="verdict-live">{{ liveText }}</p>

    <LoadingState v-if="status === 'idle' || status === 'loading' || status === 'not_found'" variant="center" />

    <ErrorState v-else-if="status === 'error'" :title="failureTitle" :message="notice" @retry="begin">
      <button type="button" class="btn btn-ghost btn-block" data-testid="to-cup" @click="toCup">{{ t('cup.round.to_cup') }}</button>
    </ErrorState>

    <ClosedState v-else-if="status === 'closed'" icon="lock-closed" :title="t('cup.status.finished')" :text="notice" data-testid="closed">
      <button type="button" class="btn btn-primary btn-block" data-testid="to-cup" @click="toCup">{{ t('cup.round.to_cup') }}</button>
    </ClosedState>

    <div v-else-if="status === 'unavailable'" class="page flex flex-1 flex-col">
      <ClosedState
        :mascot="failureKind === 'unavailable' || failureKind === 'off' ? 'thinking' : 'hello'"
        :title="failureTitle ?? t('cup.round.unavailable_title')"
        :text="notice"
        :data-kind="failureKind ?? undefined"
        data-testid="unavailable"
      >
        <button type="button" class="btn btn-secondary btn-block" data-testid="to-cup" @click="toCup">{{ t('cup.round.to_cup') }}</button>
      </ClosedState>
    </div>

    <!-- Before a new attempt: what it is, then "Start". -->
    <div v-else-if="status === 'confirm' || status === 'starting'" class="page flex flex-1 flex-col" data-testid="confirm">
      <div class="flex flex-1 flex-col gap-3">
        <p class="t-label-s pt-3.5 text-muted" data-testid="caption">{{ caption }}</p>
        <!-- Before the attempt: the hero of the kit on the central axis - the mascot thinks, the title, what the round is. -->
        <section class="flex flex-col items-center gap-2 rounded-hero bg-highlight p-5 text-center">
          <MascotArt pose="thinking" :height="112" class="mb-1 short:hidden" />
          <h1 class="t-display-m">{{ t('cup.round.confirm_title') }}</h1>
          <p class="t-body-m" data-testid="confirm-text">
            {{ t('cup.round.confirm_text', { tasks: cup?.round_tasks ?? 0, minutes: cup?.round_minutes ?? 0 }) }}
          </p>
          <p class="t-body-m text-muted">{{ t('cup.round.confirm_points') }}</p>
        </section>
      </div>
      <StickyActions>
        <button
          type="button"
          class="btn btn-primary btn-lg btn-block"
          :disabled="status === 'starting'"
          :aria-busy="status === 'starting' ? 'true' : undefined"
          data-testid="start-btn"
          @click="start"
        >
          {{ t('cup.round.start') }}
        </button>
        <button type="button" class="btn btn-ghost btn-block" data-testid="later-btn" @click="toCup">{{ t('cup.round.later') }}</button>
      </StickyActions>
    </div>

    <!-- The summary: the points of the day, the place, every task with its right answer. -->
    <div v-else-if="status === 'summary' && round" class="page flex flex-1 flex-col" data-testid="summary">
      <div class="flex flex-1 flex-col gap-3">
        <p class="t-label-s pt-3.5 text-muted">{{ caption }}</p>
        <!--
          The end of the round of the day as the end of a lesson (kit 06-urok-proyden), on the central axis: a round played to the end has
          the mascot glad of it; a round whose time ran out is told quietly. The points are the big figure, the rest lines under it.
        -->
        <section class="flex flex-col items-center gap-2 rounded-hero bg-highlight p-5 text-center" data-testid="summary-card">
          <MascotArt v-if="!expired && round.points > 0" pose="celebrate" :height="144" class="mb-1 short:hidden" />
          <h1 class="t-display-m" data-testid="summary-title">{{ expired ? t('cup.round.expired_title') : t('cup.round.done_title') }}</h1>
          <p class="font-display text-[44px] leading-[50px] font-semibold tracking-[-0.03em] tabular-nums" data-testid="summary-points">
            {{ round.points }}<span class="t-title-m text-muted"> / {{ round.max_points }}</span>
          </p>
          <p class="t-body-m" data-testid="summary-correct">{{ t('cup.round.correct', { correct: round.correct, total: round.total }) }}</p>
          <p v-if="myRank !== null" class="t-body-m-strong" data-testid="summary-place">{{ t('cup.my_place', { rank: myRank }) }}</p>
          <p v-if="expired" class="t-label-s text-muted" data-testid="summary-expired">{{ t('cup.round.expired_text') }}</p>
          <p v-if="nextText" class="t-body-m text-muted" :data-next="next?.kind" data-testid="summary-next">{{ nextText }}</p>
        </section>

        <section v-if="round.review && round.review.length > 0" class="list" :aria-label="t('cup.round.review_title')" data-testid="review">
          <h2 class="t-title-m px-4 pt-4 pb-2">{{ t('cup.round.review_title') }}</h2>
          <ol class="divide-y divide-divider border-t border-divider">
            <li v-for="(entry, index) in round.review" :key="entry.element_id" class="flex items-center gap-3 px-4 py-3" data-testid="review-row">
              <span class="w-5 shrink-0 font-display text-[13px] leading-[18px] text-muted tabular-nums" aria-hidden="true">{{ index + 1 }}</span>
              <span class="t-body-m-strong min-w-0 flex-1 break-words" lang="en" data-testid="review-answer">{{ revealText(entry.reveal) }}</span>
              <span class="t-label-s flex shrink-0 items-center gap-1" :class="inkOf(entry.verdict)" :data-verdict="entry.verdict ?? 'none'" data-testid="review-verdict">
                <AppIcon v-if="markOf(entry.verdict)" :name="markOf(entry.verdict) ?? 'check'" :size="16" class="shrink-0" />
                <span>{{ verdictOf(entry.verdict) }}<template v-if="entry.points > 0"> · {{ tn('cup.points', entry.points) }}</template></span>
              </span>
            </li>
          </ol>
        </section>
      </div>
      <StickyActions>
        <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="to-cup" @click="toCup">{{ t('cup.round.to_table') }}</button>
      </StickyActions>
    </div>

    <template v-else-if="round">
      <RunHeader
        :position="store.position"
        :total="round.total"
        :flasks="0"
        :flasks-max="0"
        :score="round.points"
        :combo="0"
        :caption="caption"
        @close="requestLeave"
      />
      <!-- The time left of the attempt: tabular figures of the kit (no jumping width); the last minute in the accent of text, not the red of a mistake. -->
      <p
        class="px-5 text-center font-display text-[15px] leading-5 font-semibold tabular-nums"
        :class="timerLow ? 'text-accent' : 'text-muted'"
        role="timer"
        :aria-label="t('cup.round.time_left', { time: timerText })"
        data-testid="timer"
      >
        {{ timerText }}
      </p>

      <GameReveal
        v-if="status === 'reveal' && outcome"
        class="[&_[data-testid=actions]]:border-t [&_[data-testid=actions]]:border-hairline"
        :outcome="outcome"
        :last="lastTask"
        @continue="onContinue"
      />

      <component
        :is="taskComponentFor(task)"
        v-else-if="task"
        :key="`${task.kind}-${elementSeq}`"
        class="[&_[data-testid=actions]]:bg-surface"
        :element="task"
        :disabled="busy"
        @answer="onAnswer"
        @skip="onSkip"
      />
    </template>

    <BottomSheet v-if="exitOpen" :label="t('cup.round.exit_title')" @close="exitOpen = false">
      <div class="flex flex-col gap-3 px-4 pt-2 pb-4" data-testid="exit-sheet">
        <h2 class="t-display-m">{{ t('cup.round.exit_title') }}</h2>
        <p class="t-body-m text-muted">{{ t('cup.round.exit_text', { time: expiresAtText }) }}</p>
        <button type="button" class="btn btn-primary btn-block" data-testid="stay-btn" @click="exitOpen = false">{{ t('cup.round.exit_stay') }}</button>
        <button type="button" class="btn btn-ghost btn-block" data-testid="leave-btn" @click="toCup">{{ t('cup.round.exit_leave') }}</button>
      </div>
    </BottomSheet>
  </section>
</template>
