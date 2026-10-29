<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { api } from '@/api'
import ClosedState from '@/components/ClosedState.vue'
import BattleResultPanel from '@/components/battle/BattleResult.vue'
import BattleReveal from '@/components/battle/BattleReveal.vue'
import BattleRoom from '@/components/battle/BattleRoom.vue'
import BattleScoreboard from '@/components/battle/BattleScoreboard.vue'
import LeaveConfirm from '@/components/battle/LeaveConfirm.vue'
import OpponentStatus from '@/components/battle/OpponentStatus.vue'
import RoundTimer from '@/components/battle/RoundTimer.vue'
import { VERDICT_LOOK } from '@/components/battle/verdict'
import { modeComponentFor } from '@/components/practice/modeComponents'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { clockText, remainingMs, secondsLeft } from '@/composables/battleTime'
import { showErrorToast } from '@/composables/errorToast'
import { lessonHeader } from '@/composables/practiceLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useBattleAudio } from '@/composables/useBattleAudio'
import { useNow } from '@/composables/useNow'
import { usePolling } from '@/composables/usePolling'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import { useTelegram } from '@/composables/useTelegram'
import { useWakeAt } from '@/composables/useWakeAt'
import { useAuthStore } from '@/stores/auth'
import { useBattleStore } from '@/stores/battle'
import { useI18n } from '@/i18n'
import type { BattleResult, PracticeAnswerPayload } from '@/api/types'

/**
 * A battle (docs/16 §11.2): the screen follows `status` of the battle, which the server sets.
 *
 * - `invited` / `room` — `BattleRoom`: the invitation sent ("Waiting for…") or received ("Accept"), the room ("Ready");
 * - `countdown` — "3 · 2 · 1" to `starts_at` by the clock of the server;
 * - `playing` — the header (the round, the score, the timer to the deadline of the server); the component of the mode
 *   with `mode="battle"` (one final answer); after the answer "Waiting for the opponent…" and what the opponent is
 *   doing; at the end of the round the reveal (`BattleReveal`) with the pause before the next one;
 * - `finished` / `closed` — `BattleResult` (the outcome, the reason, the rounds, the rematch).
 * The strips under the header tell about trouble that does not end the match: "No connection, trying…" (the poll
 * fails) and "The opponent lost connection… 0:21" (the poll says `opponent_present = false`).
 *
 * The poll (`usePolling`, every two seconds) runs only while the battle is alive, stops in the background and when the
 * screen is left, and reads at once on return; the screen is also woken at the moments the server moves the battle on
 * (the start, the deadline of a round, the end of the pause), so that a round does not begin two seconds late.
 *
 * ✕ and the system "Back" (docs/16 §11.3): the invitation of the opponent — just leaves; the invitation sent, the room
 * and the countdown — "Leave the room?" and `cancel`; the match — "Surrender and leave?" and `surrender`; the end —
 * to the friends (to the course if the learner came from the practice hub).
 */
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()
const store = useBattleStore()
const { haptic, notificationHaptic } = useTelegram()
const { t } = useI18n()
const now = useNow()

const { battle: data, status, loadStatus, errorMessage, gone, acting, actionError, offline, answerState, busyBattleId, rematchBattle, serverOffsetMs } = storeToRefs(store)

// ---------------------------------------------------------------- entering

/** Set when the learner leaves the screen: whatever comes back after that must not carry them anywhere. */
let left = false
let beginSequence = 0

async function begin(): Promise<void> {
  const sequence = ++beginSequence
  const id = props.id
  try {
    await auth.ensure()
  } catch (err) {
    if (!left && sequence === beginSequence) {
      store.resetFor(id)
      store.failLoad(err)
    }
    return
  }
  if (left || sequence !== beginSequence) return
  await store.open(id)
}

onMounted(() => {
  void begin()
})

// A rematch replaces the route with a new battle; the component is reused.
watch(
  () => props.id,
  () => void begin(),
)

onBeforeUnmount(() => {
  left = true
  store.leave()
  preload.release()
  roundAudio.revoke()
})

usePolling(() => store.tick(), { enabled: computed(() => store.polling) })
// The end of a battle: the other side may offer a rematch in the next seconds, so the result is read again, slowly.
usePolling(() => store.refreshResult(), { enabled: computed(() => store.watchingResult), intervalMs: 5000 })

// ------------------------------------------------------------------ clocks

/** Server time of the next moment at which the server moves the battle on; the screen asks for the poll right then. */
const GRACE_GUESS_MS = 1600
const dueAt = computed((): number | null => {
  const battle = data.value
  if (battle === null) return null
  const state = store.roundState
  let at: string | null = null
  if (status.value === 'invited') at = battle.invite_expires_at
  else if (status.value === 'room') at = battle.room_expires_at
  else if (status.value === 'countdown') at = battle.starts_at
  else if (status.value === 'playing' && state?.phase === 'reveal') at = state.phase_ends_at
  else if (status.value === 'playing' && state?.phase === 'answering' && state.deadline_at !== null) {
    const deadline = Date.parse(state.deadline_at)
    return Number.isFinite(deadline) ? deadline + GRACE_GUESS_MS : null
  }
  const parsed = Date.parse(at ?? '')
  return Number.isFinite(parsed) ? parsed : null
})

useWakeAt({
  dueAt: () => dueAt.value,
  offsetMs: () => serverOffsetMs.value,
  wake: () => {
    store.tick().catch(() => undefined)
  },
})

// -------------------------------------------------------------------- state

const state = computed(() => store.roundState)
const round = computed(() => store.currentRound)
const phase = computed(() => state.value?.phase ?? null)
const iAnswered = computed(() => state.value?.my_answered === true)
const opponentName = computed(() => data.value?.opponent.first_name?.trim() || t('battle.opponent'))
const roundsTotal = computed(() => data.value?.rounds_total ?? 5)
const lastRound = computed(() => state.value !== null && state.value.no >= roundsTotal.value)

const caption = computed((): string => {
  const battle = data.value
  if (battle === null) return ''
  return battle.lesson ? lessonHeader(battle.lesson) : battle.course.title
})

const headline = computed((): string =>
  status.value === 'playing' && state.value !== null ? t('battle.round.title', { round: state.value.no, rounds: roundsTotal.value }) : t('battle.title'),
)

const loading = computed(() => loadStatus.value === 'idle' || loadStatus.value === 'loading' || (loadStatus.value === 'ready' && data.value === null))
const failed = computed(() => loadStatus.value === 'error')
const live = computed(() => status.value === 'invited' || status.value === 'room' || status.value === 'countdown' || status.value === 'playing')
const over = computed(() => status.value === 'finished' || status.value === 'closed')

/** What the result screen shows; a battle without a result of its own is one that did not take place. */
const result = computed(
  (): BattleResult => data.value?.result ?? { outcome: 'none', end_reason: store.poll?.end_reason ?? 'canceled', rounds: [] },
)

// ------------------------------------------------------------------ strips

const absentSeconds = computed((): number => {
  if (store.absentSince === null) return 0
  return Math.max(0, Math.floor((now.value + serverOffsetMs.value - store.absentSince) / 1000))
})
const showAbsent = computed(() => !store.opponentPresent && (status.value === 'room' || status.value === 'countdown' || status.value === 'playing'))
const absentText = computed(() => t('battle.connection.absent', { time: clockText(absentSeconds.value) }))
const absentHint = computed(() => t(status.value === 'playing' ? 'battle.connection.absent_playing' : 'battle.connection.absent_room'))

// --------------------------------------------------------------- countdown

const countdownLeft = computed(() => secondsLeft(remainingMs(data.value?.starts_at, now.value, serverOffsetMs.value)))
const countdownNumbers = computed((): number[] => {
  const total = data.value?.countdown_seconds ?? 3
  return Array.from({ length: total }, (_, index) => total - index)
})

// -------------------------------------------------------------------- audio

/** A dictation loads its five recordings in the room; "Ready" waits for them (docs/16 §10.3). */
const preload = useBattleAudio()
watch(
  () => (status.value === 'room' ? (data.value?.audio_preload ?? null) : null),
  (urls) => {
    if (urls !== null && urls.length > 0) preload.preload(urls)
  },
  { immediate: true },
)

const roomAudio = computed(() =>
  data.value?.mode === 'dictation' && preload.total.value > 0
    ? { state: preload.state.value, loaded: preload.loaded.value, total: preload.total.value }
    : null,
)

/** The clip of the round on screen: from what the room loaded, else from the server. */
const roundAudio = usePracticeAudio({ fetchBlob: async (url) => preload.blobFor(url) ?? (await api.battles.audioBlob(url)) })

const dictation = computed(() => {
  const element = round.value?.element
  return phase.value === 'answering' && !iAnswered.value && element?.kind === 'dictation' ? element : null
})

watch(
  () => dictation.value?.audio_url ?? null,
  (url) => {
    if (url === null) roundAudio.revoke()
    else void roundAudio.load(url)
  },
  { immediate: true },
)

/** The recording did not come: the server is told once per round (it checks the file), the learner can ask again. */
const clipFailed = computed(() => dictation.value !== null && roundAudio.state.value === 'failed')
watch(clipFailed, (failedNow) => {
  const element = dictation.value
  if (failedNow && element !== null) void store.audioFailed(element.id)
})

function retryClip(): void {
  const element = dictation.value
  if (element !== null) void roundAudio.load(element.audio_url, { force: true })
}

// ------------------------------------------------------------------ actions

function onAnswer(payload: PracticeAnswerPayload): void {
  haptic('medium')
  void store.answer(payload)
}

function onSkip(): void {
  haptic('medium')
  void store.answer({ skip: true })
}

async function onReady(): Promise<void> {
  haptic('medium')
  await store.ready()
}

async function onAccept(): Promise<void> {
  haptic('medium')
  await store.accept()
}

async function onDecline(): Promise<void> {
  if (await store.decline()) exit()
}

async function onCancel(): Promise<void> {
  if (await store.cancel()) exit()
}

function openBusy(): void {
  const id = busyBattleId.value
  store.clearBusyBattle()
  if (id !== null) void router.push({ name: 'battle', params: { id } })
}

// A refusal that leaves the learner on the screen is a toast: the buttons are at the bottom, an alert would be at the top.
watch(actionError, (message) => {
  if (message === '') return
  showErrorToast(message)
  store.clearActionError()
})

// ------------------------------------------------------------- the rematch

function goTo(id: number | null): void {
  if (!left && id !== null) void router.replace({ name: 'battle', params: { id } })
}

async function onRematch(): Promise<void> {
  haptic('medium')
  goTo(await store.rematch())
}

async function onAcceptRematch(): Promise<void> {
  haptic('medium')
  goTo(await store.acceptRematch())
}

function onOpenRematch(): void {
  goTo(rematchBattle.value?.id ?? null)
}

// ------------------------------------------------------------------ leaving

const leaving = ref<'room' | 'match' | null>(null)

function toFriends(): void {
  if (!left) void router.replace({ name: 'friends' })
}

function toCourse(): void {
  const slug = data.value?.course.slug
  if (!left) void router.replace(slug ? { name: 'course', params: { slug } } : { name: 'courses' })
}

/** The way out of a battle that is over or was never joined: the course when the learner came from the practice hub, else the friends. */
function exit(): void {
  const back = router.options.history.state.back
  if (typeof back === 'string' && back.startsWith('/practice/')) toCourse()
  else toFriends()
}

/** ✕ and the system "Back". */
function requestLeave(): void {
  if (leaving.value !== null) {
    leaving.value = null
    return
  }
  const battle = data.value
  if (battle === null || loadStatus.value !== 'ready' || over.value) return exit()
  if (status.value === 'playing') leaving.value = 'match'
  // The invitation that came is not ours to withdraw: leaving it keeps it open for the learner to answer later.
  else if (status.value === 'invited' && battle.role === 'opponent') exit()
  else leaving.value = 'room'
}

useBackButton(requestLeave)

async function confirmLeave(): Promise<void> {
  const kind = leaving.value
  if (kind === 'match') {
    await store.surrender()
    leaving.value = null
    return
  }
  const done = await store.cancel()
  leaving.value = null
  if (done) exit()
}

// ------------------------------------------------------------- announcements

// The reveal is told by symbols and words on screen, by a tap to the hand and by a live region to a screen reader
// (a region that is always there announces what it is given; one that appears with its text may stay silent).
const liveText = computed((): string => {
  const battle = data.value
  if (battle === null) return ''
  if (status.value === 'countdown') return t('battle.live.countdown', { seconds: countdownLeft.value })
  if (status.value === 'playing' && state.value !== null) {
    if (phase.value === 'reveal' && round.value?.reveal) return t('battle.live.reveal', { verdict: t(VERDICT_LOOK[round.value.reveal.me.verdict].label) })
    if (iAnswered.value) return t('battle.live.waiting')
    return t('battle.live.round', { round: state.value.no, rounds: roundsTotal.value })
  }
  if (over.value) return t(`battle.result.title.${result.value.outcome}`)
  return ''
})

watch(
  () => round.value?.reveal ?? null,
  (reveal) => {
    if (reveal === null) return
    const verdict = reveal.me.verdict
    notificationHaptic(verdict === 'correct' ? 'success' : verdict === 'typo' ? 'warning' : 'error')
  },
)

watch(over, (isOver) => {
  if (isOver && result.value.outcome === 'won') notificationHaptic('success')
})
</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    :data-status="status"
    data-testid="battle-view"
  >
    <!-- Always there, so that what is put into it is announced. -->
    <p class="sr-only" role="status" aria-live="polite" data-testid="battle-live">{{ liveText }}</p>

    <LoadingState v-if="loading" variant="center" />

    <ClosedState v-else-if="failed && gone" icon="lock-closed" :title="t('battle.gone_title')" :text="errorMessage">
      <button type="button" class="btn btn-primary btn-block" data-testid="friends-btn" @click="toFriends">{{ t('battle.to_friends') }}</button>
    </ClosedState>

    <ErrorState v-else-if="failed" :message="errorMessage" @retry="begin">
      <button type="button" class="btn btn-plain btn-block" data-testid="friends-btn" @click="toFriends">{{ t('battle.to_friends') }}</button>
    </ErrorState>

    <template v-else-if="data">
      <header class="px-4 pt-3">
        <div class="flex items-center gap-2">
          <button type="button" class="-ml-1 flex size-11 shrink-0 items-center justify-center" :aria-label="t('common.close')" data-testid="close-btn" @click="requestLeave">
            <span class="flex size-9 items-center justify-center rounded-full bg-track text-tg-subtitle"><AppIcon name="x-mark" :size="20" /></span>
          </button>
          <p class="min-w-0 flex-1 truncate text-center text-[17px] leading-[22px] font-semibold" data-testid="headline">{{ headline }}</p>
          <span class="size-10 shrink-0" aria-hidden="true" />
        </div>
        <p v-if="caption" class="mt-1 truncate text-center text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="caption">{{ caption }}</p>
      </header>

      <!-- Trouble that does not end the match. -->
      <div v-if="offline || showAbsent" class="mt-3 flex flex-col gap-2 px-4" data-testid="strips">
        <InlineAlert v-if="offline" tone="warning" :message="t('battle.connection.offline')" data-testid="offline-strip" />
        <InlineAlert v-if="showAbsent" tone="warning" data-testid="absent-strip">
          <span class="block font-medium" data-testid="absent-text">{{ absentText }}</span>
          <span class="block text-[13px] leading-[18px]" data-testid="absent-hint">{{ absentHint }}</span>
        </InlineAlert>
      </div>

      <BattleRoom
        v-if="status === 'invited' || status === 'room'"
        :battle="data"
        :ready="store.readiness"
        :opponent-present="store.opponentPresent"
        :expires-at="status === 'invited' ? data.invite_expires_at : data.room_expires_at"
        :offset-ms="serverOffsetMs"
        :busy="acting"
        :audio="roomAudio"
        :busy-battle-id="busyBattleId"
        @accept="onAccept"
        @decline="onDecline"
        @cancel="onCancel"
        @ready="onReady"
        @leave="leaving = 'room'"
        @retry-audio="preload.retry()"
        @open-busy="openBusy"
      />

      <section v-else-if="status === 'countdown'" class="flex flex-1 flex-col items-center justify-center gap-5 px-6 py-10 text-center" data-testid="battle-countdown">
        <p class="text-[17px] leading-[22px] font-semibold text-tg-subtitle">{{ t('battle.countdown.title') }}</p>
        <p class="text-[96px] leading-none font-bold text-accent tabular-nums" aria-hidden="true" data-testid="countdown-number">
          {{ countdownLeft > 0 ? countdownLeft : t('battle.countdown.go') }}
        </p>
        <ol class="flex items-center gap-3 text-2xl leading-8 font-bold" aria-hidden="true" data-testid="countdown-steps">
          <li
            v-for="(number, index) in countdownNumbers"
            :key="number"
            class="flex items-center gap-3"
            :class="number === countdownLeft ? 'text-accent' : number > countdownLeft ? 'text-tg-hint opacity-50' : 'text-tg-subtitle'"
          >
            <span v-if="index > 0" class="text-tg-hint">·</span>
            <span class="tabular-nums">{{ number }}</span>
          </li>
        </ol>
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('battle.round.title', { round: 1, rounds: roundsTotal }) }}</p>
      </section>

      <template v-else-if="status === 'playing'">
        <div class="px-4 pt-3">
          <BattleScoreboard :me="store.scores.me" :opponent="store.scores.opponent" :opponent-name="opponentName">
            <RoundTimer v-if="phase === 'answering' && state" :deadline="state.deadline_at" :offset-ms="serverOffsetMs" :total-seconds="data.round_seconds" />
          </BattleScoreboard>
        </div>

        <BattleReveal
          v-if="phase === 'reveal' && round && round.reveal"
          :round="round"
          :opponent-name="opponentName"
          :last="lastRound"
          :offset-ms="serverOffsetMs"
          :total-seconds="data.reveal_seconds"
        />

        <template v-else-if="phase === 'answering'">
          <!-- The answer is in: the opponent's turn. -->
          <section v-if="iAnswered" class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-6" data-testid="battle-waiting">
            <div class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
              <span class="flex size-16 items-center justify-center rounded-full bg-success-soft text-success-ink"><AppIcon name="check" solid :size="32" /></span>
              <h2 class="text-[22px] leading-7 font-bold">{{ t('battle.round.answered') }}</h2>
              <p class="text-base leading-[22px] text-tg-subtitle">{{ t('battle.round.waiting') }}</p>
              <div class="w-full pt-2">
                <OpponentStatus :name="opponentName" :state="!store.opponentPresent ? 'absent' : state?.opponent_answered ? 'answered' : 'answering'" />
              </div>
            </div>
          </section>

          <!-- Not delivered: the answer waits for "Retry"; the clock goes on. -->
          <section v-else-if="answerState === 'failed'" class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-6" data-testid="battle-send-failed">
            <InlineAlert tone="warning" :message="t('battle.round.send_failed')" />
            <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="retry-answer-btn" @click="store.retryAnswer()">{{ t('common.retry') }}</button>
          </section>

          <template v-else-if="round">
            <p v-if="answerState === 'sending'" class="px-4 pt-3 text-center text-[15px] leading-5 text-tg-subtitle" data-testid="sending">{{ t('battle.round.sending') }}</p>
            <div v-if="clipFailed" class="flex flex-col gap-2 px-4 pt-3" data-testid="clip-failed">
              <InlineAlert tone="warning" :message="t('battle.audio.round_failed')" />
              <button type="button" class="btn btn-tinted btn-sm self-start" data-testid="clip-retry" @click="retryClip">{{ t('practice.audio.retry') }}</button>
            </div>
            <!-- The component of the mode (modeComponents.ts); the key makes every round a new one, with no pick left over. -->
            <component
              :is="modeComponentFor(round.element)"
              :key="`${data.id}-${store.elementSeq}`"
              :element="round.element"
              mode="battle"
              :disabled="answerState === 'sending'"
              @answer="onAnswer"
              @skip="onSkip"
            />
          </template>

          <LoadingState v-else variant="center" />
        </template>

        <LoadingState v-else variant="center" />
      </template>

      <BattleResultPanel
        v-else-if="over"
        :battle="data"
        :result="result"
        :rematch="rematchBattle"
        :busy="acting"
        @rematch="onRematch"
        @accept-rematch="onAcceptRematch"
        @decline-rematch="store.declineRematch()"
        @open-rematch="onOpenRematch"
        @friends="toFriends"
        @course="toCourse"
      />

      <LoadingState v-else-if="live" variant="center" />
    </template>

    <LeaveConfirm
      v-if="leaving"
      :variant="leaving"
      :invite="status === 'invited'"
      :busy="acting !== null"
      @confirm="confirmLeave"
      @stay="leaving = null"
    />
  </section>
</template>
