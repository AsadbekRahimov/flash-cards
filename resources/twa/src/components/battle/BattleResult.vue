<script setup lang="ts">
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import BattleScoreboard from '@/components/battle/BattleScoreboard.vue'
import { VERDICT_LOOK } from '@/components/battle/verdict'
import { lessonHeader, modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { BattleAction } from '@/stores/battle'
import type { Battle, BattleEndReason, BattleOutcome, BattleResult, BattleRoundResult } from '@/api/types'

/**
 * The end of a battle (docs/16 §11.2, docs/15 §3.5): `finished` (somebody won, or it is a draw) and `closed` (it did not
 * take place). From top to bottom:
 * - the outcome, big - "Victory", "Defeat", "Draw", "The match did not take place" - with a symbol, a colour and the
 *   reason in words (`end_reason`: all rounds played, surrender, left and did not come back, both gone, declined,
 *   the time of the invitation or of the room ran out, cancelled, voided);
 * - the score and the rounds, one row each, "You | Name", with the symbol, the points and (for a screen reader) the verdict;
 * - the actions: "Rematch" (the one primary button; "Invite again" after a battle that did not take place), "To friends",
 *   "To the course". After a decline there is no new invitation: "To friends" is the primary one.
 *
 * `rematch` is the rematch that follows this battle, read in full: an offer to the learner is answered here ("Accept" is
 * then the primary button, "Rematch" gives way to it), an offer of their own is a way back into it.
 * Events: `rematch`, `accept-rematch`, `decline-rematch`, `open-rematch`, `friends`, `course`.
 */
const props = defineProps<{
  battle: Battle
  /** The result of the server; for a battle that carries none the screen passes an outcome of `none` with the reason. */
  result: BattleResult
  rematch: Battle | null
  busy: BattleAction | null
}>()

const emit = defineEmits<{
  rematch: []
  'accept-rematch': []
  'decline-rematch': []
  'open-rematch': []
  friends: []
  course: []
}>()

const { t } = useI18n()

const opponentName = computed(() => props.battle.opponent.first_name?.trim() || t('battle.opponent'))

interface Look {
  icon: IconName
  circle: string
  title: StringKey
}

const LOOKS: Record<BattleOutcome, Look> = {
  won: { icon: 'trophy', circle: 'bg-success-soft text-success-ink', title: 'battle.result.title.won' },
  lost: { icon: 'flag', circle: 'bg-track text-tg-subtitle', title: 'battle.result.title.lost' },
  draw: { icon: 'arrows-right-left', circle: 'bg-accent-soft text-accent-ink', title: 'battle.result.title.draw' },
  none: { icon: 'information-circle', circle: 'bg-track text-tg-subtitle', title: 'battle.result.title.none' },
}

const look = computed(() => LOOKS[props.result.outcome])

/** The reasons that do not depend on who won; the others read differently for the winner and for the one who lost. */
const PLAIN_REASONS: Partial<Record<BattleEndReason, StringKey>> = {
  both_absent: 'battle.reason.both_absent',
  declined: 'battle.reason.declined',
  invite_expired: 'battle.reason.invite_expired',
  room_expired: 'battle.reason.room_expired',
  canceled: 'battle.reason.canceled',
  voided: 'battle.reason.voided',
}

const reason = computed((): string => {
  const { end_reason: why, outcome } = props.result
  if (why === 'completed') return t(outcome === 'draw' ? 'battle.reason.completed_draw' : 'battle.reason.completed')
  if (why === 'surrender') return t(outcome === 'won' ? 'battle.reason.surrender_won' : 'battle.reason.surrender_lost')
  if (why === 'absent') return t(outcome === 'won' ? 'battle.reason.absent_won' : 'battle.reason.absent_lost')
  return t(PLAIN_REASONS[why] ?? 'battle.reason.canceled')
})

const topic = computed(() => {
  const place = props.battle.lesson ? lessonHeader(props.battle.lesson) : props.battle.course.title
  return `${modeTitle(props.battle.mode)} · ${place}`
})

const showScore = computed(() => props.result.outcome !== 'none')

// ---- the rematch ----

const offered = computed(() => props.rematch !== null && props.rematch.role === 'opponent' && props.rematch.status === 'invited')
const ownRematch = computed(
  () => props.rematch !== null && props.rematch.role === 'challenger' && ['invited', 'room', 'countdown', 'playing'].includes(props.rematch.status),
)
/** After a decline nobody asks the friend again at once; after a game, or a battle that fell through, one may. */
const canInviteAgain = computed(() => !offered.value && !ownRematch.value && props.result.end_reason !== 'declined')
const finished = computed(() => props.battle.status === 'finished')

const cell = (side: BattleRoundResult['me']) => ({ look: VERDICT_LOOK[side.verdict], points: side.points })
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="battle-result" :data-outcome="result.outcome" :data-reason="result.end_reason">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-3 pb-6">
      <header class="flex flex-col items-center gap-2 text-center">
        <span class="flex size-20 items-center justify-center rounded-full" :class="look.circle" data-testid="result-icon">
          <AppIcon :name="look.icon" solid :size="44" />
        </span>
        <h1 class="text-[28px] leading-[34px] font-bold tracking-tight" data-testid="result-title">{{ t(look.title) }}</h1>
        <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="result-reason">{{ reason }}</p>
        <p class="text-[13px] leading-[18px] text-tg-hint break-words" data-testid="result-topic">{{ topic }}</p>
      </header>

      <BattleScoreboard v-if="showScore" :me="battle.scores.me" :opponent="battle.scores.opponent" :opponent-name="opponentName" size="lg" />

      <section v-if="result.rounds.length > 0" data-testid="result-rounds">
        <h2 class="section-label mt-0">{{ t('battle.result.rounds') }}</h2>
        <div class="list">
          <div class="list-row grid min-h-10 grid-cols-[2rem_minmax(0,1fr)_minmax(0,1fr)] gap-2 py-2 text-[13px] leading-[18px] text-tg-hint">
            <span aria-hidden="true">#</span>
            <span class="truncate">{{ t('battle.you') }}</span>
            <span class="truncate">{{ opponentName }}</span>
          </div>
          <div
            v-for="round in result.rounds"
            :key="round.no"
            class="list-row grid grid-cols-[2rem_minmax(0,1fr)_minmax(0,1fr)] gap-2"
            data-testid="round-row"
            :data-round="round.no"
          >
            <span class="text-[15px] leading-5 text-tg-hint tabular-nums"><span class="sr-only">{{ t('battle.result.round', { round: round.no }) }}</span><span aria-hidden="true">{{ round.no }}</span></span>
            <span class="inline-flex items-center gap-2" data-testid="round-me" :data-verdict="round.me.verdict">
              <span class="flex size-6 shrink-0 items-center justify-center rounded-full" :class="cell(round.me).look.soft"><AppIcon :name="cell(round.me).look.icon" :size="14" /></span>
              <span class="text-base leading-[22px] font-semibold tabular-nums">{{ cell(round.me).points }}</span>
              <span class="sr-only">{{ t(cell(round.me).look.label) }}</span>
            </span>
            <span class="inline-flex items-center gap-2" data-testid="round-opponent" :data-verdict="round.opponent.verdict">
              <span class="flex size-6 shrink-0 items-center justify-center rounded-full" :class="cell(round.opponent).look.soft"><AppIcon :name="cell(round.opponent).look.icon" :size="14" /></span>
              <span class="text-base leading-[22px] font-semibold tabular-nums">{{ cell(round.opponent).points }}</span>
              <span class="sr-only">{{ t(cell(round.opponent).look.label) }}</span>
            </span>
          </div>
        </div>
      </section>

      <!-- The friend offers a rematch: answered here. -->
      <section v-if="offered && rematch" class="card flex flex-col gap-1" data-testid="rematch-offer">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('battle.result.rematch_from', { name: rematch.opponent.first_name?.trim() || t('battle.opponent') }) }}</h2>
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('battle.result.rematch_text') }}</p>
      </section>
      <section v-else-if="ownRematch" class="card flex flex-col gap-1" data-testid="rematch-own">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('battle.result.rematch_mine') }}</h2>
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('battle.result.rematch_mine_text') }}</p>
      </section>
    </div>

    <StickyActions>
      <template v-if="offered">
        <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy !== null" data-testid="accept-rematch-btn" @click="emit('accept-rematch')">
          {{ t('battle.invite.accept') }}
        </button>
        <button type="button" class="btn btn-tinted btn-block" :disabled="busy !== null" data-testid="decline-rematch-btn" @click="emit('decline-rematch')">
          {{ t('battle.invite.decline') }}
        </button>
      </template>
      <button v-else-if="ownRematch" type="button" class="btn btn-primary btn-lg btn-block" data-testid="open-rematch-btn" @click="emit('open-rematch')">
        {{ t('battle.result.open_rematch') }}
      </button>
      <button v-else-if="canInviteAgain" type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy !== null" data-testid="rematch-btn" @click="emit('rematch')">
        {{ finished ? t('battle.result.rematch') : t('battle.result.again') }}
      </button>

      <button
        type="button"
        class="btn btn-block"
        :class="offered || ownRematch || canInviteAgain ? 'btn-tinted' : 'btn-primary btn-lg'"
        data-testid="friends-btn"
        @click="emit('friends')"
      >
        {{ t('battle.to_friends') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="course-btn" @click="emit('course')">{{ t('battle.to_course') }}</button>
    </StickyActions>
  </div>
</template>
