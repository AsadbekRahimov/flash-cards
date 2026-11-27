<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import PlayerName from '@/components/PlayerName.vue'
import CupDuelKind from '@/components/cup/CupDuelKind.vue'
import ClosedState from '@/components/ClosedState.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { duelBlockReason, duelOutcomeText } from '@/composables/cupDuelInfo'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupDuelsStore } from '@/stores/cupDuels'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { CupDuel, CupDuelPlayer } from '@/api/types'

/**
 * The duels of a cup (docs/33 §7.1, docs/04 A.23d `GET /cups/{id}/duels`, one request): the challenges to me ("Accept" / "Decline"),
 * the open ones to anybody in the group ("Accept"), my open challenge (waiting, "Cancel"), my duels of today (how each ended), and
 * "Challenge to a duel" when the server says I may (or why not). "Accept" goes to the match at once: its room waits only a short while.
 * The kind of a challenge (rated / friendly) is the server's; a changed one is told and the list read again. The names are the names in
 * the tournament with the emoji of Pro (`PlayerName`). The duels off (409 `cup_duel_disabled`): the section is gone for the session.
 */
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()
const duels = useCupDuelsStore()
const today = useTodayStore()
const boards = useCupBoardStore()
const { haptic } = useTelegram()
const { t, tn, locale } = useI18n()
const { list, status, failure, acting, actionError, notice, busyBattleId, limitHit, off } = storeToRefs(duels)

useBackButton({ name: 'cup', params: { id: props.id } })

let left = false
const authError = ref('')

async function load(): Promise<void> {
  authError.value = ''
  try {
    await auth.ensure()
  } catch {
    authError.value = t('error.generic')
    return
  }
  if (!left) await duels.load(props.id)
}

onMounted(() => {
  duels.clearMessages()
  // The paywall of another screen (a new challenge, "Back") is not this one's: the list opens without it.
  duels.dismissPaywall()
  void load()
})

watch(() => props.id, () => void load())

onBeforeUnmount(() => {
  left = true
})

const data = computed(() => (list.value !== null && duels.cupId === props.id ? list.value : null))

type ViewState = 'loading' | 'ready' | 'disabled' | 'unavailable' | 'off' | 'error'

const view = computed((): ViewState => {
  if (authError.value !== '') return 'error'
  if (off.value) return 'disabled'
  if (data.value !== null) return 'ready'
  if (status.value === 'failed' && failure.value !== null) {
    const kind = failure.value.kind
    if (kind === 'unavailable' || kind === 'off') return kind
    return 'error'
  }
  return 'loading'
})

const errorText = computed((): string => authError.value || failure.value?.message || t('error.generic'))

const availability = computed(() => data.value?.availability ?? null)

/** Why I may not challenge today; the state of the cup comes from the table the cup screen has read, when it is of this cup. */
const blockReason = computed((): string | null => {
  const a = availability.value
  if (a === null) return null
  const board = boards.board !== null && boards.board.cup.id === props.id ? boards.board : null
  return duelBlockReason(a, board)
})
const empty = computed((): boolean => {
  const d = data.value
  return d !== null && d.incoming.length === 0 && d.open.length === 0 && d.mine === null && d.today.length === 0
})

const zone = computed((): string | undefined => today.data?.timezone || undefined)
const until = (duel: CupDuel): string => t('cup.duel.until', { time: formatDate(duel.expires_at, 'time', locale.value, zone.value) })
const playerName = (player: CupDuelPlayer | null): string => (player === null ? t('cup.duel.anybody') : player.display_name || t('league.anonymous'))

/** The other player of my duel (the opponent of my challenge, the challenger of one to me); null - an open challenge nobody took. */
const rival = (duel: CupDuel): CupDuelPlayer | null => (duel.role === 'opponent' ? duel.challenger : duel.opponent)

/** "4 : 3" of a played duel, my points first. */
function score(duel: CupDuel): string {
  const points = duel.points
  if (points === null) return ''
  return duel.role === 'opponent' ? `${points.opponent} : ${points.challenger}` : `${points.challenger} : ${points.opponent}`
}

async function accept(duel: CupDuel): Promise<void> {
  haptic('medium')
  const battleId = await duels.accept(duel)
  if (battleId !== null && !left) await router.replace({ name: 'battle', params: { id: battleId } })
}

function decline(duel: CupDuel): void {
  haptic('light')
  void duels.decline(duel)
}

function cancel(duel: CupDuel): void {
  haptic('light')
  void duels.cancel(duel)
}

function openBusy(): void {
  const id = busyBattleId.value
  if (id !== null) void router.push({ name: 'battle', params: { id } })
}

const { reviewFromPaywall } = useReviewFromPaywall(() => duels.dismissPaywall(), { toastErrors: true })
</script>

<template>
  <div class="page" :data-state="view" data-testid="cup-duels-view">
    <PageHeader :eyebrow="t('cup.title')" :title="t('cup.duel.list_title')" />

    <LoadingState v-if="view === 'loading'" variant="list" />

    <ClosedState v-else-if="view === 'disabled'" mascot="thinking" :title="t('cup.duel.off_title')" :text="t('cup.duel.failure.disabled')" data-testid="duels-off">
      <RouterLink :to="{ name: 'cup', params: { id } }" class="btn btn-secondary btn-block" data-testid="to-cup">{{ t('cup.round.to_cup') }}</RouterLink>
    </ClosedState>

    <ClosedState v-else-if="view === 'unavailable'" mascot="thinking" :title="t('cup.invite.unavailable_title')" :text="errorText" data-testid="unavailable" />

    <ClosedState v-else-if="view === 'off'" mascot="thinking" :title="t('cup.off_title')" :text="errorText" data-testid="off" />

    <ErrorState v-else-if="view === 'error'" :message="errorText" :data-kind="failure?.kind" @retry="load" />

    <div v-else-if="data" class="space-y-5" :aria-busy="acting !== null ? 'true' : undefined">
      <InlineAlert v-if="notice" tone="warning" :message="notice" data-testid="list-notice" />
      <InlineAlert v-if="actionError" tone="danger" :message="actionError" data-testid="list-error" />
      <button v-if="busyBattleId !== null" type="button" class="btn btn-secondary btn-block" data-testid="open-busy" @click="openBusy">{{ t('battle.open_current') }}</button>

      <!-- Challenges to me. -->
      <section v-if="data.incoming.length > 0" data-testid="incoming">
        <h2 class="section-label mt-0">{{ t('cup.duel.group.incoming') }}</h2>
        <ul class="list">
          <li v-for="duel in data.incoming" :key="duel.id" class="list-row flex-col items-stretch gap-2" :data-duel="duel.id" data-testid="incoming-row">
            <div class="flex items-center gap-3">
              <PlayerName :name="playerName(duel.challenger)" :emoji="duel.challenger.pro_emoji" class="t-body-m-strong min-w-0 flex-1" />
              <CupDuelKind :rated="duels.ratedOf(duel)" />
            </div>
            <p class="t-label-s text-muted tabular-nums">{{ until(duel) }}</p>
            <div class="flex gap-2">
              <button
                type="button"
                class="btn btn-secondary btn-sm flex-1"
                :disabled="acting !== null"
                :aria-busy="acting === duel.id ? 'true' : undefined"
                data-testid="accept-btn"
                @click="accept(duel)"
              >
                {{ t('cup.duel.accept') }}
              </button>
              <button type="button" class="btn btn-ghost btn-sm flex-1" :disabled="acting !== null" data-testid="decline-btn" @click="decline(duel)">
                {{ t('cup.duel.decline') }}
              </button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Open challenges to anybody in the group. -->
      <section v-if="data.open.length > 0" data-testid="open">
        <h2 class="section-label mt-0">{{ t('cup.duel.group.open') }}</h2>
        <ul class="list">
          <li v-for="duel in data.open" :key="duel.id" class="list-row" :data-duel="duel.id" data-testid="open-row">
            <span class="flex min-w-0 flex-1 flex-col gap-1">
              <PlayerName :name="playerName(duel.challenger)" :emoji="duel.challenger.pro_emoji" class="t-body-m-strong" />
              <span class="t-label-s text-muted tabular-nums">{{ until(duel) }}</span>
            </span>
            <CupDuelKind :rated="duels.ratedOf(duel)" />
            <button
              type="button"
              class="btn btn-secondary btn-sm shrink-0"
              :disabled="acting !== null"
              :aria-busy="acting === duel.id ? 'true' : undefined"
              data-testid="accept-btn"
              @click="accept(duel)"
            >
              {{ t('cup.duel.accept') }}
            </button>
          </li>
        </ul>
      </section>

      <!-- My open challenge: waiting. -->
      <section v-if="data.mine" data-testid="mine">
        <h2 class="section-label mt-0">{{ t('cup.duel.group.mine') }}</h2>
        <div class="list">
          <div class="list-row" :data-duel="data.mine.id" data-testid="mine-row">
            <RouterLink :to="{ name: 'cup-duel', params: { id, duelId: data.mine.id } }" class="flex min-w-0 flex-1 flex-col gap-1" data-testid="mine-open">
              <PlayerName :name="playerName(data.mine.opponent)" :emoji="data.mine.opponent?.pro_emoji" class="t-body-m-strong" />
              <span class="t-label-s text-muted tabular-nums">{{ t('cup.duel.state.waiting') }} · {{ until(data.mine) }}</span>
            </RouterLink>
            <button
              type="button"
              class="btn btn-ghost btn-sm shrink-0"
              :disabled="acting !== null"
              :aria-busy="acting === data.mine.id ? 'true' : undefined"
              data-testid="cancel-btn"
              @click="cancel(data.mine)"
            >
              {{ t('cup.duel.cancel') }}
            </button>
          </div>
        </div>
      </section>

      <!-- "Challenge" or why not today. -->
      <section v-if="availability && data.mine === null" class="space-y-2" data-testid="challenge-section">
        <RouterLink v-if="availability.can_challenge" :to="{ name: 'cup-duel-new', params: { id } }" class="btn btn-primary btn-block" data-testid="challenge-btn">
          {{ t('cup.duel.challenge') }}
        </RouterLink>
        <p v-if="availability.can_challenge" class="text-center text-[13px] leading-[18px] text-muted" data-testid="challenges-left">
          {{ tn('cup.duel.challenges_left', availability.challenges_left) }}
        </p>
        <p v-else-if="blockReason" class="t-body-m text-muted" data-testid="cannot-challenge">{{ blockReason }}</p>
      </section>

      <!-- My duels of today. -->
      <section v-if="data.today.length > 0" data-testid="today">
        <h2 class="section-label mt-0">{{ t('cup.duel.group.today') }}</h2>
        <ul class="list">
          <li v-for="duel in data.today" :key="duel.id" :data-duel="duel.id" :data-status="duel.status">
            <RouterLink :to="{ name: 'cup-duel', params: { id, duelId: duel.id } }" class="list-row" data-testid="today-row">
              <span class="flex min-w-0 flex-1 flex-col gap-1">
                <PlayerName :name="playerName(rival(duel))" :emoji="rival(duel)?.pro_emoji" class="t-body-m-strong" />
                <span class="t-label-s text-muted" data-testid="today-outcome">{{ duelOutcomeText(duel) }}</span>
              </span>
              <span v-if="duel.points" class="t-body-m-strong shrink-0 font-display tabular-nums" data-testid="today-score">{{ score(duel) }}</span>
            </RouterLink>
          </li>
        </ul>
      </section>

      <!-- No duels yet: the mascot greets above the line (kit: an empty list is a centred hero, not an error). -->
      <div v-if="empty" class="flex flex-col items-center gap-2 px-4 py-4 text-center">
        <MascotArt pose="hello" :height="112" class="short:hidden" />
        <p class="t-body-m text-muted" data-testid="duels-empty">{{ t('cup.duel.empty') }}</p>
      </div>

      <RouterLink :to="{ name: 'cup', params: { id } }" class="btn btn-plain btn-block" data-testid="to-cup">{{ t('cup.round.to_cup') }}</RouterLink>
    </div>

    <PaywallSheet v-if="limitHit" reason="practice_limit" :limit-hit="limitHit" @close="duels.dismissPaywall()" @review="reviewFromPaywall" />
  </div>
</template>
