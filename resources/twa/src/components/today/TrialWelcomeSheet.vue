<script setup lang="ts">
import { computed } from 'vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ProBenefits from '@/components/ui/ProBenefits.vue'
import { BRAND } from '@/brand'
import { formatDate } from '@/composables/format'
import { trialDaysLeft } from '@/composables/trial'
import { useI18n } from '@/i18n'

/**
 * The one-off sheet of the welcome trial (docs/36 §4б, W3), in the frame of the Pro mock-up (docs/design/2026-11-25-redesign Pro): the
 * badge "<brand> Pro", "Pro for N days - until :date", one line on what it means and what Pro gives - the same list as the paywall
 * (`ProBenefits short`, by the server's word on practice and the game). The days and the date come from the end of the trial (`/me`
 * `renewal.ends_at`), nothing is written into the text. When to show it is the screen's business (`takeTrialWelcome`).
 *
 * - `endsAt` — the end of the trial (ISO 8601).
 * - `timezone` — the learner's zone (`/today.timezone`): the date is the one of the learner's day.
 *
 * Emits `close` ("Got it", the cross, the dimmed page) and `more` ("About Pro": the subscription screen).
 */
const props = defineProps<{ endsAt: string; timezone?: string }>()
const emit = defineEmits<{ close: []; more: [] }>()

const { t, tn, locale } = useI18n()

const title = computed(() =>
  tn('trialSheet.title', trialDaysLeft(props.endsAt), { date: formatDate(props.endsAt, 'dayMonth', locale.value, props.timezone) }),
)
</script>

<template>
  <BottomSheet :label="t('trialSheet.label')" data-testid="trial-welcome-sheet" @close="emit('close')">
    <div class="flex flex-col gap-2.5 pt-3">
      <span class="inline-flex h-7 items-center gap-1.5 self-start rounded-full bg-accent-soft px-3 text-[13px] font-bold text-accent-ink" data-testid="trial-welcome-badge">
        <AppIcon name="sparkles" :size="16" />
        {{ t('trialSheet.badge', { brand: BRAND }) }}
      </span>
      <h2 class="text-[28px] leading-[34px] font-extrabold tracking-[-0.5px]" data-testid="trial-welcome-title">{{ title }}</h2>
      <p class="text-[15px] leading-[21px] text-muted">{{ t('trialSheet.text') }}</p>
    </div>

    <ProBenefits short />

    <div class="flex flex-col gap-1.5 pb-1">
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="trial-welcome-ok" @click="emit('close')">
        {{ t('trialSheet.ok') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="trial-welcome-more" @click="emit('more')">
        {{ t('trialSheet.more') }}
      </button>
    </div>
  </BottomSheet>
</template>
