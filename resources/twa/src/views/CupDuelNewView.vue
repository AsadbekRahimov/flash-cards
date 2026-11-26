<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import PlayerName from '@/components/PlayerName.vue'
import CupDuelKind from '@/components/cup/CupDuelKind.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { duelBlockReason } from '@/composables/cupDuelInfo'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupDuelsStore } from '@/stores/cupDuels'
import { useI18n } from '@/i18n'
import type { CupStandingRow } from '@/api/types'

/**
 * A challenge to a duel of a cup (docs/33 §5.7, §7.1, docs/04 A.23d `POST /cups/{id}/duels`): whom - a participant of the table (only
 * the ones still in the group, never me) or anybody in the group (an open challenge, published in the chat); the kind the server will
 * offer - rated (a point of the cup for each own right answer, one rated duel a day) while my rated duel of the day is not played, else
 * friendly (no points of the cup, one of the free battles of the day on Free) - the server decides it and tells it in the answer; then the
 * confirmation and "Send". One `request_id` per attempt: a repeat after a lost reply sends the same one (the server answers it with the
 * duel it made), a double tap is one request. After the challenge - its screen, where the challenger waits.
 *
 * The table is the one of the cup screen (no second read while it is young). My open challenge is shown instead of a new one; a day
 * without challenges says why. The free battles spent (403 `practice_limit_reached`) - the paywall of the practice.
 */
const props = defineProps<{ id: number }>()

/** The choice of "anybody in the group". */
const PUBLIC = 'public'

const router = useRouter()
const auth = useAuthStore()
const boards = useCupBoardStore()
const duels = useCupDuelsStore()
const billing = useBillingStore()
const { haptic } = useTelegram()
const { t } = useI18n()
const { creating, createFailure, limitHit, off } = storeToRefs(duels)
const { battleLimit } = storeToRefs(billing)

useBackButton({ name: 'cup', params: { id: props.id } })

let left = false
const authError = ref('')
const loaded = ref(false)

async function load(): Promise<void> {
  authError.value = ''
  loaded.value = false
  try {
    await auth.ensure()
  } catch {
    authError.value = t('error.generic')
    return
  }
  if (left || off.value) return
  // The free battles of today are asked beside: best effort, the screen works without the line.
  void billing.load()
  if (!boards.isFreshFor(props.id)) await boards.load(props.id)
  if (!left) loaded.value = true
}

onMounted(() => {
  duels.resetCreate()
  void load()
})

watch(() => props.id, () => {
  duels.resetCreate()
  void load()
})

onBeforeUnmount(() => {
  left = true
})

const board = computed(() => (boards.board !== null && boards.board.cup.id === props.id ? boards.board : null))
const me = computed(() => board.value?.me ?? null)
const availability = computed(() => me.value?.duel ?? null)

type ViewState = 'loading' | 'ready' | 'disabled' | 'unavailable' | 'off' | 'not_participant' | 'error'

const view = computed((): ViewState => {
  if (authError.value !== '') return 'error'
  if (off.value) return 'disabled'
  const failed = createFailure.value?.kind
  if (failed === 'disabled') return 'disabled'
  if (failed === 'unavailable' || failed === 'off') return failed
  if (board.value !== null && loaded.value) {
    if (me.value === null) return 'not_participant'
    // `me.duel` null: the server has the duels off.
    return availability.value ? 'ready' : 'disabled'
  }
  const failure = boards.failure
  if (boards.status === 'failed' && failure !== null) {
    if (failure.kind === 'unavailable' || failure.kind === 'off') return failure.kind
    return 'error'
  }
  return 'loading'
})

const errorText = computed((): string => authError.value || createFailure.value?.message || boards.failure?.message || t('error.generic'))

/** The participants I may challenge: the table without me and without the ones who left the group. */
const candidates = computed((): CupStandingRow[] => (board.value?.standings ?? []).filter((row) => !row.me && !row.left))

const reason = computed((): string | null => (availability.value && board.value ? duelBlockReason(availability.value, board.value) : null))
const openDuelId = computed((): number | null => availability.value?.open_duel_id ?? createFailure.value?.duelId ?? null)
const canChallenge = computed((): boolean => availability.value?.can_challenge === true && openDuelId.value === null)

const choice = ref<string | null>(null)
const confirmOpen = ref(false)

const chosen = computed((): CupStandingRow | null => candidates.value.find((row) => String(row.participant_id) === choice.value) ?? null)
const isPublic = computed((): boolean => choice.value === PUBLIC)

/** The kind the challenge will offer, as the server's hint says: rated while my rated duel of the day is not played. */
const rated = computed((): boolean => availability.value?.rated_available === true)

const rowName = (row: CupStandingRow): string => row.display_name || t('league.anonymous')

function pick(value: string): void {
  if (creating.value) return
  haptic('light')
  choice.value = value
}

function openConfirm(): void {
  if (choice.value === null || !canChallenge.value) return
  haptic('light')
  confirmOpen.value = true
}

const confirmTitle = computed((): string => (isPublic.value ? t('cup.duel.new.confirm_public') : t('cup.duel.new.confirm_personal', { name: chosen.value ? rowName(chosen.value) : '' })))

async function send(): Promise<void> {
  if (creating.value || choice.value === null) return
  haptic('medium')
  const opponent = isPublic.value ? null : (chosen.value?.participant_id ?? null)
  if (!isPublic.value && opponent === null) return
  const duel = await duels.challenge(props.id, opponent)
  if (left) return
  if (duel !== null) {
    confirmOpen.value = false
    await router.replace({ name: 'cup-duel', params: { id: props.id, duelId: duel.id } })
    return
  }
  // The paywall and the states of the screen replace the sheet; a refusal with its words stays in it.
  if (limitHit.value !== null || view.value !== 'ready') confirmOpen.value = false
}

function openMine(): void {
  const id = openDuelId.value
  if (id !== null) void router.push({ name: 'cup-duel', params: { id: props.id, duelId: id } })
}

const sendError = computed((): string => (createFailure.value !== null && createFailure.value.kind !== 'limit' ? createFailure.value.message : ''))

const { reviewFromPaywall } = useReviewFromPaywall(() => duels.dismissPaywall(), { toastErrors: true })
</script>

<template>
  <div class="page" :data-state="view" data-testid="cup-duel-new-view">
    <PageHeader :eyebrow="board ? board.cup.group_title : t('cup.title')" :title="t('cup.duel.new.title')" />

    <LoadingState v-if="view === 'loading'" variant="list" />

    <EmptyState v-else-if="view === 'disabled'" icon="lock-closed" :title="t('cup.duel.off_title')" :text="t('cup.duel.failure.disabled')" data-testid="duels-off">
      <template #action>
        <RouterLink :to="{ name: 'cup', params: { id } }" class="btn btn-tinted" data-testid="to-cup">{{ t('cup.round.to_cup') }}</RouterLink>
      </template>
    </EmptyState>

    <EmptyState v-else-if="view === 'unavailable'" icon="lock-closed" :title="t('cup.invite.unavailable_title')" :text="errorText" data-testid="unavailable" />

    <EmptyState v-else-if="view === 'off'" icon="lock-closed" :title="t('cup.off_title')" :text="errorText" data-testid="off" />

    <EmptyState v-else-if="view === 'not_participant'" icon="users" :text="t('cup.duel.failure.not_participant')" data-testid="not-participant" />

    <ErrorState v-else-if="view === 'error'" :message="errorText" @retry="load" />

    <div v-else-if="availability" class="space-y-5">
      <!-- My open challenge: shown instead of a new one. -->
      <section v-if="openDuelId !== null" class="card flex flex-col gap-3" data-testid="open-duel">
        <p class="text-[15px] leading-5">{{ t('cup.duel.failure.already_open') }}</p>
        <button type="button" class="btn btn-primary btn-block" data-testid="open-mine" @click="openMine">{{ t('cup.duel.open_mine') }}</button>
      </section>

      <InlineAlert v-else-if="!canChallenge" tone="info" :message="reason ?? t('cup.duel.reason.other')" data-testid="cannot-challenge" />

      <template v-else>
        <section data-testid="opponents">
          <h2 class="section-label mt-0">{{ t('cup.duel.new.whom') }}</h2>
          <div class="list" role="radiogroup" :aria-label="t('cup.duel.new.whom')">
            <button
              type="button"
              role="radio"
              class="list-row"
              :aria-checked="isPublic"
              :data-selected="isPublic"
              data-testid="opponent-public"
              @click="pick(PUBLIC)"
            >
              <span class="flex min-w-0 flex-1 flex-col">
                <span class="font-bold">{{ t('cup.duel.new.public') }}</span>
                <span class="text-[13px] leading-[18px] text-muted">{{ t('cup.duel.new.public_hint') }}</span>
              </span>
              <AppIcon v-if="isPublic" name="check" :size="20" class="text-accent" />
            </button>
            <button
              v-for="row in candidates"
              :key="row.participant_id"
              type="button"
              role="radio"
              class="list-row"
              :aria-checked="choice === String(row.participant_id)"
              :data-selected="choice === String(row.participant_id)"
              :data-testid="`opponent-${row.participant_id}`"
              @click="pick(String(row.participant_id))"
            >
              <PlayerName :name="rowName(row)" :emoji="row.pro_emoji" class="min-w-0 flex-1 font-bold" />
              <AppIcon v-if="choice === String(row.participant_id)" name="check" :size="20" class="text-accent" />
            </button>
          </div>
          <p v-if="candidates.length === 0" class="mt-2 text-[13px] leading-[18px] text-muted" data-testid="no-candidates">{{ t('cup.duel.new.no_candidates') }}</p>
        </section>

        <section class="card flex flex-col gap-2" data-testid="kind-section">
          <h2 class="text-base leading-[22px] font-bold">{{ t('cup.duel.new.kind') }}</h2>
          <CupDuelKind :rated="rated" hint />
          <p v-if="rated && !isPublic" class="text-[13px] leading-[18px] text-muted" data-testid="rated-note">{{ t('cup.duel.new.rated_note') }}</p>
          <p v-if="!rated && battleLimit" class="text-[13px] leading-[18px] text-muted" data-testid="battle-limit">
            {{ t('battle.limit.left', { remaining: Math.max(0, battleLimit.remaining), limit: battleLimit.limit }) }}
          </p>
        </section>

        <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="choice === null" data-testid="next-btn" @click="openConfirm">
          {{ t('cup.duel.new.next') }}
        </button>
      </template>
    </div>

    <BottomSheet v-if="confirmOpen" :label="confirmTitle" @close="confirmOpen = false">
      <div class="flex flex-col gap-3 px-4 pt-2 pb-4" data-testid="confirm-sheet">
        <h2 class="text-[22px] leading-7 font-bold" data-testid="confirm-title">{{ confirmTitle }}</h2>
        <CupDuelKind :rated="rated" hint />
        <p class="text-[15px] leading-5 text-muted" data-testid="confirm-text">{{ isPublic ? t('cup.duel.new.confirm_public_text') : t('cup.duel.new.confirm_personal_text') }}</p>
        <InlineAlert v-if="sendError" tone="danger" :message="sendError" data-testid="send-error" />
        <button v-if="createFailure?.duelId" type="button" class="btn btn-tinted btn-block" data-testid="open-existing" @click="openMine">{{ t('cup.duel.open_mine') }}</button>
        <button
          type="button"
          class="btn btn-primary btn-block"
          :disabled="creating"
          :aria-busy="creating ? 'true' : undefined"
          data-testid="send-btn"
          @click="send"
        >
          {{ createFailure?.uncertain ? t('common.retry') : t('cup.duel.new.send') }}
        </button>
        <button type="button" class="btn btn-plain btn-block" :disabled="creating" data-testid="confirm-cancel" @click="confirmOpen = false">{{ t('cup.duel.new.back') }}</button>
      </div>
    </BottomSheet>

    <PaywallSheet v-if="limitHit" reason="practice_limit" :limit-hit="limitHit" @close="duels.dismissPaywall()" @review="reviewFromPaywall" />
  </div>
</template>
