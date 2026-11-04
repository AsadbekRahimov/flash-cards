<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { useBillingStore } from '@/stores/billing'
import { useI18n } from '@/i18n'
import type { ProPromise } from '@/api/types'
import type { StringKey } from '@/i18n/ru'

/**
 * What Pro gives, as a list with check marks (the Subscription screen, the paywall, the Profile pitch).
 *
 * - `compact` — smaller text and tighter spacing, for a bottom sheet.
 *
 * Texts are `benefits.*` in the dictionaries; `data-testid="pro-benefits"` on the list, `benefit` on each item.
 *
 * The line about practice is the server's word: `practice.pro_promise` of `/me` (`ProPromises` — one rule, which the landing and the Stars
 * invoice follow too). `both` — "Practice and battles without a daily limit"; `solo` — "Practice without a daily limit" (the battles are
 * switched off, or Free has no limit on them); `none` — no such line: practice is not open to this learner (`PRACTICE_ACCESS=off`, a learner
 * outside the pilot), or Pro has nothing to lift there. The component works out nothing from the flags of the block, and until `/me` has come
 * the word is `none`.
 *
 * @example <ProBenefits compact />
 */
defineProps<{ compact?: boolean }>()

/** `practice` stands for the line about practice, which `practice.pro_promise` picks (or leaves out); the others are always on the list. */
const BENEFITS: readonly (StringKey | 'practice')[] = [
  'benefits.new_words',
  'practice',
  'benefits.exercises',
  'benefits.decks',
  'benefits.freezes',
  'benefits.mock_exams',
]

/** The sentence of each word that sells something; `none` — and a word this app does not know yet — has none. */
const PRACTICE_LINES: Partial<Record<ProPromise, StringKey>> = {
  both: 'benefits.practice',
  solo: 'benefits.practice_solo',
}

const billing = useBillingStore()
const { t } = useI18n()

const benefits = computed((): StringKey[] => {
  const practice = PRACTICE_LINES[billing.practiceAccess.pro_promise]

  return BENEFITS.flatMap((key) => (key !== 'practice' ? [key] : practice ? [practice] : []))
})
</script>

<template>
  <ul :class="compact ? 'space-y-2' : 'space-y-3'" data-testid="pro-benefits">
    <li v-for="key in benefits" :key="key" class="flex items-start gap-2.5" data-testid="benefit">
      <AppIcon name="check-circle" solid :size="compact ? 18 : 20" class="mt-px text-success" />
      <span :class="compact ? 'text-sm leading-5' : 'text-[15px] leading-5'">{{ t(key) }}</span>
    </li>
  </ul>
</template>
