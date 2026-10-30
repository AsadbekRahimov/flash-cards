<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/i18n'

/**
 * The score of a battle, big (docs/16 §11.2): "You 100 : 125 Name". The learner's side is on the left, the opponent's on
 * the right; the names are small above the numbers and cut off with an ellipsis when they are long.
 *
 * - `me` / `opponent` — the points of each (a sum of the rounds that are over: 0..500).
 * - `opponentName` — already localized.
 * - `size` — `md` (in a round, with the timer in the default slot under the score) or `lg` (the result).
 *
 * The group is named for a screen reader with the whole score, so that the digits are not read in pieces.
 */
const props = withDefaults(
  defineProps<{
    me: number
    opponent: number
    opponentName: string
    size?: 'md' | 'lg'
  }>(),
  { size: 'md' },
)

const { t } = useI18n()

const label = computed(() => t('battle.score.aria', { me: props.me, opponent: props.opponent, name: props.opponentName }))
const digits = computed(() => (props.size === 'lg' ? 'text-[44px] leading-[48px]' : 'text-[32px] leading-9'))
</script>

<template>
  <section class="card" role="group" :aria-label="label" data-testid="scoreboard">
    <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-end gap-3">
      <div class="min-w-0">
        <p class="truncate text-[13px] leading-[18px] font-medium text-tg-hint" data-testid="score-me-name">{{ t('battle.you') }}</p>
        <p class="font-bold tabular-nums" :class="digits" data-testid="score-me">{{ me }}</p>
      </div>
      <p class="pb-0.5 font-bold text-tg-hint" :class="digits" aria-hidden="true">:</p>
      <div class="min-w-0 text-right">
        <p class="truncate text-[13px] leading-[18px] font-medium text-tg-hint" data-testid="score-opponent-name">{{ opponentName }}</p>
        <p class="font-bold tabular-nums" :class="digits" data-testid="score-opponent">{{ opponent }}</p>
      </div>
    </div>
    <div v-if="$slots.default" class="mt-3">
      <slot />
    </div>
  </section>
</template>
