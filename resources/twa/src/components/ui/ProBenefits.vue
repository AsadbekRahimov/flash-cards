<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'

/**
 * What Pro gives, as a list with check marks (the Subscription screen, the paywall, the Profile pitch).
 *
 * - `compact` — smaller text and tighter spacing, for a bottom sheet.
 *
 * Texts are `benefits.*` in the dictionaries; `data-testid="pro-benefits"` on the list, `benefit` on each item.
 *
 * @example <ProBenefits compact />
 */
defineProps<{ compact?: boolean }>()

const BENEFITS: readonly StringKey[] = [
  'benefits.new_words',
  'benefits.exercises',
  'benefits.decks',
  'benefits.freezes',
  'benefits.mock_exams',
]

const { t } = useI18n()
</script>

<template>
  <ul :class="compact ? 'space-y-2' : 'space-y-3'" data-testid="pro-benefits">
    <li v-for="key in BENEFITS" :key="key" class="flex items-start gap-2.5" data-testid="benefit">
      <AppIcon name="check-circle" solid :size="compact ? 18 : 20" class="mt-px text-success" />
      <span :class="compact ? 'text-sm leading-5' : 'text-[15px] leading-5'">{{ t(key) }}</span>
    </li>
  </ul>
</template>
