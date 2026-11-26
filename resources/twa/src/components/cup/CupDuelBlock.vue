<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { duelBlockReason } from '@/composables/cupDuelInfo'
import { useI18n } from '@/i18n'
import type { CupBoardResponse, CupDuelAvailability } from '@/api/types'

/**
 * "Duels" on the cup screen (docs/33 §7.1, docs/04 A.23d `me.duel`): "Challenge to a duel" when the server says I may - or why not
 * today, or my open challenge that waits - what a rated duel is (and that it is played, if so), the challenges to me (`incoming`, from the
 * list when it has been read), and the way to the list. The screen draws the block only while `me.duel` comes (null - the duels are off).
 */
const props = defineProps<{
  cupId: number
  availability: CupDuelAvailability
  board: Pick<CupBoardResponse, 'cup' | 'me'>
  /** Challenges to me; null - the list has not been read (nothing is shown). */
  incoming: number | null
}>()

const { t, tn } = useI18n()

const reason = computed((): string | null => duelBlockReason(props.availability, props.board))
</script>

<template>
  <section class="card flex flex-col gap-3" data-testid="duel-block">
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="min-w-0 text-base leading-[22px] font-bold">{{ t('cup.duel.block_title') }}</h2>
      <span v-if="incoming !== null && incoming > 0" class="chip chip-accent shrink-0" data-testid="incoming-count">{{ tn('cup.duel.incoming_count', incoming) }}</span>
    </div>

    <template v-if="availability.open_duel_id !== null">
      <p class="text-[15px] leading-5" data-testid="duel-waiting">{{ t('cup.duel.block_waiting') }}</p>
      <RouterLink :to="{ name: 'cup-duel', params: { id: cupId, duelId: availability.open_duel_id } }" class="btn btn-tinted btn-block" data-testid="open-mine">
        {{ t('cup.duel.open_mine') }}
      </RouterLink>
    </template>
    <template v-else-if="availability.can_challenge">
      <p class="text-[13px] leading-[18px] text-muted" data-testid="duel-hint">
        {{ availability.rated_available ? t('cup.duel.block_rated') : t('cup.duel.block_rated_played') }}
      </p>
      <RouterLink :to="{ name: 'cup-duel-new', params: { id: cupId } }" class="btn btn-tinted btn-block" data-testid="challenge-btn">{{ t('cup.duel.challenge') }}</RouterLink>
    </template>
    <p v-else class="text-[15px] leading-5 text-muted" data-testid="duel-reason">{{ reason }}</p>

    <RouterLink :to="{ name: 'cup-duels', params: { id: cupId } }" class="btn btn-plain btn-block" data-testid="duels-link">{{ t('cup.duel.list_title') }}</RouterLink>
  </section>
</template>
