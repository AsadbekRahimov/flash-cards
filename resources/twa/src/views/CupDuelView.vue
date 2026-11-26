<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import PlayerName from '@/components/PlayerName.vue'
import CupDuelKind from '@/components/cup/CupDuelKind.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { DUEL_OVERDUE_MS, DUEL_POLL_MAX_MS, DUEL_POLL_MS, clockText, duelOutcomeText, duelRated } from '@/composables/cupDuelInfo'
import { showErrorToast } from '@/composables/errorToast'
import { useBackButton } from '@/composables/useBackButton'
import { useNow } from '@/composables/useNow'
import { usePolling } from '@/composables/usePolling'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useServerClock } from '@/composables/useServerClock'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useCupDuelStore } from '@/stores/cupDuel'
import { useI18n } from '@/i18n'
import type { CupDuelPlayer } from '@/api/types'

/**
 * A duel of a cup (docs/33 §5.7, §7.1, docs/04 A.23d): `/cups/:id/duels/:duelId`, and the link of the public challenge in the chat
 * (`TwaScreen::CupDuel`, `/cups/c/:code/duels/:duel`: the cup by its code first, then the duel).
 *
 * - Open, my challenge: "Waiting for the opponent", the time left by the server's `expires_at` / `server_now`, "Cancel". The screen polls
 *   the duel every 3 s while it is on screen and the page is in front (`usePolling`: one request at a time, slower after a 429 or a lost
 *   reply, stopped when the screen goes) - for at most the life of the challenge.
 * - Open, to me (or to anybody, for an open one): who challenges, the kind (rated / friendly) with what it means, "Accept" (with the kind
 *   shown: `expect_rated`; the kind changed meanwhile - the new one is shown and the learner accepts again) and "Decline".
 * - Accepted: the room of the match waits only a short while, so both players go to the match (`/battles/:id`) at once, without a
 *   question - the one who accepted by the answer of "Accept", the challenger by the poll.
 * - Over: played (the points of each, rated or not), void (nobody answered: the match did not take place), declined, canceled, expired,
 *   the cup over - in words.
 * A refusal is a state: the duels off (the section is gone for the session), the cup not available (a stranger, one who left - one state),
 * the cups off; a missing duel (404) leads back to the cup.
 */
const props = defineProps<{
  /** The cup (`/cups/:id/duels/:duelId`); null - by the code of the link. */
  id: number | null
  /** The duel; null - not a number in the link (a missing duel). */
  duelId: number | null
  /** The code of the cup in the link of the chat. */
  code?: string | null
}>()

const router = useRouter()
const auth = useAuthStore()
const store = useCupDuelStore()
const clock = useServerClock()
const now = useNow(1000)
const { haptic, notificationHaptic } = useTelegram()
const { t, tn } = useI18n()
const { duel, status, failure, acting, actionError, notice, busyBattleId, limitHit, cupId } = storeToRefs(store)

let left = false
const authError = ref('')

useBackButton(() => {
  const id = cupId.value
  void router.push(id !== null ? { name: 'cup', params: { id } } : { name: 'today' })
})

async function load(): Promise<void> {
  authError.value = ''
  try {
    await auth.ensure()
  } catch {
    authError.value = t('error.generic')
    return
  }
  if (left) return
  if (props.id !== null && props.duelId !== null) await store.open(props.id, props.duelId)
  else await store.openByCode(props.code ?? '', props.duelId)
}

onMounted(() => {
  void load()
})

watch([() => props.id, () => props.duelId, () => props.code], () => void load())

// ---- the time of the server ----

watch(
  () => duel.value?.server_now,
  (serverNow) => clock.sync(serverNow),
  { immediate: true },
)

const leftMs = computed((): number => {
  void now.value
  return duel.value ? clock.remaining(duel.value.expires_at) : 0
})

/** An open challenge whose time is up on the server's clock: told as expired (the server closes it on the next read). */
const timeUp = computed((): boolean => duel.value?.status === 'open' && leftMs.value <= 0)

// ---- the poll ----

const polling = usePolling(() => store.poll(), {
  enabled: computed(() => !left && status.value === 'ready' && duel.value?.status === 'open' && leftMs.value > -DUEL_OVERDUE_MS),
  intervalMs: DUEL_POLL_MS,
  maxIntervalMs: DUEL_POLL_MAX_MS,
})

onBeforeUnmount(() => {
  left = true
  polling.stop()
  store.reset()
})

// ---- to the match: at once, the room waits only a short while ----

const battleId = computed((): number | null => {
  const d = duel.value
  return d !== null && d.status === 'accepted' && d.role !== null ? d.battle_id : null
})

watch(
  battleId,
  (id) => {
    if (id === null || left) return
    notificationHaptic('success')
    void router.replace({ name: 'battle', params: { id } })
  },
  { immediate: true },
)

function toBattle(): void {
  const id = battleId.value ?? busyBattleId.value
  if (id !== null) void router.push({ name: 'battle', params: { id } })
}

// ---- a missing duel: back to the cup ----

watch(
  () => (status.value === 'failed' && failure.value?.kind === 'not_found' ? cupId.value : null),
  (id) => {
    if (id === null || left) return
    showErrorToast(failure.value?.message ?? t('cup.duel.failure.not_found'))
    void router.replace({ name: 'cup', params: { id } })
  },
  { immediate: true },
)

// ---- the view ----

type ViewState = 'loading' | 'ready' | 'disabled' | 'unavailable' | 'off' | 'not_found' | 'error'

const view = computed((): ViewState => {
  if (authError.value !== '') return 'error'
  if (status.value === 'ready' && duel.value !== null) return 'ready'
  if (status.value === 'failed' && failure.value !== null) {
    const kind = failure.value.kind
    if (kind === 'disabled' || kind === 'unavailable' || kind === 'off' || kind === 'not_found') return kind
    return 'error'
  }
  return 'loading'
})

const errorText = computed((): string => authError.value || failure.value?.message || t('error.generic'))

/** The state the screen tells: an open challenge whose time is up is "expired" already. */
const shownStatus = computed(() => (timeUp.value ? 'expired' : (duel.value?.status ?? null)))

const playerName = (player: CupDuelPlayer | null): string => (player === null ? t('cup.duel.anybody') : player.display_name || t('league.anonymous'))

const rated = computed((): boolean => (duel.value ? duelRated(duel.value) : false))
const role = computed(() => duel.value?.role ?? null)

/** The title of the state: "Waiting for the opponent", "X challenges you", "The duel is played"… */
const title = computed((): string => {
  const d = duel.value
  if (d === null) return t('cup.duel.title')
  switch (shownStatus.value) {
    case 'open':
      if (role.value === 'challenger') return t('cup.duel.state.waiting')
      return d.opponent === null ? t('cup.duel.state.open_to_all', { name: playerName(d.challenger) }) : t('cup.duel.state.challenged', { name: playerName(d.challenger) })
    case 'accepted':
      return t('cup.duel.state.accepted')
    case 'done':
      return t('cup.duel.state.done')
    default:
      return t('cup.duel.state.not_played')
  }
})

const outcome = computed((): string => {
  const d = duel.value
  if (d === null) return ''
  if (timeUp.value) return t('cup.duel.outcome.expired')
  return d.status === 'open' || d.status === 'accepted' ? '' : duelOutcomeText(d)
})

const timer = computed((): string => clockText(leftMs.value))

/** The points of a played duel: mine, when I played in it and it counted. */
const myPoints = computed((): number | null => {
  const d = duel.value
  if (d === null || d.status !== 'done' || d.points === null || d.rated !== true || d.end_reason === 'cup_closed' || d.role === null) return null
  return d.role === 'challenger' ? d.points.challenger : d.points.opponent
})

const winner = (player: CupDuelPlayer | null): boolean => player !== null && duel.value?.winner_participant_id === player.participant_id

const canAccept = computed((): boolean => shownStatus.value === 'open' && role.value !== 'challenger')
const canDecline = computed((): boolean => shownStatus.value === 'open' && role.value === 'opponent')
const canCancel = computed((): boolean => shownStatus.value === 'open' && role.value === 'challenger')

function accept(): void {
  haptic('medium')
  void store.accept()
}

function decline(): void {
  haptic('light')
  void store.decline()
}

function cancel(): void {
  haptic('light')
  void store.cancel()
}

const { reviewFromPaywall } = useReviewFromPaywall(() => store.dismissPaywall(), { toastErrors: true })

/** The words a screen reader hears when the state changes (waiting → accepted → over). */
const liveText = computed((): string => [title.value, outcome.value].filter((part) => part !== '').join('. '))
</script>

<template>
  <div class="page" :data-state="view" :data-status="shownStatus ?? undefined" data-testid="cup-duel-view">
    <PageHeader :eyebrow="t('cup.title')" :title="t('cup.duel.title')" />

    <LoadingState v-if="view === 'loading' || view === 'not_found'" variant="cards" />

    <EmptyState v-else-if="view === 'disabled'" icon="lock-closed" :title="t('cup.duel.off_title')" :text="errorText" data-testid="duels-off">
      <template v-if="cupId !== null" #action>
        <RouterLink :to="{ name: 'cup', params: { id: cupId } }" class="btn btn-tinted" data-testid="to-cup">{{ t('cup.round.to_cup') }}</RouterLink>
      </template>
    </EmptyState>

    <EmptyState v-else-if="view === 'unavailable'" icon="lock-closed" :title="t('cup.invite.unavailable_title')" :text="errorText" data-testid="unavailable">
      <template v-if="code" #action>
        <RouterLink :to="{ name: 'cup-invite', params: { code } }" class="btn btn-tinted" data-testid="to-invite">{{ t('cup.duel.open_cup') }}</RouterLink>
      </template>
    </EmptyState>

    <EmptyState v-else-if="view === 'off'" icon="lock-closed" :title="t('cup.off_title')" :text="errorText" data-testid="off" />

    <ErrorState v-else-if="view === 'error'" :message="errorText" :data-kind="failure?.kind" @retry="load" />

    <div v-else-if="duel" class="space-y-3">
      <!-- The change of the state (waiting → accepted → over) is announced politely. -->
      <p class="sr-only" role="status" aria-live="polite" data-testid="duel-live">{{ liveText }}</p>
      <section class="flex flex-col gap-3 rounded-hero bg-highlight p-4" :aria-busy="acting !== null ? 'true' : undefined" data-testid="duel-card">
        <h2 class="text-[22px] leading-7 font-extrabold break-words" data-testid="duel-title">{{ title }}</h2>
        <CupDuelKind :rated="rated" :hint="shownStatus === 'open'" />

        <!-- The players: the score once played. -->
        <div class="flex items-center gap-3" data-testid="duel-players">
          <span class="flex min-w-0 flex-1 flex-col" :data-winner="winner(duel.challenger) ? 'true' : undefined" data-testid="challenger">
            <PlayerName :name="playerName(duel.challenger)" :emoji="duel.challenger.pro_emoji" :suffix="role === 'challenger' ? `· ${t('league.you')}` : undefined" class="text-base leading-[22px] font-bold" />
          </span>
          <span v-if="duel.points" class="shrink-0 font-mono text-[22px] leading-7 font-bold" data-testid="duel-score">{{ duel.points.challenger }} : {{ duel.points.opponent }}</span>
          <span v-else class="shrink-0 text-[13px] leading-[18px] text-muted" aria-hidden="true">{{ t('cup.duel.versus') }}</span>
          <span class="flex min-w-0 flex-1 flex-col items-end text-right" :data-winner="winner(duel.opponent) ? 'true' : undefined" data-testid="opponent">
            <PlayerName
              :name="playerName(duel.opponent)"
              :emoji="duel.opponent?.pro_emoji"
              :suffix="role === 'opponent' ? `· ${t('league.you')}` : undefined"
              class="text-base leading-[22px] font-bold"
              :class="duel.opponent === null ? 'text-muted' : ''"
            />
          </span>
        </div>

        <template v-if="shownStatus === 'open'">
          <p class="text-[15px] leading-5" data-testid="duel-timer">
            {{ t('cup.duel.time_left') }} <span class="font-mono font-medium" role="timer">{{ timer }}</span>
          </p>
          <p v-if="role === 'challenger'" class="text-[13px] leading-[18px] text-muted" data-testid="waiting-hint">{{ t('cup.duel.waiting_hint') }}</p>
        </template>

        <template v-else-if="shownStatus === 'accepted'">
          <p class="text-[15px] leading-5" data-testid="duel-accepted">{{ battleId !== null ? t('cup.duel.to_match_hint') : t('cup.duel.outcome.taken') }}</p>
          <button v-if="battleId !== null" type="button" class="btn btn-primary btn-lg btn-block" data-testid="to-battle" @click="toBattle">{{ t('cup.duel.to_match') }}</button>
        </template>

        <template v-else>
          <p class="text-[15px] leading-5" data-testid="duel-outcome">{{ outcome }}</p>
          <p v-if="myPoints !== null" class="text-[15px] leading-5 font-semibold" data-testid="duel-my-points">{{ t('cup.duel.my_points', { points: tn('cup.points', myPoints) }) }}</p>
        </template>
      </section>

      <InlineAlert v-if="notice" tone="warning" :message="notice" data-testid="duel-notice" />
      <InlineAlert v-if="actionError" tone="danger" :message="actionError" data-testid="duel-error" />
      <button v-if="busyBattleId !== null" type="button" class="btn btn-tinted btn-block" data-testid="open-busy" @click="toBattle">{{ t('battle.open_current') }}</button>

      <div v-if="canAccept || canDecline || canCancel" class="flex flex-col gap-2" data-testid="duel-actions">
        <button
          v-if="canAccept"
          type="button"
          class="btn btn-primary btn-lg btn-block"
          :disabled="acting !== null"
          :aria-busy="acting === 'accept' ? 'true' : undefined"
          data-testid="accept-btn"
          @click="accept"
        >
          {{ t('cup.duel.accept') }}
        </button>
        <button
          v-if="canDecline"
          type="button"
          class="btn btn-plain btn-block"
          :disabled="acting !== null"
          :aria-busy="acting === 'decline' ? 'true' : undefined"
          data-testid="decline-btn"
          @click="decline"
        >
          {{ t('cup.duel.decline') }}
        </button>
        <button
          v-if="canCancel"
          type="button"
          class="btn btn-tinted btn-block"
          :disabled="acting !== null"
          :aria-busy="acting === 'cancel' ? 'true' : undefined"
          data-testid="cancel-btn"
          @click="cancel"
        >
          {{ t('cup.duel.cancel') }}
        </button>
      </div>

      <RouterLink v-if="cupId !== null" :to="{ name: 'cup-duels', params: { id: cupId } }" class="btn btn-plain btn-block" data-testid="to-duels">{{ t('cup.duel.list_title') }}</RouterLink>
      <RouterLink v-if="cupId !== null" :to="{ name: 'cup', params: { id: cupId } }" class="btn btn-plain btn-block" data-testid="to-cup">{{ t('cup.round.to_cup') }}</RouterLink>
    </div>

    <PaywallSheet v-if="limitHit" reason="practice_limit" :limit-hit="limitHit" @close="store.dismissPaywall()" @review="reviewFromPaywall" />
  </div>
</template>
