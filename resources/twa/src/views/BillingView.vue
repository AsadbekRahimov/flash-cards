<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import LapsedBanner from '@/components/LapsedBanner.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useTelegram } from '@/composables/useTelegram'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'

/**
 * "Subscription" (docs/13 §5): the plan and its end, the auto-renewal of a Stars subscription with
 * its cancel / resume buttons, and "Buy / Renew" which opens the purchase paywall. The history of
 * payments is task 2A.7.
 */
const router = useRouter()
const auth = useAuthStore()
const billing = useBillingStore()
const { showBackButton } = useTelegram()
const { t, locale } = useI18n()

const failed = ref('')
const paywallOpen = ref(false)
let hideBack: (() => void) | null = null

const entitlement = computed(() => billing.entitlement)
const isPro = computed(() => entitlement.value?.plan === 'pro')
const renewal = computed(() => entitlement.value?.renewal ?? null)
const subscription = computed(() => billing.catalog?.subscription ?? null)
const lapsed = computed(() => !isPro.value && (entitlement.value?.lapsed ?? null) !== null)
const sponsored = computed(() => isPro.value && renewal.value === null && entitlement.value?.source.startsWith('sponsor:') === true)

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso))
}

const untilText = computed(() => {
  const until = renewal.value?.ends_at ?? entitlement.value?.until ?? null
  return until ? t('billing.until', { date: formatDate(until) }) : t('billing.forever')
})

const PROVIDER_LABELS: Record<string, StringKey> = {
  telegram_stars: 'billing.provider.telegram_stars',
  payme: 'billing.provider.payme',
  click: 'billing.provider.click',
  manual: 'billing.provider.manual',
  promo: 'billing.provider.promo',
  reward: 'billing.provider.reward',
}

const providerText = computed(() => {
  const label = PROVIDER_LABELS[renewal.value?.provider ?? '']
  return label ? t('billing.provider_line', { provider: t(label) }) : ''
})

/** The banner's "Renew" is the same action as the button below it, where there is something to buy. */
function onLapsedRenew(): void {
  if (billing.canPurchase) paywallOpen.value = true
}

async function load(): Promise<void> {
  failed.value = ''
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  await Promise.all([billing.load(), billing.loadCatalog()])
}

onMounted(() => {
  hideBack = showBackButton(() => void router.push({ name: 'settings' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <h1 class="text-xl font-bold mb-4">{{ t('billing.title') }}</h1>

    <div v-if="failed" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ failed }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <div v-else-if="!entitlement" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else class="space-y-3">
      <LapsedBanner v-if="!isPro" preloaded @renew="onLapsedRenew()" />

      <div class="card space-y-1" data-testid="plan-card">
        <div class="font-semibold" data-testid="plan-name">{{ isPro ? t('billing.plan.pro') : t('billing.plan.free') }}</div>
        <template v-if="isPro">
          <div v-if="sponsored" class="text-sm text-tg-hint" data-testid="plan-sponsored">{{ t('billing.sponsored') }}</div>
          <div class="text-sm text-tg-hint" data-testid="plan-until">{{ untilText }}</div>
          <div v-if="providerText" class="text-sm text-tg-hint" data-testid="plan-provider">{{ providerText }}</div>
        </template>
        <template v-else>
          <div v-if="entitlement.new_words_per_day !== null" class="text-sm text-tg-hint" data-testid="plan-free">
            {{ t('paywall.free', { count: entitlement.new_words_per_day }) }}
          </div>
        </template>
      </div>

      <div v-if="subscription && (subscription.can_cancel || subscription.can_resume)" class="card space-y-3" data-testid="renewal-card">
        <p class="text-sm" data-testid="renewal-state">{{ subscription.can_cancel ? t('billing.auto_on') : t('billing.auto_off') }}</p>
        <button
          v-if="subscription.can_cancel"
          type="button"
          class="btn btn-secondary w-full"
          :disabled="billing.acting"
          data-testid="cancel-renewal"
          @click="billing.cancelRenewal()"
        >
          {{ t('billing.cancel') }}
        </button>
        <button
          v-else
          type="button"
          class="btn btn-primary w-full"
          :disabled="billing.acting"
          data-testid="resume-renewal"
          @click="billing.resumeRenewal()"
        >
          {{ t('billing.resume') }}
        </button>
        <p v-if="billing.actionError" class="text-red-600 text-sm" role="alert" data-testid="renewal-error">{{ billing.actionError }}</p>
      </div>

      <button
        v-if="billing.canPurchase && !subscription?.auto_renew"
        type="button"
        class="btn btn-primary w-full"
        data-testid="buy"
        @click="paywallOpen = true"
      >
        {{ isPro || lapsed ? t('billing.renew') : t('billing.buy') }}
      </button>
      <p v-else-if="!isPro && !billing.canPurchase" class="text-sm text-tg-hint text-center" data-testid="unavailable">{{ t('billing.unavailable') }}</p>
    </div>

    <PaywallSheet v-if="paywallOpen" @close="paywallOpen = false" />
  </section>
</template>
