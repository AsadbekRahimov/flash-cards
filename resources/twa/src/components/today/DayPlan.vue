<script setup lang="ts">
import { computed } from 'vue'
import PlanStep from '@/components/ui/PlanStep.vue'
import { useI18n } from '@/i18n'
import type { DayPlanStep, DayPlanStepKey } from '@/composables/dayPlan'
import type { StringKey } from '@/i18n/ru'

/**
 * "Plan for today · step N of M" (kit Muhrla, screens 01 / 02): the steps lesson -> training -> game one under another, each a `PlanStep` -
 * a green circle with a check when done, the muhr circle with the number for the current one, an outlined number for one ahead - with its
 * meta under the title. No card: the block lies on the page, as in the kit. Only the steps that can come today are passed
 * (`dayPlanSteps`, composables/dayPlan.ts), so a switched-off review or a closed game leaves no empty place.
 *
 * - `steps` — the steps in order, with their state.
 * - `notes` — the meta of a step ("5 new words"); a step without one shows its fixed note (the training, the game) or none.
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
  <section class="flex flex-col gap-2" data-testid="day-plan">
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="t-display-m">{{ t('today.steps.title') }}</h2>
      <span v-if="counter" class="t-label-s shrink-0 text-muted" data-testid="plan-counter">{{ counter }}</span>
    </div>
    <ol class="flex flex-col">
      <li
        v-for="row in rows"
        :key="row.key"
        :data-key="row.key"
        :data-state="row.state"
        :aria-current="row.state === 'current' ? 'step' : undefined"
        data-testid="plan-step"
      >
        <PlanStep :state="row.state" :number="row.number" :title="row.title" :meta="row.note" data-testid="plan-step-body" />
        <span class="sr-only">{{ row.status }}</span>
      </li>
    </ol>
  </section>
</template>
