<script setup lang="ts">
import { computed } from 'vue'
import { formatDate } from '@/composables/format'
import { useI18n } from '@/i18n'
import type { ReadinessHistoryItem } from '@/api/types'

/**
 * Bars of the test of the week by weeks (docs/04 A.20): plain CSS, no library. The last `weeks` weeks up to and including
 * `currentWeek` (a Monday, `Y-m-d`). A week with a result is an accent bar of its percent (a visible minimum for 0%) with the
 * value above it; a week without one is only a thin dashed mark on the baseline, no fill. With fewer than three weeks of results
 * the empty weeks to the left of the first result are cut (but never below `MIN_COLUMNS` columns). Empty history: "No results yet".
 *
 * `data-testid`: `quiz-chart`, `quiz-chart-empty`, `quiz-bar-<week_start>` (a result; `data-percent` on the bar),
 * `quiz-gap-<week_start>` (no result).
 */
const props = defineProps<{
  history: ReadinessHistoryItem[]
  weeks: number
  /** Monday of the current week. */
  currentWeek: string
}>()

const MIN_COLUMNS = 4
const SPARSE_BELOW = 3
/** Height of the bar of a 0% result, in percent of the track, so that it does not vanish. */
const MIN_BAR = 4

const { t, locale } = useI18n()

/** `Y-m-d` of Monday `back` weeks before `monday`; plain calendar arithmetic in UTC so a time zone cannot move the day. */
function weekBefore(monday: string, back: number): string {
  const [y, m, d] = monday.split('-').map(Number)
  return new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, (d ?? 1) - back * 7)).toISOString().slice(0, 10)
}

const bars = computed(() => {
  const byWeek = new Map(props.history.map((item) => [item.week_start, item]))
  const count = Math.max(1, props.weeks)
  const all = Array.from({ length: count }, (_, i) => {
    const week = weekBefore(props.currentWeek, count - 1 - i)
    const item = byWeek.get(week) ?? null
    const label = formatDate(week, 'dayMonthShort', locale.value)
    return {
      week,
      label,
      current: week === props.currentWeek,
      percent: item?.percent ?? null,
      aria: item
        ? t('readiness.quiz.bar', { week: label, correct: item.correct, total: item.total })
        : t('readiness.quiz.bar_empty', { week: label }),
    }
  })
  const filled = all.filter((bar) => bar.percent !== null).length
  if (filled >= SPARSE_BELOW) return all
  const first = all.findIndex((bar) => bar.percent !== null)
  return all.slice(Math.min(Math.max(first, 0), Math.max(0, count - MIN_COLUMNS)))
})
</script>

<template>
  <p v-if="history.length === 0" class="py-4 text-center text-[15px] leading-5 text-tg-subtitle" data-testid="quiz-chart-empty">
    {{ t('readiness.quiz.chart_empty') }}
  </p>
  <ul v-else class="flex h-28 items-end gap-1" data-testid="quiz-chart">
    <li
      v-for="(bar, index) in bars"
      :key="bar.week"
      class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1"
      role="img"
      :aria-label="bar.aria"
    >
      <span
        class="text-[11px] leading-3 tabular-nums"
        :class="bar.current ? 'font-semibold text-tg-text' : 'text-tg-subtitle'"
        aria-hidden="true"
      >{{ bar.percent === null ? '' : bar.percent }}</span>
      <span class="flex w-full flex-1 items-end" aria-hidden="true">
        <span
          v-if="bar.percent !== null"
          class="w-full rounded-t bg-accent"
          :class="bar.current ? '' : 'opacity-60'"
          :style="{ height: `${Math.max(bar.percent, MIN_BAR)}%` }"
          :data-percent="bar.percent"
          :data-current="bar.current ? 'true' : 'false'"
          :data-testid="`quiz-bar-${bar.week}`"
        />
        <span v-else class="w-full border-b border-dashed border-tg-hint/50" :data-testid="`quiz-gap-${bar.week}`" />
      </span>
      <span
        class="h-3 text-[10px] leading-3"
        :class="bar.current ? 'font-semibold text-tg-text' : 'text-tg-hint'"
        aria-hidden="true"
      >{{ bar.current || (bars.length - 1 - index) % 3 === 0 ? bar.label : '' }}</span>
    </li>
  </ul>
</template>
