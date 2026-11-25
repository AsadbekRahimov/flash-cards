<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import { formatDate } from '@/composables/format'
import { useI18n } from '@/i18n'
import type { ScoreToday } from '@/api/types'

/**
 * "Today N of 100" of the word points v2 (docs/32 §8.2, docs/04 A.2a `score_today`), drawn as the card of the goal of the day: a star,
 * «40 / 100 баллов» with the table (Free / Pro) as a quiet pill, what is left on the right and a thin line. The cap, the points and what
 * is left are the server's (`cap`, `points`, `remaining`); the day is the rating day of the league (Tashkent), so at the cap the card says
 * honestly that no more points come today while the words are still learned, and when the next rating day begins in the learner's
 * time (`day_ends_at` in `timezone`, the zone of `/today`; the phone's without it). `month` (only when the monthly count differs from the
 * weekly one) is one more muted line.
 *
 * A day on the old rules has no cap (`cap: null`): then the points alone, no line.
 */
const props = defineProps<{ score: ScoreToday; timezone?: string }>()

const { t, tn, locale } = useI18n()

const cap = computed(() => props.score.cap)
const capped = computed(() => cap.value !== null && (props.score.remaining ?? Math.max(0, cap.value - props.score.points)) <= 0)
const percent = computed(() => (cap.value !== null && cap.value > 0 ? (props.score.points / cap.value) * 100 : 0))

const countText = computed(() =>
  cap.value === null ? tn('league.score', props.score.points) : tn('score.today.count', cap.value, { points: props.score.points }),
)

const aside = computed(() => {
  if (cap.value === null) return ''
  if (capped.value) return t('score.today.max')
  const left = props.score.remaining ?? Math.max(0, cap.value - props.score.points)
  return tn('score.today.more', left)
})

const category = computed(() => t(props.score.category === 'pro' ? 'score.category.pro' : 'score.category.free'))

/** When the next rating day begins, in the learner's own time ("00:00" in Tashkent, "22:00" further west). */
const dayEnds = computed(() => {
  const when = formatDate(props.score.day_ends_at, 'time', locale.value, props.timezone)
  return when === '' ? '' : t('score.today.day_ends', { time: when })
})

const monthLine = computed(() => {
  const month = props.score.month
  return month ? t('score.today.month', { points: tn('league.score', month.points) }) : ''
})
</script>

<template>
  <section class="card flex flex-col gap-3 px-4 py-3.5" data-testid="score-today">
    <div class="flex min-h-11 items-center gap-3">
      <AppIcon name="star" :size="28" class="shrink-0 text-accent" />
      <span class="h-[34px] w-px shrink-0 bg-hairline" aria-hidden="true" />
      <span class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="flex min-w-0 items-center gap-2">
          <span class="truncate text-[17px] leading-[22px] font-bold">{{ t('score.today.title') }}</span>
          <span class="shrink-0 rounded-full bg-accent-soft px-2 text-xs leading-5 font-bold text-accent" data-testid="score-today-category">{{ category }}</span>
        </span>
        <span class="text-sm leading-5 text-muted tabular-nums" data-testid="score-today-count">{{ countText }}</span>
      </span>
      <span
        v-if="aside"
        class="shrink-0 text-right text-[13px] leading-[18px]"
        :class="capped ? 'font-semibold text-success-ink' : 'text-muted'"
        data-testid="score-today-aside"
      >{{ aside }}</span>
    </div>
    <ProgressLine v-if="cap !== null" :value="percent" :tone="capped ? 'success' : 'accent'" data-testid="score-today-bar" />
    <div v-if="capped || monthLine" class="flex flex-col gap-0.5 text-[13px] leading-[18px] text-muted">
      <p v-if="capped" data-testid="score-today-capped">{{ t('score.today.capped') }}<template v-if="dayEnds">. {{ dayEnds }}</template></p>
      <p v-if="monthLine" data-testid="score-today-month">{{ monthLine }}</p>
    </div>
  </section>
</template>
