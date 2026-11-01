<script lang="ts">
/** What to say about a player: in the room (`ready`, `not_ready`), waiting for an answer to the invitation (`invited`), in a round (`answering`, `answered`), or `absent`. */
export type PlayerState = 'invited' | 'not_ready' | 'ready' | 'answering' | 'answered' | 'absent'
</script>

<script setup lang="ts">
import { computed } from 'vue'
import InitialAvatar from '@/components/InitialAvatar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import type { StringKey } from '@/i18n/ru'
import { useI18n } from '@/i18n'

/**
 * One player and what they are doing now (docs/16 §11.3): the avatar with the first letter, the name, and a chip with a
 * symbol and a word - "answered", "answering…", "ready", "no connection". Used for the opponent while the learner waits
 * for them (an invitation, the room, a round) and for the learner themself in the room.
 *
 * - `name` — already localized ("You", or the name of the opponent).
 * - `state` — see `PlayerState`; `null` shows no chip.
 * - `accent` — the avatar in the accent colour (the learner).
 *
 * The chip is a polite live region: the change of the state of the opponent is heard by a screen reader.
 */
const props = defineProps<{
  name: string
  /** `null`: just the person, no chip. */
  state: PlayerState | null
  accent?: boolean
}>()

const { t } = useI18n()

interface Look {
  icon: IconName
  chip: string
  label: StringKey
}

const LOOKS: Record<PlayerState, Look> = {
  invited: { icon: 'clock', chip: 'chip-neutral', label: 'battle.player.invited' },
  not_ready: { icon: 'clock', chip: 'chip-neutral', label: 'battle.player.not_ready' },
  ready: { icon: 'check', chip: 'chip-success', label: 'battle.player.ready' },
  answering: { icon: 'pencil-square', chip: 'chip-neutral', label: 'battle.player.answering' },
  answered: { icon: 'check', chip: 'chip-success', label: 'battle.player.answered' },
  absent: { icon: 'exclamation-triangle', chip: 'chip-warning', label: 'battle.player.absent' },
}

const look = computed(() => (props.state === null ? null : LOOKS[props.state]))
</script>

<template>
  <div class="flex items-center gap-3" data-testid="player-status" :data-state="state">
    <InitialAvatar :name="name" :accent="accent" />
    <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-medium" data-testid="player-name">{{ name }}</span>
    <span v-if="look" class="chip shrink-0" :class="look.chip" role="status" data-testid="player-chip">
      <AppIcon :name="look.icon" :size="14" />
      {{ t(look.label) }}
    </span>
  </div>
</template>
