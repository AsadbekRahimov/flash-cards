<script setup lang="ts">
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import BattleScoreboard from '@/components/battle/BattleScoreboard.vue'
import { resultTitleKey } from '@/components/battle/resultTitle'
import { VERDICT_LOOK } from '@/components/battle/verdict'
import { lessonHeader, modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { BattleAction } from '@/stores/battle'
import type { Battle, BattleEndReason, BattleOutcome, BattleRematch, BattleResult, BattleRoundResult, BattleStatus } from '@/api/types'

/**
 * The end of a battle (docs/16 §11.2, docs/15 §3.5): `finished` (somebody won, or it is a draw) and `closed` (it did not
 * take place). From top to bottom:
 * - the outcome, big - "Victory", "Defeat", "Draw", "The match did not take place" (or "The match was interrupted": it began, and
 *   both players left or the server voided it, `resultTitleKey`) - with a symbol, a colour and the reason in words (`end_reason`: all
 *   rounds played, surrender, left and did not come back, both gone, declined, the time of the invitation or of the room ran out,
 *   cancelled, voided - for a dictation the recording that is not there);
 * - the score and the rounds, one row each, "You | Name", with the symbol, the points and (for a screen reader) the verdict;
 * - the actions: "Rematch" (the one primary button; "Invite again" after a battle that did not take place), "To friends",
 *   "To the course". After a decline there is no new invitation: "To friends" is the primary one.
 *
 * `rematch` is the rematch that follows this battle as the server reports it - `{battle_id, status, by_me}`, no battle to read:
 * an offer of the friend is answered here ("Accept" is then the primary button, "Rematch" gives way to it); a rematch of the learner's
 * own that waits, and one that is on already (the friend took it, or the learner took the friend's on another device), is a way back
 * into it. Events: `rematch`, `accept-rematch`, `decline-rematch`, `open-rematch`, `friends`, `course`.
 */
const props = defineProps<{
  battle: Battle
  /** The result of the server; for a battle that carries none the screen passes an outcome of `none` with the reason. */
  result: BattleResult
  rematch: BattleRematch | null
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
}

const LOOKS: Record<BattleOutcome, Look> = {
  won: { icon: 'trophy', circle: 'bg-success-soft text-success-ink' },
  lost: { icon: 'flag', circle: 'bg-track text-tg-subtitle' },
  draw: { icon: 'arrows-right-left', circle: 'bg-accent-soft text-accent-ink' },
  none: { icon: 'information-circle', circle: 'bg-track text-tg-subtitle' },
}

const look = computed(() => LOOKS[props.result.outcome])
const title = computed((): string => t(resultTitleKey(props.battle, props.result)))

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
  // Only a dictation has a recording to lack. The server voids a battle for nothing else but a missing clip (or practice switched
  // off, rare), so for a dictation the reason is told as the one the learner may have met in the room: the recording is not there.
  if (why === 'voided' && props.battle.mode === 'dictation') return t('battle.reason.voided_audio')
  return t(PLAIN_REASONS[why] ?? 'battle.reason.canceled')
})

const topic = computed(() => {
  const place = props.battle.lesson ? lessonHeader(props.battle.lesson) : props.battle.course.title
  return `${modeTitle(props.battle.mode)} · ${place}`
})

const showScore = computed(() => props.result.outcome !== 'none')

// ---- the rematch ----

const OPEN_STATUSES: readonly BattleStatus[] = ['invited', 'room', 'countdown', 'playing']

/** The friend offers a rematch (`by_me` is false) and it waits for the learner: to be answered here. */
const offered = computed(() => props.rematch !== null && !props.rematch.by_me && props.rematch.status === 'invited')
/** A rematch that is the learner's to open: their own offer that waits, or one that is on already (taken by the friend, or by the learner elsewhere). */
const ownRematch = computed(() => props.rematch !== null && !offered.value && OPEN_STATUSES.includes(props.rematch.status))
/** The rematch is on already: the room is open, the card says so instead of "waiting for the friend". */
const rematchOn = computed(() => ownRematch.value && props.rematch?.status !== 'invited')
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
        <h1 class="text-[28px] leading-[34px] font-bold tracking-tight" data-testid="result-title">{{ title }}</h1>
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
      <section v-if="offered" class="card flex flex-col gap-1" data-testid="rematch-offer">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('battle.result.rematch_from', { name: opponentName }) }}</h2>
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('battle.result.rematch_text') }}</p>
      </section>
      <section v-else-if="ownRematch" class="card flex flex-col gap-1" data-testid="rematch-own" :data-state="rematchOn ? 'on' : 'waiting'">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ rematchOn ? t('battle.result.rematch_on') : t('battle.result.rematch_mine') }}</h2>
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ rematchOn ? t('battle.result.rematch_on_text') : t('battle.result.rematch_mine_text') }}</p>
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
