<script setup lang="ts">
import { computed } from 'vue'
import PlayerName from '@/components/PlayerName.vue'
import { useI18n } from '@/i18n'

/**
 * The score of a battle, big (docs/16 §11.2): "You 100 : 125 Name". The learner's side is on the left, the opponent's on
 * the right; the names are small above the numbers and cut off with an ellipsis when they are long. A flat card of the kit (surface, 1 px
 * hairline, radius lg); "You" in the accent of text, the figures in Unbounded with tabular digits.
 *
 * - `me` / `opponent` — the points of each (a sum of the rounds that are over: 0..500).
 * - `opponentName` — already localized; `opponentEmoji` — the opponent's emoji of Pro (docs/31 §5а) after it, none for null / absent.
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
    opponentEmoji?: string | null
    size?: 'md' | 'lg'
  }>(),
  { size: 'md', opponentEmoji: null },
)

const { t } = useI18n()

const label = computed(() => t('battle.score.aria', { me: props.me, opponent: props.opponent, name: props.opponentName }))
/** The figures in Unbounded 600 with tabular digits (Numeric of the kit, larger): a score that changes does not move its neighbours. */
const digits = computed(() => (props.size === 'lg' ? 'text-[40px] leading-[44px]' : 'text-[28px] leading-8'))
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
      <!-- "You" is the learner's mark (the accent of text, as "· вы" in the league). -->
      <p class="truncate t-label-s text-accent" data-testid="score-me-name">{{ t('battle.you') }}</p>
      <p class="font-display font-semibold tracking-[-0.02em] tabular-nums" :class="[digits, { 'short:text-[22px] short:leading-[26px]': $slots.default }]" data-testid="score-me">{{ me }}</p>
    </div>
    <p class="col-start-2 row-start-1 pb-0.5 font-display font-semibold text-tertiary" :class="[digits, { 'short:hidden': $slots.default }]" aria-hidden="true">:</p>
    <div class="col-start-3 row-start-1 min-w-0 text-right">
      <PlayerName :name="opponentName" :emoji="opponentEmoji" class="justify-end t-label-s text-muted" data-testid="score-opponent-name" />
      <p class="font-display font-semibold tracking-[-0.02em] tabular-nums" :class="[digits, { 'short:text-[22px] short:leading-[26px]': $slots.default }]" data-testid="score-opponent">{{ opponent }}</p>
    </div>
    <div v-if="$slots.default" class="col-span-3 row-start-2 short:col-span-1 short:col-start-2 short:row-start-1 short:min-w-[5.5rem]" data-testid="scoreboard-timer">
      <slot />
    </div>
  </section>
</template>
