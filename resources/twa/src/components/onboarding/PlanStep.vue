<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import OnboardingActions from './OnboardingActions.vue'
import StepIntro from './StepIntro.vue'
import { ONBOARDING_GOALS } from '@/api/onboardingTypes'
import { useOnboardingStore } from '@/stores/onboarding'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { IconName } from '@/components/ui/icons'
import type { StringKey } from '@/i18n/ru'

/**
 * O8 "The plan is ready" (kit 02-onboarding/O8): the mascot `celebrate` with the check stamp, "<name>, your plan is ready" - the name
 * the learner chose on O2 (`display_name`), never Telegram's `first_name` (docs/40 §5, п. 2) - and the summary of what was saved: the
 * course with its modules, the goal of the day, the reminder, the goals. "Start the first lesson" finishes the onboarding on the server
 * (`POST /onboarding/complete`: the course is set there) and leads to "Today".
 */
const emit = defineEmits<{ done: []; exit: []; missing: [step: string] }>()
const store = useOnboardingStore()
const { haptic } = useTelegram()
const { t, tn } = useI18n()

const values = computed(() => store.data?.values ?? null)
const name = computed((): string | null => values.value?.display_name ?? null)
const busy = computed(() => store.saving === 'complete')

interface Row {
  key: string
  icon: IconName
  label: string
  value: string
}

const rows = computed((): Row[] => {
  const v = values.value
  if (v === null) return []
  const course = v.course
  const courseValue = course === null
    ? (v.level ?? '—')
    : course.stages_count !== null && course.stages_count > 0
      ? tn('onboarding.plan.course_modules', course.stages_count, { title: course.title })
      : course.title
  const reminder = v.reminder
  const goals = (v.goals ?? []).filter((goal) => ONBOARDING_GOALS.includes(goal))
  const list: Row[] = [
    { key: 'course', icon: 'book-open', label: t('onboarding.plan.course'), value: courseValue },
    {
      key: 'goal',
      icon: 'target',
      label: t('onboarding.plan.goal'),
      value: v.daily_goal !== null ? tn('onboarding.plan.goal_value', v.daily_goal) : '—',
    },
    {
      key: 'reminder',
      icon: 'bell',
      label: t('onboarding.plan.reminder'),
      value: reminder !== null && reminder.enabled && reminder.time !== null
        ? t('onboarding.plan.reminder_at', { time: reminder.time })
        : t('onboarding.plan.reminder_off'),
    },
  ]
  if (goals.length > 0) {
    list.push({
      key: 'why',
      icon: 'award',
      label: t('onboarding.plan.why'),
      value: goals.map((goal) => t(`onboarding.goals.short.${goal}` as StringKey)).join(', '),
    })
  }
  return list
})

async function onStart(): Promise<void> {
  haptic('light')
  const outcome = await store.complete()
  if (outcome === 'ok') emit('done')
  else if (outcome === 'incomplete' && store.missingStep !== null) emit('missing', store.missingStep)
}
</script>

<template>
  <div class="flex flex-1 flex-col">
    <StepIntro
      :title="name !== null ? t('onboarding.plan.title', { name }) : t('onboarding.plan.title_plain')"
      :lead="t('onboarding.plan.lead')"
    >
      <div class="flex flex-col items-center gap-2">
        <MascotArt pose="celebrate" :height="96" loading="eager" />
        <span class="plan-stamp stamp--enter inline-flex size-11 items-center justify-center rounded-full text-muhr" aria-hidden="true" data-testid="plan-stamp">
          <AppIcon name="check" :size="24" />
        </span>
      </div>
    </StepIntro>
    <ul class="card mt-5 flex flex-col px-4 py-1" data-testid="plan-summary">
      <li
        v-for="row in rows"
        :key="row.key"
        class="plan-row flex min-h-14 items-center gap-3 py-2"
        :data-testid="`plan-${row.key}`"
      >
        <span class="icon-tile shrink-0"><AppIcon :name="row.icon" :size="20" /></span>
        <span class="t-body-m shrink-0 text-muted">{{ row.label }}</span>
        <span class="t-body-m-strong min-w-0 flex-1 text-right" data-testid="plan-value">{{ row.value }}</span>
      </li>
    </ul>
    <OnboardingActions
      :label="t('onboarding.plan.start')"
      :busy="busy"
      :error="store.saveErrorStep === 'complete' ? store.saveError : ''"
      @primary="onStart"
    />
  </div>
</template>

<style scoped>
/* The check stamp of the kit: a muhr ring with a dashed inner ring, turned like the stamp (-8deg). */
.plan-stamp {
  position: relative;
  border: 2.5px solid var(--app-muhr);
  transform: rotate(-8deg);
}
.plan-stamp::after {
  content: '';
  position: absolute;
  inset: 3px;
  border: 1px dashed var(--app-muhr);
  border-radius: 9999px;
}
.plan-row {
  position: relative;
}
.plan-row + .plan-row::before {
  content: '';
  position: absolute;
  top: 0;
  left: 52px;
  right: -16px;
  border-top: 1px solid var(--app-divider);
}
</style>
