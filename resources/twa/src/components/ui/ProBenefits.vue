<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { useBillingStore } from '@/stores/billing'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'

/**
 * What Pro gives, as a list with check marks (the Subscription screen, the paywall, the Profile pitch).
 *
 * - `compact` — smaller text and tighter spacing, for a bottom sheet.
 *
 * Texts are `benefits.*` in the dictionaries; `data-testid="pro-benefits"` on the list, `benefit` on each item.
 *
 * "Practice and battles without a daily limit" is on the list only where there is practice to sell: with `PRACTICE_ACCESS=off`, or for a
 * learner outside the pilot, `/me` says `practice.available = false` and the line is not offered (the Free limit it lifts does not exist
 * there either). With the practice behind Pro (`PRACTICE_PLAN=pro`) it IS available, `pro_required` set: the line stays.
 *
 * @example <ProBenefits compact />
 */
defineProps<{ compact?: boolean }>()

const BENEFITS: readonly StringKey[] = [
  'benefits.new_words',
  'benefits.practice',
  'benefits.exercises',
  'benefits.decks',
  'benefits.freezes',
  'benefits.mock_exams',
]

const billing = useBillingStore()
const { t } = useI18n()

/** With the battles switched off (`PRACTICE_BATTLE=false`) the line speaks of the practice alone: no battles are sold that do not exist. */
const benefits = computed((): StringKey[] =>
  BENEFITS.filter((key) => key !== 'benefits.practice' || billing.practiceAccess.available).map((key) =>
    key === 'benefits.practice' && !billing.practiceAccess.battle ? 'benefits.practice_solo' : key,
  ),
)
</script>

<template>
  <ul :class="compact ? 'space-y-2' : 'space-y-3'" data-testid="pro-benefits">
    <li v-for="key in benefits" :key="key" class="flex items-start gap-2.5" data-testid="benefit">
      <AppIcon name="check-circle" solid :size="compact ? 18 : 20" class="mt-px text-success" />
      <span :class="compact ? 'text-sm leading-5' : 'text-[15px] leading-5'">{{ t(key) }}</span>
    </li>
  </ul>
</template>
