<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { useBillingStore } from '@/stores/billing'
import { useI18n } from '@/i18n'

/**
 * "Welcome back! The account is restored" (R11b): a one-time sheet for a learner who deleted the account and came back
 * within the 30 days. The server decides (`/me.account_restored`, kept by the billing store); the sheet takes the notice
 * from the store on mount, which tells the server once (`seen`) - so neither a second visit nor the bot's `/start` repeats it.
 * With `pro_can_resume` it adds that the Pro auto-renewal was stopped by the deletion and a button to the subscription screen.
 */
const billing = useBillingStore()
const router = useRouter()
const { t } = useI18n()

const open = ref(false)
const canResume = ref(false)

onMounted(async () => {
  await billing.load()
  const notice = billing.takeAccountRestored()
  if (notice === null) return
  canResume.value = notice.pro_can_resume === true
  open.value = true
})

function openBilling(): void {
  open.value = false
  void router.push({ name: 'billing' })
}
</script>

<template>
  <BottomSheet v-if="open" :label="t('restored.title')" data-testid="restored-sheet" @close="open = false">
    <header class="flex flex-col items-center gap-2 pt-1 text-center">
      <span class="flex size-14 items-center justify-center rounded-full bg-success-soft text-success-ink">
        <AppIcon name="check-circle" solid :size="28" />
      </span>
      <h2 class="text-[22px] leading-7 font-bold" data-testid="restored-title">{{ t('restored.title') }}</h2>
    </header>

    <div class="space-y-2 text-center text-[15px] leading-5">
      <p data-testid="restored-text">{{ t('restored.text') }}</p>
      <p v-if="canResume" class="text-tg-subtitle" data-testid="restored-pro">{{ t('restored.pro') }}</p>
    </div>

    <div class="space-y-2 pb-1">
      <button v-if="canResume" type="button" class="btn btn-secondary btn-block" data-testid="restored-billing" @click="openBilling()">
        {{ t('restored.billing') }}
      </button>
      <button type="button" class="btn btn-primary btn-block" data-testid="restored-ok" @click="open = false">
        {{ t('restored.ok') }}
      </button>
    </div>
  </BottomSheet>
</template>
