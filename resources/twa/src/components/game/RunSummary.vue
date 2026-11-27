<script setup lang="ts">
/**
 * The summary of a run (docs/05 §5b.3, docs/18 §12.9), drawn as the end of a lesson (docs/design Finish). `completed`: the score, the stars
 * (a symbol AND a number for a screen reader) and the coins in one card of numbers, then in rows - ONLY what the server gave and is not
 * zero - the league points, "the streak held", the new stars and the boss. `failed`
 * ("The flasks are over"): the same without the rewards, then the mistakes. Nothing is made up: a value the server does not send yet
 * (G2.5a coins, G2.10 streak, G2.4 boss) leaves no row.
 *
 * Buttons (`StickyActions`): "Repeat in cards" (a lost run with mistakes or words pulled to repeat: the ordinary daily review), "Again" and
 * "To the map". Events: `review`, `again`, `map`.
 */
import { computed } from 'vue'
import CityCard from '@/components/game/CityCard.vue'
import StickyActions from '@/components/StickyActions.vue'
import NumberText from '@/components/game/NumberText.vue'
import { NUMBER_MARK } from '@/components/game/numberMark'
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import StampMark from '@/components/ui/StampMark.vue'
import { useI18n } from '@/i18n'
import type { GameMistake, GameSummary } from '@/api/gameRunTypes'

const props = withDefaults(defineProps<{
  summary: GameSummary
  /** `failed`: the flasks ran out. Anything else is a finished run. */
  failed: boolean
  busy?: boolean
  /** The caravan of the day is one a day: `false` leaves "Again" out. */
  canAgain?: boolean
  /** The kind of the run: a fight with the boss of a CLASS has its own words (one fight per member). */
  kind?: string
  /** The class caravan of a class-boss run: "To the class" leads there instead of "To the map". */
  classCaravanId?: number | null
  /** The share of the city card is on its way: its button is busy (`useShare` of the screen). */
  sharing?: boolean
}>(), { canAgain: true, kind: 'stop', classCaravanId: null, sharing: false })

defineEmits<{ review: []; again: []; map: []; share: []; class: [] }>()

const { t, tn } = useI18n()

const again = computed((): boolean => props.canAgain)
const stars = computed((): number => Math.min(3, Math.max(0, props.summary.stars)))
/** A fight with a boss: won or not (a lost one is not `failed` - a boss has no flasks), and the card of the first win. */
const boss = computed(() => props.summary.boss)
const bossLost = computed((): boolean => boss.value !== null && !boss.value.won)
const card = computed(() => (boss.value?.won === true && boss.value.first && boss.value.share ? boss.value.share : null))
const classFight = computed((): boolean => props.kind === 'class_boss')
const toClass = computed((): boolean => classFight.value && props.classCaravanId !== null)
const title = computed((): string => {
  if (boss.value !== null && classFight.value) return boss.value.won ? t('gameRun.summary.title.class_boss_won') : t('gameRun.summary.title.class_boss_lost')
  if (boss.value !== null) return boss.value.won ? t('gameRun.summary.title.boss_won') : t('gameRun.summary.title.boss_lost')
  return props.failed ? t('gameRun.summary.title.failed') : t('gameRun.summary.title.completed')
})
const canReview = computed((): boolean => (props.failed || bossLost.value) && (props.summary.mistakes.length > 0 || props.summary.srs_pulled > 0))

/**
 * The numbers of the run in one card (docs/design Finish: up to three, split by thin lines): the score, the stars (the accuracy in a fight
 * with a boss, which is what decides it) and the coins - only when the server gave any.
 */
const coins = computed((): number => (props.failed ? 0 : Math.max(0, props.summary.coins)))
const numbers = computed((): number => 2 + (coins.value > 0 ? 1 : 0))

/** The rows of what else the run earned (under the numbers): only the values that exist and are not zero. */
const rewards = computed((): { key: string; label: string; value: string }[] => {
  const s = props.summary
  const rows: { key: string; label: string; value: string }[] = []
  if (!props.failed) {
    if (s.league_points > 0) rows.push({ key: 'league', label: t('gameRun.summary.league'), value: t('gameRun.summary.plus', { count: s.league_points }) })
    if ((s.new_stars ?? 0) > 0) rows.push({ key: 'new_stars', label: t('gameRun.summary.new_stars'), value: t('gameRun.summary.plus', { count: s.new_stars ?? 0 }) })
  }
  return rows
})

/** The chip of a line of the list: a right answer after a hint is neutral (it is here for the hint), not red. */
function verdictChip(verdict: GameMistake['verdict']): string {
  switch (verdict) {
    case 'typo':
      return 'chip-warning'
    case 'skipped':
      return 'chip-accent'
    case 'correct':
      return 'chip-neutral'
    default:
      return 'chip-danger'
  }
}

const streakHeld = computed((): boolean => !props.failed && props.summary.streak_held === true)

/** A run that is won (finished, or the boss beaten): the mascot and the stamp of the score. A lost one has neither. */
const celebrate = computed((): boolean => !props.failed && (boss.value === null || boss.value.won))
</script>

<template>
  <div class="flex flex-1 flex-col" :data-outcome="failed ? 'failed' : 'completed'" data-testid="run-summary">
    <!--
      The end of a run as the end of a lesson of the kit (06-urok-proyden), on the central axis: a run that is won has the mascot glad of it
      (with the cup and the stamp of the score after a boss) - the one moment of the screen; a lost one is told quietly, without them. Then
      the numbers in one card, the rest in rows.
    -->
    <div class="flex flex-1 flex-col gap-3.5 px-5 pt-8 pb-4 short:pt-4">
      <div class="mb-2 flex flex-col items-center gap-2 text-center">
        <!-- A stop run that is won: the "celebrate" mascot of the kit's lesson end (its picture holds its own stamp); a boss beaten: the
             mascot with the cup and the stamp of the score. One stamp on the screen either way. -->
        <template v-if="celebrate && boss">
          <MascotArt pose="trophy" :height="120" class="short:hidden" />
          <StampMark :value="summary.score" :unit="tn('gameRun.summary.stamp_unit', summary.score)" class="my-1" />
        </template>
        <MascotArt v-else-if="celebrate" pose="celebrate" :height="176" class="short:h-28 short:w-auto" />
        <h2 class="t-display-l break-words" data-testid="summary-title">{{ title }}</h2>
        <p v-if="boss && !boss.won" class="t-body-m text-muted" data-testid="boss-lost-hint">
          {{ t(classFight ? 'gameRun.summary.class_boss_lost_hint' : 'gameRun.summary.boss_lost_hint', { accuracy: Math.round(boss.accuracy) }) }}
        </p>
      </div>

      <!-- The stat card of the kit: equal columns (`flex: 1`) split by 1 px lines, the figure (Numeric/L) over its caption, centred. -->
      <dl class="grid rounded-card border border-hairline bg-surface text-center" :style="{ gridTemplateColumns: `repeat(${numbers}, minmax(0, 1fr))` }" data-testid="numbers">
        <div class="flex min-w-0 flex-col-reverse items-center gap-1 px-3 py-4">
          <dt class="t-label-s text-muted">{{ t('gameRun.summary.score') }}</dt>
          <dd class="t-numeric-l" data-testid="summary-score">{{ summary.score }}</dd>
        </div>
        <div class="flex min-w-0 flex-col-reverse items-center gap-1 border-l border-divider px-3 py-4">
          <dt class="t-label-s text-muted">{{ t('gameRun.summary.stars_label') }}</dt>
          <dd class="flex h-[26px] items-center gap-0.5" role="img" :aria-label="t('gameRun.summary.stars', { stars })" data-testid="stars">
            <AppIcon v-for="n in 3" :key="n" name="star" :solid="n <= stars" :size="20" :class="n <= stars ? 'text-body' : 'text-tertiary'" :data-full="n <= stars ? 'true' : 'false'" data-testid="star" />
          </dd>
        </div>
        <div v-if="coins > 0" class="flex min-w-0 flex-col-reverse items-center gap-1 border-l border-divider px-3 py-4" data-testid="reward-coins">
          <dt class="t-label-s text-muted">{{ t('gameRun.summary.coins') }}</dt>
          <dd class="t-numeric-l">{{ t('gameRun.summary.plus', { count: coins }) }}</dd>
        </div>
      </dl>

      <CityCard v-if="card" :title="card.title" :busy="sharing" @share="$emit('share')" />

      <section v-if="rewards.length > 0 || streakHeld || summary.boss" class="list" data-testid="rewards">
        <p v-for="row in rewards" :key="row.key" class="list-row justify-between" :data-testid="`reward-${row.key}`">
          <span class="font-semibold">{{ row.label }}</span>
          <span class="font-display font-semibold tabular-nums">{{ row.value }}</span>
        </p>
        <p v-if="streakHeld" class="list-row font-semibold" data-testid="streak-held">{{ t('gameRun.summary.streak_held') }}</p>
        <p v-if="summary.boss" class="list-row justify-between" data-testid="boss">
          <span class="font-semibold">{{ summary.boss.won ? t(classFight ? 'gameRun.summary.class_boss_won' : 'gameRun.summary.boss_won') : t(classFight ? 'gameRun.summary.class_boss_lost' : 'gameRun.summary.boss_lost') }}</span>
          <NumberText class="shrink-0 text-[13px] leading-[18px] text-muted" :text="t('gameRun.summary.boss_accuracy', { accuracy: NUMBER_MARK })" :value="Math.round(summary.boss.accuracy)" />
        </p>
      </section>

      <section v-if="summary.mistakes.length > 0" class="flex flex-col" data-testid="mistakes">
        <h3 class="section-label !mt-1">{{ t('gameRun.summary.mistakes') }} · <span class="tabular-nums">{{ summary.mistakes.length }}</span></h3>
        <ul class="list" role="list">
          <li v-for="(mistake, index) in summary.mistakes" :key="`${mistake.element_id}-${index}`" class="list-row !items-start" :data-testid="`mistake-${index}`">
            <div class="min-w-0 flex-1">
              <p class="font-semibold break-words" data-testid="mistake-label">{{ mistake.label }}</p>
              <p class="text-[13px] leading-[18px] break-words text-muted" data-testid="mistake-yours">
                {{ mistake.your_answer ? t('gameRun.summary.your_answer', { answer: mistake.your_answer }) : t('gameRun.summary.no_answer') }}
              </p>
            </div>
            <span class="chip shrink-0" :class="verdictChip(mistake.verdict)" data-testid="mistake-verdict">
              {{ t(`gameRun.summary.verdict.${mistake.verdict}`) }}
            </span>
          </li>
        </ul>
        <p v-if="failed" class="mt-2 px-[18px] text-[13px] leading-[18px] text-muted">{{ t('gameRun.summary.failed_hint') }}</p>
      </section>
    </div>

    <!-- One main button (ink); the next one is Secondary, a third Ghost (kit Muhrla, Button). -->
    <StickyActions>
      <button v-if="canReview" type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy" data-testid="review-btn" @click="$emit('review')">
        {{ t('gameRun.summary.review') }}
      </button>
      <button v-if="again" type="button" class="btn btn-block" :class="canReview ? 'btn-secondary' : 'btn-primary btn-lg'" data-testid="again-btn" @click="$emit('again')">
        {{ t('gameRun.summary.again') }}
      </button>
      <button
        v-if="toClass"
        type="button"
        class="btn btn-block"
        :class="canReview ? 'btn-secondary' : 'btn-primary btn-lg'"
        data-testid="class-btn"
        @click="$emit('class')"
      >
        {{ t('gameRun.summary.to_class') }}
      </button>
      <button
        v-else
        type="button"
        class="btn btn-block"
        :class="canReview && again ? 'btn-ghost' : canReview || again ? 'btn-secondary' : 'btn-primary btn-lg'"
        data-testid="map-btn"
        @click="$emit('map')"
      >
        {{ t('gameRun.to_map') }}
      </button>
    </StickyActions>
  </div>
</template>
