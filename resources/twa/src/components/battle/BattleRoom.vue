<script setup lang="ts">
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import OpponentStatus from '@/components/battle/OpponentStatus.vue'
import type { PlayerState } from '@/components/battle/OpponentStatus.vue'
import { clockText, remainingMs, secondsLeft } from '@/composables/battleTime'
import { MODE_LOOK, lessonHeader, modeTitle } from '@/composables/practiceLabels'
import { useNow } from '@/composables/useNow'
import { useI18n } from '@/i18n'
import type { BattleAction } from '@/stores/battle'
import type { AudioState } from '@/stores/practice'
import type { Battle } from '@/api/types'

/**
 * Before the match (docs/16 §11.2): the invitation that is sent, the invitation that came, the room.
 *
 * - **Sent** (`invited`, the challenger): "Waiting for {name}…", how long the invitation lasts, "Cancel".
 * - **Came** (`invited`, the opponent): who calls, the format, the topic, the rules; "Accept" (the one primary button)
 *   and "Decline".
 * - **Room** (`room`): both players with their "ready" marks, the format, the topic, the rules and the limit of a
 *   round; "Ready" (the one primary button; after it "Ready - waiting for the opponent") and "Leave". A dictation loads
 *   its five recordings first (`audio`): "Ready" waits for them, and a recording that did not come can be asked again.
 *
 * `ready` is the freshest of the poll; `expiresAt` the end of the invitation / of the room by the server's clock
 * (`offsetMs`). Events: `accept`, `decline`, `cancel`, `ready`, `leave`, `retry-audio`, `open-busy` (the learner's other
 * battle that stands in the way of "Accept").
 */
const props = defineProps<{
  battle: Battle
  ready: { me: boolean; opponent: boolean }
  opponentPresent: boolean
  /** `invite_expires_at` while `invited`, `room_expires_at` in the room. */
  expiresAt: string | null
  offsetMs: number
  /** The action of the screen that is on its way. */
  busy: BattleAction | null
  /** The recordings of a dictation, loaded before "Ready"; null for the other formats. */
  audio?: { state: AudioState; loaded: number; total: number } | null
  /** The learner's other battle that stands in the way (409 `battle_busy`). */
  busyBattleId?: number | null
}>()

const emit = defineEmits<{
  accept: []
  decline: []
  cancel: []
  ready: []
  leave: []
  'retry-audio': []
  'open-busy': []
}>()

const { t } = useI18n()
const now = useNow()

type Phase = 'invite_sent' | 'invite_received' | 'room'
const phase = computed((): Phase => {
  if (props.battle.status === 'room') return 'room'
  return props.battle.role === 'challenger' ? 'invite_sent' : 'invite_received'
})

const opponentName = computed(() => props.battle.opponent.first_name?.trim() || t('battle.opponent'))
const named = computed(() => Boolean(props.battle.opponent.first_name?.trim()))

const title = computed((): string => {
  if (phase.value === 'room') return t('battle.room.title')
  if (phase.value === 'invite_received') return t('practice.hub.battle.invited_by', { name: props.battle.opponent.first_name?.trim() || t('friends.list.anonymous') })
  return named.value ? t('battle.invite.waiting', { name: opponentName.value }) : t('battle.invite.waiting_anon')
})

const lead = computed((): string => {
  if (phase.value === 'room') return t('battle.room.lead')
  return phase.value === 'invite_received' ? t('battle.invite.lead') : t('battle.invite.lead_sent')
})

/** "Invitation lasts 4:12 more" / "The room closes in 1:45"; nothing once the time is out (the poll moves the battle on). */
const expires = computed((): string | null => {
  const left = remainingMs(props.expiresAt, now.value, props.offsetMs)
  if (props.expiresAt === null || left <= 0) return null
  const time = clockText(secondsLeft(left))
  return phase.value === 'room' ? t('battle.room.expires', { time }) : t('battle.invite.expires', { time })
})

const look = computed(() => MODE_LOOK[props.battle.mode])
const topic = computed(() => (props.battle.lesson ? lessonHeader(props.battle.lesson) : props.battle.course.title))

const rules = computed((): string[] => {
  const mode = props.battle.mode
  const rows = [
    t('battle.rules.rounds', { count: props.battle.rounds_total }),
    t('battle.rules.time', { seconds: props.battle.round_seconds }),
    mode === 'pairs' ? t('battle.rules.points_pairs') : t('battle.rules.points'),
    t('battle.rules.winner'),
    t('battle.rules.final'),
    t('battle.rules.leave'),
  ]
  if (mode === 'dictation') rows.push(t('battle.rules.dictation'))
  return rows
})

const opponentState = computed((): PlayerState => {
  if (phase.value === 'invite_sent') return 'invited'
  if (!props.opponentPresent) return 'absent'
  return props.ready.opponent ? 'ready' : 'not_ready'
})

/** "Ready" waits for the recordings of a dictation. */
const waitingForAudio = computed(() => props.audio != null && props.audio.state !== 'ready')
const canReady = computed(() => !props.ready.me && !waitingForAudio.value && props.busy === null)
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="battle-room" :data-phase="phase">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-3 pb-4">
      <header class="text-center">
        <h1 class="text-[22px] leading-7 font-bold break-words" data-testid="room-title">{{ title }}</h1>
        <p class="mt-1 text-[15px] leading-5 text-tg-subtitle" data-testid="room-lead">{{ lead }}</p>
        <p v-if="expires" class="mt-2 inline-flex items-center gap-1.5 text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="room-expires">
          <AppIcon name="clock" :size="16" />
          {{ expires }}
        </p>
      </header>

      <section class="card flex flex-col gap-3" data-testid="room-players">
        <OpponentStatus v-if="phase === 'room'" :name="t('battle.you')" :state="ready.me ? 'ready' : 'not_ready'" accent />
        <OpponentStatus :name="opponentName" :state="phase === 'invite_received' ? null : opponentState" />
      </section>

      <section class="card flex flex-col gap-3" data-testid="room-brief">
        <div class="flex items-center gap-3">
          <span class="icon-tile size-10 rounded-xl" :class="`tile-${look.tile}`"><AppIcon :name="look.icon" :size="22" /></span>
          <span class="min-w-0 flex-1">
            <span class="block text-[17px] leading-[22px] font-semibold" data-testid="room-mode">{{ modeTitle(battle.mode) }}</span>
            <span class="block truncate text-[15px] leading-5 text-tg-subtitle">{{ t(`practice.mode.${battle.mode}.example`) }}</span>
          </span>
        </div>
        <p class="flex items-center gap-2 text-[15px] leading-5" data-testid="room-topic">
          <AppIcon name="book-open" :size="18" class="text-tg-hint" />
          <span class="min-w-0 flex-1 break-words">{{ topic }}</span>
        </p>
        <p v-if="battle.lesson" class="-mt-1 pl-[26px] text-[13px] leading-[18px] text-tg-hint break-words">{{ battle.course.title }}</p>
      </section>

      <section data-testid="room-rules">
        <h2 class="section-label mt-0">{{ t('battle.rules.title') }}</h2>
        <ul class="card flex flex-col gap-2.5" role="list">
          <li v-for="rule in rules" :key="rule" class="flex items-start gap-2.5 text-[15px] leading-5" data-testid="rule-row">
            <AppIcon name="check-circle" :size="18" class="mt-px text-success" />
            <span class="min-w-0 flex-1 break-words">{{ rule }}</span>
          </li>
        </ul>
      </section>

      <!-- A dictation: the five recordings are loaded before "Ready" (docs/16 §10.3). -->
      <section v-if="phase === 'room' && audio" data-testid="room-audio" :data-state="audio.state">
        <InlineAlert v-if="audio.state === 'failed'" tone="warning" :message="t('battle.audio.failed')" data-testid="audio-failed" />
        <div v-else class="card flex flex-col gap-2">
          <p class="flex items-center gap-2 text-[15px] leading-5 font-medium" data-testid="audio-progress">
            <AppIcon :name="audio.state === 'ready' ? 'check-circle' : 'speaker-wave'" :size="18" :class="audio.state === 'ready' ? 'text-success' : 'text-tg-hint'" />
            {{ audio.state === 'ready' ? t('battle.audio.ready') : t('battle.audio.loading', { loaded: audio.loaded, total: audio.total }) }}
          </p>
          <div class="progress" aria-hidden="true">
            <div class="progress-fill" :class="{ 'progress-success': audio.state === 'ready' }" :style="{ width: `${audio.total > 0 ? Math.round((audio.loaded / audio.total) * 100) : 0}%` }" />
          </div>
        </div>
        <button v-if="audio.state === 'failed'" type="button" class="btn btn-tinted btn-sm mt-2" data-testid="audio-retry" @click="emit('retry-audio')">
          {{ t('practice.audio.retry') }}
        </button>
      </section>

      <div v-if="busyBattleId != null" class="space-y-2" data-testid="room-busy">
        <InlineAlert tone="warning" :message="t('error.code.battle_busy')" />
        <button type="button" class="btn btn-tinted btn-sm" data-testid="open-busy" @click="emit('open-busy')">{{ t('battle.open_current') }}</button>
      </div>
    </div>

    <StickyActions>
      <template v-if="phase === 'invite_received'">
        <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy !== null" data-testid="accept-btn" @click="emit('accept')">
          {{ t('battle.invite.accept') }}
        </button>
        <button type="button" class="btn btn-tinted btn-block" :disabled="busy !== null" data-testid="decline-btn" @click="emit('decline')">
          {{ t('battle.invite.decline') }}
        </button>
      </template>

      <template v-else-if="phase === 'invite_sent'">
        <button type="button" class="btn btn-tinted btn-block" :disabled="busy !== null" data-testid="cancel-btn" @click="emit('cancel')">
          {{ t('battle.invite.cancel') }}
        </button>
      </template>

      <template v-else>
        <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="!canReady" data-testid="ready-btn" @click="emit('ready')">
          <template v-if="ready.me">
            <AppIcon name="check" :size="20" />
            {{ t('battle.room.ready_done') }}
          </template>
          <template v-else>{{ t('battle.room.ready') }}</template>
        </button>
        <button type="button" class="btn btn-tinted btn-block" :disabled="busy !== null" data-testid="leave-btn" @click="emit('leave')">
          {{ t('battle.room.leave') }}
        </button>
      </template>
    </StickyActions>
  </div>
</template>
