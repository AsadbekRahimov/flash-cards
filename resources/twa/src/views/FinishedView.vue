<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useTrainingStore } from '@/stores/training'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * "Session finished" (docs/14 §6.3): an immersive screen (no tab bar) that ends a personal or group session.
 * The numbers of a personal session (cards, share of right answers, new words) and the goal and streak of the day
 * come with the server summary (`training.summary`); a group session has none, so it says only how many cards were
 * reviewed. The buttons at the bottom lead out: "Home" (the one primary button) and "Close".
 */
const props = defineProps<{
  reviewed: number
  nextInDays: number
  /** `done_reason` of the last answer (docs/04 A.3); unknown values show no extra line. */
  reason?: string | null
}>()

const REASONS = ['lesson_complete', 'nothing_due', 'new_quota_exhausted', 'session_cap'] as const

const { close, notificationHaptic } = useTelegram()
const { t, tn } = useI18n()
const router = useRouter()
const training = useTrainingStore()

// Goal and streak of the day come with the server summary of a personal session (absent for group sessions).
const summary = computed(() => training.summary)
const goal = computed(() => summary.value?.goal ?? null)
const streak = computed(() => summary.value?.streak ?? null)

const nextLabel = computed(() =>
  props.nextInDays <= 0 ? t('finished.next_today') : tn('finished.next_in_days', props.nextInDays),
)

const reasonLabel = computed(() => {
  const reason = REASONS.find((r) => r === props.reason)
  return reason ? t(`finished.reason.${reason}`) : null
})

/** An empty session that a reason already explains ("nothing is due") has no numbers worth showing. */
const hasNumbers = computed(() => (summary.value?.reviewed ?? props.reviewed) > 0 || reasonLabel.value === null)

/** Share of right answers, whole percent; «—» while nothing was answered. */
const correctPercent = computed(() => {
  const current = summary.value
  return current !== null && current.reviewed > 0 ? `${Math.round((current.correct / current.reviewed) * 100)}%` : '—'
})

const goalPercent = computed(() => {
  const current = goal.value
  if (current === null) return 0
  if (current.met) return 100
  return current.target > 0 ? Math.min(100, Math.round((current.done / current.target) * 100)) : 0
})

// The end of today's new words is the moment to say what Free gives and what Pro will (docs/12 §5.4).
const quotaExhausted = computed(() => props.reason === 'new_quota_exhausted')
const paywallOpen = ref(quotaExhausted.value)

/** "Repeat words" in the paywall: closes it and starts a daily review; a failure is shown on this screen. */
async function reviewFromPaywall(): Promise<void> {
  paywallOpen.value = false
  const sessionId = await training.open({ mode: 'daily_review', source: 'self' })
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
}

notificationHaptic('success')
</script>

<template>
  <div class="page" data-testid="finished-view">
    <div class="flex flex-1 flex-col justify-center gap-5 py-4">
      <header class="text-center">
        <span
          class="mx-auto flex size-20 items-center justify-center rounded-full bg-success-soft text-success"
          data-testid="finished-icon"
        >
          <AppIcon name="check-circle" solid :size="48" />
        </span>
        <h1 class="mt-4 text-[28px] leading-[34px] font-bold tracking-tight">{{ t('finished.title') }}</h1>
        <p v-if="reasonLabel" class="mt-1 text-[15px] leading-5 text-tg-subtitle" data-testid="reason">{{ reasonLabel }}</p>
      </header>

      <div v-if="summary && hasNumbers" class="grid grid-cols-3 gap-2" data-testid="stats">
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-reviewed">{{ summary.reviewed }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ t('finished.stats.cards') }}</p>
        </div>
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-correct">{{ correctPercent }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ t('finished.stats.correct') }}</p>
        </div>
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-new">{{ summary.new_words }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ t('finished.stats.new') }}</p>
        </div>
      </div>
      <p
        v-else-if="!summary && hasNumbers"
        class="card text-center text-[17px] leading-[22px] font-semibold"
        data-testid="reviewed"
      >
        {{ tn('finished.reviewed', reviewed) }}
      </p>

      <section v-if="goal" class="card" data-testid="goal">
        <div class="flex items-center justify-between gap-3">
          <p
            class="text-[17px] leading-[22px] font-semibold"
            :class="{ 'text-success-ink': goal.met }"
            data-testid="goal-text"
          >
            {{ goal.met ? t('finished.goal_met') : t('finished.goal', { done: goal.done, target: goal.target }) }}
          </p>
          <AppIcon v-if="goal.met" name="check-circle" solid :size="22" class="shrink-0 text-success" />
        </div>
        <div
          class="progress mt-3"
          :class="{ 'progress-success': goal.met }"
          role="progressbar"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="goalPercent"
          :aria-label="t('today.goal.title')"
        >
          <div class="progress-fill" :style="{ width: `${goalPercent}%` }" />
        </div>

        <div v-if="streak && (streak.current > 0 || streak.at_risk)" class="mt-3 flex items-start gap-3 border-t pt-3">
          <span class="icon-tile tile-orange"><AppIcon name="fire" solid :size="18" /></span>
          <div class="min-w-0 flex-1">
            <p v-if="streak.current > 0" class="text-[15px] leading-[30px] font-semibold" data-testid="streak">
              {{ tn('today.streak.in_row', streak.current) }}
            </p>
            <p v-if="streak.at_risk" class="text-[15px] leading-5 text-warning-ink" data-testid="streak-at-risk">
              {{ t('today.streak.at_risk') }}
            </p>
          </div>
        </div>
      </section>

      <section class="card flex items-center gap-3">
        <span class="icon-tile tile-blue"><AppIcon name="clock" :size="18" /></span>
        <div>
          <p class="text-[13px] leading-[18px] text-tg-hint">{{ t('finished.next_label') }}</p>
          <p class="text-[17px] leading-[22px] font-semibold" data-testid="next-label">{{ nextLabel }}</p>
        </div>
      </section>
    </div>

    <div class="space-y-2 pt-4">
      <p
        v-if="training.openError"
        class="flex items-start gap-2 px-1 text-[15px] leading-5 text-danger-ink"
        role="alert"
        data-testid="open-error"
      >
        <AppIcon name="exclamation-circle" :size="20" class="mt-px" />
        <span>{{ training.openError }}</span>
      </p>

      <RouterLink :to="{ name: 'today' }" class="btn btn-primary btn-lg btn-block" data-testid="home-btn">
        {{ t('finished.home') }}
      </RouterLink>
      <button v-if="quotaExhausted" type="button" class="btn btn-tinted btn-block" data-testid="paywall-open" @click="paywallOpen = true">
        {{ t('paywall.open') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="close-btn" @click="close()">
        {{ t('common.close') }}
      </button>
    </div>

    <PaywallSheet v-if="paywallOpen" reason="quota" @close="paywallOpen = false" @review="reviewFromPaywall" />
  </div>
</template>
