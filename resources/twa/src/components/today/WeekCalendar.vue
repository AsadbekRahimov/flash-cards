<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useStreakI18n, type StreakStringKey } from '@/i18n/streak'
import type { StreakWeekDay } from '@/api/types'

/**
 * The week of the streak of F4 (kit 03-new-features/F4-seriya-spasena.png, docs/40 §2.5): seven circles on a card, Monday first, the
 * short name of the day under each. What a day shows:
 * - `done` - held by the goal or a run: a muhr circle with a check;
 * - `freeze` - held by a freeze: a snowflake on the sunken paper;
 * - `today` - not held yet: a ring in `border-strong` with the number of the day, which pulses once (ANIMATIONS.md §8; not under
 *   `prefers-reduced-motion`); today held shows `done` / `freeze`, its name stays bold;
 * - `future` - a dashed circle;
 * - `missed` - a past day nothing held (only before a loss): an empty circle. The kit has no such day.
 * Each circle is named for a screen reader ("Пт: заморозка").
 *
 * - `week` - `streak.week` of `/today` (seven days, `Y-m-d`).
 */
const props = defineProps<{ week: StreakWeekDay[] }>()

const { t } = useStreakI18n()

type DayState = 'done' | 'freeze' | 'today' | 'future' | 'missed'

/** 1 = Monday … 7 = Sunday of a `Y-m-d` day (calendar arithmetic, no time zone). */
function isoWeekday(date: string): number {
  const [year, month, day] = date.split('-').map(Number)
  const weekday = new Date(Date.UTC(year ?? 1970, (month ?? 1) - 1, day ?? 1)).getUTCDay()
  return weekday === 0 ? 7 : weekday
}

const days = computed(() => {
  const todayIndex = props.week.findIndex((day) => day.is_today)
  return props.week.map((day, index) => {
    let state: DayState
    if (day.held_by === 'freeze') state = 'freeze'
    else if (day.held_by !== null) state = 'done'
    else if (day.is_today) state = 'today'
    else if (todayIndex !== -1 && index > todayIndex) state = 'future'
    else state = 'missed'
    const name = t(`streak.week.day.${isoWeekday(day.date)}` as StreakStringKey)
    return {
      date: day.date,
      state,
      isToday: day.is_today,
      number: Number(day.date.slice(8, 10)),
      name,
      label: t(`streak.week.state.${state}` as StreakStringKey, { day: name }),
    }
  })
})
</script>

<template>
  <ul class="card flex items-start justify-between gap-1 px-3 py-4" :aria-label="t('streak.week.label')" data-testid="week-calendar">
    <li
      v-for="day in days"
      :key="day.date"
      class="flex min-w-0 flex-1 flex-col items-center gap-2"
      :aria-label="day.label"
      :data-state="day.state"
      :data-today="day.isToday || undefined"
      data-testid="week-day"
    >
      <span
        class="day-mark flex size-[34px] shrink-0 items-center justify-center rounded-full"
        :class="{
          'bg-muhr text-on-muhr': day.state === 'done',
          'bg-sunken text-body': day.state === 'freeze',
          'day-mark--today border-2 border-strong text-body': day.state === 'today',
          'border-[1.5px] border-dashed border-divider': day.state === 'future',
          'border-[1.5px] border-hairline bg-track': day.state === 'missed',
        }"
        aria-hidden="true"
      >
        <AppIcon v-if="day.state === 'done'" name="check" :size="18" />
        <AppIcon v-else-if="day.state === 'freeze'" name="snowflake" :size="18" />
        <span v-else-if="day.state === 'today'" class="t-body-m-strong tabular-nums">{{ day.number }}</span>
      </span>
      <span class="t-label-s" :class="day.isToday ? 'font-semibold text-body' : 'text-tertiary'" aria-hidden="true">{{ day.name }}</span>
    </li>
  </ul>
</template>

<style scoped>
/* Today's ring pulses once when the screen appears (ANIMATIONS.md §8 "Seriya kalendari"), after the mascot and the stamp. */
.day-mark--today {
  animation: day-pulse 600ms var(--ease-out) 300ms both;
}
@keyframes day-pulse {
  0% {
    transform: scale(1);
  }
  45% {
    transform: scale(1.12);
  }
  100% {
    transform: scale(1);
  }
}
@media (prefers-reduced-motion: reduce) {
  .day-mark--today {
    animation: none;
  }
}
</style>
