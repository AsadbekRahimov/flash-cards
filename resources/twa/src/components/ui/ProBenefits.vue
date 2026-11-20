<script setup lang="ts">
import { computed } from 'vue'
import { useBillingStore } from '@/stores/billing'
import { useI18n } from '@/i18n'
import { resolveBenefits, type BenefitSlot } from '@/composables/proBenefits'
import type { StringKey } from '@/i18n/ru'

/**
 * What Pro gives (the Subscription screen, the paywall, the Profile pitch): plain lines between hairlines, as on the Pro
 * mock-up (docs/design Pro), no check marks.
 *
 * - `compact` — smaller text and tighter rows, for a bottom sheet.
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

/** The slots of the list, in order (`resolveBenefits` decides which of `practice` / `game` is there for this learner). */
const BENEFITS: readonly BenefitSlot[] = [
  'benefits.new_words',
  'practice',
  'game',
  'benefits.exercises',
  'benefits.decks',
  'benefits.freezes',
  'benefits.mock_exams',
]

const billing = useBillingStore()
const { t } = useI18n()

const benefits = computed((): StringKey[] =>
  resolveBenefits(BENEFITS, billing.practiceAccess.pro_promise, billing.gameAccess.pro_promise),
)
</script>

<template>
  <ul class="border-t border-hairline" data-testid="pro-benefits">
    <li
      v-for="key in benefits"
      :key="key"
      class="border-b border-divider"
      :class="compact ? 'py-2.5 text-sm leading-5' : 'py-3.5 text-[15px] leading-5'"
      data-testid="benefit"
    >
      {{ t(key) }}
    </li>
  </ul>
</template>
