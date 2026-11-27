<script setup lang="ts">
import { computed } from 'vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import ProBenefits from '@/components/ui/ProBenefits.vue'
import { BRAND } from '@/brand'
import { formatDate } from '@/composables/format'
import { trialDaysLeft } from '@/composables/trial'
import { useI18n } from '@/i18n'

/**
 * The one-off sheet of the welcome trial (docs/36 §4б, W3), in the frame of the Pro screen of the kit Muhrla (10-podpiska-pro): on the
 * centre axis the mascot in the scarf, the ink badge "<brand> Pro", "Pro for N days - until :date", one line on what it means; then what
 * Pro gives - the same list as the paywall
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
    <!-- The hero of the Pro screen of the kit (10-podpiska-pro): the mascot in the scarf, the ink chip of Pro, the title, the line. -->
    <div class="flex flex-col items-center gap-2 px-6 pt-2 text-center">
      <MascotArt pose="freeze" :height="96" class="short:hidden" />
      <span class="chip chip-ink gap-1.5" data-testid="trial-welcome-badge">
        <AppIcon name="sparkles" :size="14" />
        {{ t('trialSheet.badge', { brand: BRAND }) }}
      </span>
      <h2 class="t-display-m" data-testid="trial-welcome-title">{{ title }}</h2>
      <p class="t-body-m text-muted">{{ t('trialSheet.text') }}</p>
    </div>

    <ProBenefits short compact />

    <div class="flex flex-col gap-1.5 pb-1">
      <button type="button" class="btn btn-primary btn-block" data-testid="trial-welcome-ok" @click="emit('close')">
        {{ t('trialSheet.ok') }}
      </button>
      <button type="button" class="btn btn-ghost btn-block" data-testid="trial-welcome-more" @click="emit('more')">
        {{ t('trialSheet.more') }}
      </button>
    </div>
  </BottomSheet>
</template>
