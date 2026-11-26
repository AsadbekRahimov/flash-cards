<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useI18n } from '@/i18n'
import type { DayPlanStep, DayPlanStepKey } from '@/composables/dayPlan'
import type { StringKey } from '@/i18n/ru'

/**
 * The card "Plan for today · step N of M" (docs/design/2026-11-25-redesign Main / MainNext): the steps lesson -> training -> game in a row,
 * each with its mark - a green tick when done, a filled number for the current one, an outlined number for one ahead. Only the steps that
 * can come today are passed (`dayPlanSteps`, composables/dayPlan.ts), so a switched-off review or a closed game leaves no empty place.
 *
 * - `steps` — the steps in order, with their state.
 * - `notes` — the second line of a step ("5 new words"); a step without one shows its fixed note (the training, the game) or none.
 * - `done` — the chain of today is complete: the counter says so instead of "step N of M".
 */
const props = defineProps<{
  steps: DayPlanStep[]
  notes?: Partial<Record<DayPlanStepKey, string>>
  done: boolean
}>()

const { t } = useI18n()

const TITLES: Record<DayPlanStepKey, StringKey> = {
  lesson: 'today.steps.lesson',
  training: 'today.steps.training',
  game: 'today.steps.game',
}
const FIXED_NOTES: Partial<Record<DayPlanStepKey, StringKey>> = {
  training: 'today.steps.training_note',
  game: 'today.steps.game_note',
}

const rows = computed(() =>
  props.steps.map((step, index) => {
    const fixed = FIXED_NOTES[step.key]
    return {
      ...step,
      number: index + 1,
      title: t(TITLES[step.key]),
      note: props.notes?.[step.key] ?? (fixed ? t(fixed) : ''),
      status: t(`today.steps.state.${step.state}`),
    }
  }),
)

const counter = computed(() => {
  if (props.done) return t('today.steps.all_done')
  const current = props.steps.findIndex((step) => step.state === 'current')
  return current < 0 ? '' : t('today.steps.counter', { step: current + 1, total: props.steps.length })
})
</script>

<template>
  <section class="card flex flex-col gap-2.5 px-4 pt-3 pb-3.5" data-testid="day-plan">
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="text-[15px] leading-5 font-bold">{{ t('today.steps.title') }}</h2>
      <span v-if="counter" class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="plan-counter">{{ counter }}</span>
    </div>
    <ol class="grid gap-1.5" :class="steps.length === 3 ? 'grid-cols-3' : steps.length === 2 ? 'grid-cols-2' : 'grid-cols-1'">
      <li
        v-for="row in rows"
        :key="row.key"
        class="flex min-w-0 items-center gap-2"
        :data-key="row.key"
        :data-state="row.state"
        :aria-current="row.state === 'current' ? 'step' : undefined"
        data-testid="plan-step"
      >
        <span
          v-if="row.state === 'done'"
          class="flex size-[26px] shrink-0 items-center justify-center rounded-full bg-success text-on-primary"
          aria-hidden="true"
        >
          <AppIcon name="check" :size="16" />
        </span>
        <span
          v-else-if="row.state === 'current'"
          class="flex size-[26px] shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-on-primary"
          aria-hidden="true"
        >{{ row.number }}</span>
        <span
          v-else
          class="flex size-[26px] shrink-0 items-center justify-center rounded-full border-[1.5px] border-track text-sm font-semibold text-muted"
          aria-hidden="true"
        >{{ row.number }}</span>
        <span class="flex min-w-0 flex-col">
          <span
            class="truncate text-sm leading-[18px]"
            :class="row.state === 'current' ? 'font-bold text-body' : 'font-medium text-muted'"
            data-testid="plan-step-title"
          >{{ row.title }}</span>
          <span v-if="row.note" class="truncate text-xs leading-4 text-muted" data-testid="plan-step-note">{{ row.note }}</span>
          <span class="sr-only">{{ row.status }}</span>
        </span>
      </li>
    </ol>
  </section>
</template>
