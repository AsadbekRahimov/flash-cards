<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import PromoRedeem from '@/components/PromoRedeem.vue'
import { useTrainingStore } from '@/stores/training'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

const props = defineProps<{
  reviewed: number
  nextInDays: number
  /** `done_reason` of the last answer (docs/04 A.3); unknown values show no extra line. */
  reason?: string | null
}>()

const REASONS = ['lesson_complete', 'nothing_due', 'new_quota_exhausted', 'session_cap'] as const

const { close, notificationHaptic } = useTelegram()
const { t, tn } = useI18n()
const training = useTrainingStore()

// Goal and streak of the day come with the server summary of a personal session (absent for group sessions).
const goal = computed(() => training.summary?.goal ?? null)
const streak = computed(() => training.summary?.streak ?? null)

const nextLabel = computed(() =>
  props.nextInDays <= 0 ? t('finished.next_today') : tn('finished.next_in_days', props.nextInDays),
)

const reasonLabel = computed(() => {
  const reason = REASONS.find((r) => r === props.reason)
  return reason ? t(`finished.reason.${reason}`) : null
})

notificationHaptic('success')
</script>

<template>
  <section class="flex-1 flex items-center justify-center p-6 text-center">
    <div class="max-w-sm space-y-5">
      <div class="text-6xl">🎉</div>
      <h1 class="text-2xl font-bold">{{ t('finished.title') }}</h1>
      <p v-if="reasonLabel" class="text-tg-hint" data-testid="reason">{{ reasonLabel }}</p>
      <p v-if="reviewed > 0 || !reasonLabel" class="text-tg-hint" data-testid="reviewed">{{ tn('finished.reviewed', reviewed) }}</p>
      <div class="card">
        <div class="text-sm text-tg-hint">{{ t('finished.next_label') }}</div>
        <div class="text-lg font-semibold mt-1" data-testid="next-label">{{ nextLabel }}</div>
      </div>
      <div v-if="goal" class="card space-y-1" data-testid="goal">
        <div class="text-lg font-semibold" data-testid="goal-text">
          {{ goal.met ? t('finished.goal_met') : t('finished.goal', { done: goal.done, target: goal.target }) }}
        </div>
        <div v-if="streak && streak.current > 0" class="text-sm" data-testid="streak">
          🔥 {{ tn('today.streak.days', streak.current) }}
        </div>
        <div v-if="streak?.at_risk" class="text-sm text-red-600" data-testid="streak-at-risk">
          {{ t('today.streak.at_risk') }}
        </div>
      </div>
      <PromoRedeem />
      <RouterLink :to="{ name: 'today' }" class="btn btn-primary w-full" data-testid="home-btn">
        {{ t('finished.home') }}
      </RouterLink>
      <button class="btn btn-secondary w-full" data-testid="close-btn" @click="close()">
        {{ t('common.close') }}
      </button>
    </div>
  </section>
</template>
