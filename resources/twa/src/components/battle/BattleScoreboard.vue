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
 * In a short window (`short:`, 320 x 568) the timer of a round does not take a row of its own: it stands where the colon was, between
 * the two scores (the timer is a bar with the time over it there), and the card is one row of numbers - about 50 px less, which the
 * task of the round needs more than a colon.
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
  <!-- Every cell has its own place, so that the timer can take the one of the colon in a short window. -->
  <section
    class="card grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-end gap-x-3 gap-y-3"
    :class="{ 'short:items-center short:gap-y-0 short:p-3': $slots.default }"
    role="group"
    :aria-label="label"
    data-testid="scoreboard"
  >
    <div class="col-start-1 row-start-1 min-w-0">
      <p class="truncate text-[13px] leading-[18px] font-medium text-tg-hint" data-testid="score-me-name">{{ t('battle.you') }}</p>
      <p class="font-bold tabular-nums" :class="[digits, { 'short:text-[28px] short:leading-8': $slots.default }]" data-testid="score-me">{{ me }}</p>
    </div>
    <p class="col-start-2 row-start-1 pb-0.5 font-bold text-tg-hint" :class="[digits, { 'short:hidden': $slots.default }]" aria-hidden="true">:</p>
    <div class="col-start-3 row-start-1 min-w-0 text-right">
      <p class="truncate text-[13px] leading-[18px] font-medium text-tg-hint" data-testid="score-opponent-name">{{ opponentName }}</p>
      <p class="font-bold tabular-nums" :class="[digits, { 'short:text-[28px] short:leading-8': $slots.default }]" data-testid="score-opponent">{{ opponent }}</p>
    </div>
    <div v-if="$slots.default" class="col-span-3 row-start-2 short:col-span-1 short:col-start-2 short:row-start-1 short:min-w-[5.5rem]" data-testid="scoreboard-timer">
      <slot />
    </div>
  </section>
</template>
