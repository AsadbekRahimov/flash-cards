<script setup lang="ts">
/**
 * The summary of a run (docs/05 §5b.3, docs/18 §12.9). `completed`: the stars (a symbol AND a number for a screen reader), the score and -
 * ONLY what the server gave and is not zero - the coins, the league points, "the streak held", the new stars and the boss. `failed`
 * ("The flasks are over"): the same without the rewards, then the mistakes. Nothing is made up: a value the server does not send yet
 * (G2.5a coins, G2.10 streak, G2.4 boss) leaves no row.
 *
 * Buttons (`StickyActions`): "Repeat in cards" (a lost run with mistakes or words pulled to repeat: the ordinary daily review), "Again" and
 * "To the map". Events: `review`, `again`, `map`.
 */
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useI18n } from '@/i18n'
import type { GameSummary } from '@/api/gameRunTypes'

const props = defineProps<{
  summary: GameSummary
  /** `failed`: the flasks ran out. Anything else is a finished run. */
  failed: boolean
  busy?: boolean
}>()

defineEmits<{ review: []; again: []; map: [] }>()

const { t } = useI18n()

const stars = computed((): number => Math.min(3, Math.max(0, props.summary.stars)))
const canReview = computed((): boolean => props.failed && (props.summary.mistakes.length > 0 || props.summary.srs_pulled > 0))

/** The rows of what the run earned: only the values that exist and are not zero. */
const rewards = computed((): { key: string; label: string; value: string }[] => {
  const s = props.summary
  const rows: { key: string; label: string; value: string }[] = []
  if (!props.failed) {
    if (s.coins > 0) rows.push({ key: 'coins', label: t('gameRun.summary.coins'), value: t('gameRun.summary.plus', { count: s.coins }) })
    if (s.league_points > 0) rows.push({ key: 'league', label: t('gameRun.summary.league'), value: t('gameRun.summary.plus', { count: s.league_points }) })
    if ((s.new_stars ?? 0) > 0) rows.push({ key: 'new_stars', label: t('gameRun.summary.new_stars'), value: t('gameRun.summary.plus', { count: s.new_stars ?? 0 }) })
  }
  return rows
})

const streakHeld = computed((): boolean => !props.failed && props.summary.streak_held === true)
</script>

<template>
  <div class="flex flex-1 flex-col" :data-outcome="failed ? 'failed' : 'completed'" data-testid="run-summary">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-6 pb-4">
      <div class="flex flex-col items-center gap-3 text-center">
        <h2 class="text-[22px] leading-7 font-bold" data-testid="summary-title">{{ failed ? t('gameRun.summary.title.failed') : t('gameRun.summary.title.completed') }}</h2>

        <div class="flex items-center gap-1" role="img" :aria-label="t('gameRun.summary.stars', { stars })" data-testid="stars">
          <AppIcon v-for="n in 3" :key="n" name="star" :solid="n <= stars" :size="36" :class="n <= stars ? 'text-warning' : 'text-tg-hint'" :data-full="n <= stars ? 'true' : 'false'" data-testid="star" />
        </div>

        <p class="text-[15px] leading-5 text-tg-hint">
          {{ t('gameRun.summary.score') }}: <span class="font-semibold text-tg-text tabular-nums" data-testid="summary-score">{{ summary.score }}</span>
        </p>
      </div>

      <section v-if="rewards.length > 0 || streakHeld || summary.boss" class="card flex flex-col gap-2" data-testid="rewards">
        <p v-for="row in rewards" :key="row.key" class="flex items-center justify-between text-[15px] leading-5" :data-testid="`reward-${row.key}`">
          <span class="text-tg-hint">{{ row.label }}</span>
          <span class="font-semibold tabular-nums">{{ row.value }}</span>
        </p>
        <p v-if="streakHeld" class="flex items-center gap-2 text-[15px] leading-5 font-medium" data-testid="streak-held">
          <AppIcon name="fire" solid :size="20" class="text-warning" />{{ t('gameRun.summary.streak_held') }}
        </p>
        <p v-if="summary.boss" class="flex items-center justify-between text-[15px] leading-5" data-testid="boss">
          <span class="font-medium">{{ summary.boss.won ? t('gameRun.summary.boss_won') : t('gameRun.summary.boss_lost') }}</span>
          <span class="tabular-nums text-tg-hint">{{ t('gameRun.summary.boss_accuracy', { accuracy: Math.round(summary.boss.accuracy) }) }}</span>
        </p>
      </section>

      <section v-if="summary.mistakes.length > 0" class="flex flex-col gap-2" data-testid="mistakes">
        <h3 class="section-label !mt-0 !px-0">{{ t('gameRun.summary.mistakes') }} · {{ summary.mistakes.length }}</h3>
        <ul class="list" role="list">
          <li v-for="(mistake, index) in summary.mistakes" :key="`${mistake.element_id}-${index}`" class="list-row !items-start" :data-testid="`mistake-${index}`">
            <div class="min-w-0 flex-1">
              <p class="font-medium break-words" data-testid="mistake-label">{{ mistake.label }}</p>
              <p class="text-[13px] leading-[18px] text-tg-hint break-words" data-testid="mistake-yours">
                {{ mistake.your_answer ? t('gameRun.summary.your_answer', { answer: mistake.your_answer }) : t('gameRun.summary.no_answer') }}
              </p>
            </div>
            <span class="chip shrink-0" :class="mistake.verdict === 'typo' ? 'chip-warning' : mistake.verdict === 'skipped' ? 'chip-accent' : 'chip-danger'" data-testid="mistake-verdict">
              {{ t(`gameRun.summary.verdict.${mistake.verdict}`) }}
            </span>
          </li>
        </ul>
        <p v-if="failed" class="px-1 text-[13px] leading-[18px] text-tg-hint">{{ t('gameRun.summary.failed_hint') }}</p>
      </section>
    </div>

    <StickyActions>
      <button v-if="canReview" type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy" data-testid="review-btn" @click="$emit('review')">
        {{ t('gameRun.summary.review') }}
      </button>
      <button type="button" class="btn btn-block" :class="canReview ? 'btn-tinted' : 'btn-primary btn-lg'" data-testid="again-btn" @click="$emit('again')">
        {{ t('gameRun.summary.again') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="map-btn" @click="$emit('map')">{{ t('gameRun.to_map') }}</button>
    </StickyActions>
  </div>
</template>
