<script setup lang="ts">
import { computed } from 'vue'
import PromoRedeem from '@/components/PromoRedeem.vue'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

const props = defineProps<{
  reviewed: number
  nextInDays: number
}>()

const { close, notificationHaptic } = useTelegram()
const { t, tn } = useI18n()

const nextLabel = computed(() =>
  props.nextInDays <= 0 ? t('finished.next_today') : tn('finished.next_in_days', props.nextInDays),
)

notificationHaptic('success')
</script>

<template>
  <section class="flex-1 flex items-center justify-center p-6 text-center">
    <div class="max-w-sm space-y-5">
      <div class="text-6xl">🎉</div>
      <h1 class="text-2xl font-bold">{{ t('finished.title') }}</h1>
      <p class="text-tg-hint" data-testid="reviewed">{{ tn('finished.reviewed', reviewed) }}</p>
      <div class="card">
        <div class="text-sm text-tg-hint">{{ t('finished.next_label') }}</div>
        <div class="text-lg font-semibold mt-1" data-testid="next-label">{{ nextLabel }}</div>
      </div>
      <PromoRedeem />
      <button class="btn btn-primary w-full" data-testid="close-btn" @click="close()">
        {{ t('common.close') }}
      </button>
    </div>
  </section>
</template>
