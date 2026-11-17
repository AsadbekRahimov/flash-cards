<script setup lang="ts">
import { computed } from 'vue'
import { formatDate } from '@/composables/format'
import { useI18n } from '@/i18n'
import type { ReadinessHistoryItem } from '@/api/types'

/**
 * Bars of the test of the week by weeks (docs/04 A.20): plain CSS, no library. The last `weeks` weeks up to and including
 * `currentWeek` (a Monday, `Y-m-d`); a week without a scored result is an empty bar. Empty history: "No results yet".
 *
 * `data-testid`: `quiz-chart`, `quiz-chart-empty`, `quiz-bar-<week_start>` (`data-percent` on the bar).
 */
const props = defineProps<{
  history: ReadinessHistoryItem[]
  weeks: number
  /** Monday of the current week. */
  currentWeek: string
}>()

const { t, locale } = useI18n()

/** `Y-m-d` of Monday `back` weeks before `monday`; plain calendar arithmetic in UTC so a time zone cannot move the day. */
function weekBefore(monday: string, back: number): string {
  const [y, m, d] = monday.split('-').map(Number)
  return new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, (d ?? 1) - back * 7)).toISOString().slice(0, 10)
}

const bars = computed(() => {
  const byWeek = new Map(props.history.map((item) => [item.week_start, item]))
  const count = Math.max(1, props.weeks)
  return Array.from({ length: count }, (_, i) => {
    const week = weekBefore(props.currentWeek, count - 1 - i)
    const item = byWeek.get(week) ?? null
    const label = formatDate(week, 'dayMonthShort', locale.value)
    return {
      week,
      label,
      percent: item?.percent ?? null,
      aria: item
        ? t('readiness.quiz.bar', { week: label, correct: item.correct, total: item.total })
        : t('readiness.quiz.bar_empty', { week: label }),
    }
  })
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
      <span class="text-[11px] leading-3 text-tg-subtitle tabular-nums" aria-hidden="true">{{ bar.percent === null ? '' : bar.percent }}</span>
      <span class="flex w-full flex-1 items-end rounded-t bg-track" aria-hidden="true">
        <span
          class="w-full rounded-t bg-accent"
          :style="{ height: `${bar.percent ?? 0}%` }"
          :data-percent="bar.percent ?? ''"
          :data-testid="`quiz-bar-${bar.week}`"
        />
      </span>
      <span class="h-3 text-[10px] leading-3 text-tg-hint" aria-hidden="true">{{ (bars.length - 1 - index) % 3 === 0 ? bar.label : '' }}</span>
    </li>
  </ul>
</template>
