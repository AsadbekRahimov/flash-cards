<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/i18n'
import type { CupFields, CupRules } from '@/api/types'

/**
 * The rules of a cup in short (docs/33 §5.2, §5.5; owner's К4): one round a day of N words and M minutes, one attempt; 2 points for a
 * clean right answer, a typo is 0; the best `counted_days` days of `days` count; a day runs from midnight to midnight of the cup's zone;
 * the summary of the day in the chat. Every number is the server's (`rules`, the fields of the cup). A list of short lines.
 */
const props = defineProps<{
  cup: CupFields
  rules: CupRules
}>()

const { t, tn } = useI18n()

/** "A day: 00:00 to 24:00 by Tashkent time" - Tashkent by its name, another zone by its id. */
const dayLine = computed((): string =>
  props.cup.timezone === 'Asia/Tashkent' ? t('cup.rules.day_tashkent') : t('cup.rules.day_zone', { zone: props.cup.timezone }),
)
</script>

<template>
  <ul class="t-body-m flex flex-col gap-2 text-muted" data-testid="cup-rules">
    <li data-testid="rule-round">{{ t('cup.rules.round', { tasks: cup.round_tasks, minutes: cup.round_minutes }) }}</li>
    <li data-testid="rule-points">{{ t('cup.rules.points', { points: tn('cup.points', rules.points_per_answer), typo: tn('cup.points', rules.typo_points) }) }}</li>
    <li data-testid="rule-best">{{ tn('cup.rules.best', rules.counted_days, { days: rules.days }) }}</li>
    <li data-testid="rule-max">{{ t('cup.rules.max', { day: tn('cup.points', rules.max_day_points), total: tn('cup.points', rules.max_total) }) }}</li>
    <li data-testid="rule-day">{{ dayLine }}</li>
    <li v-if="cup.summaries_enabled" data-testid="rule-summary">{{ t('cup.rules.summary', { time: cup.summary_time }) }}</li>
  </ul>
</template>
