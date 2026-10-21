<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import PromoRedeem from '@/components/PromoRedeem.vue'
import { PROVIDER_CURRENCY, PROVIDER_ORDER, formatPrice } from '@/composables/billingFormat'
import { supportContactLink } from '@/composables/supportContact'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { BillingPeriod, BillingProviderKey, MeResponse } from '@/api/types'

export type PaywallReason = 'quota' | 'info' | 'decks' | 'streak_freeze' | 'exam_simulator'

/**
 * Paywall (docs/12 §5.4, docs/13 §5).
 *
 * Mode `coming_soon` (Phase 1): "Pro is coming", what Free gives, a promo code field and — only when
 * the owner configured one — the support contact for centers.
 *
 * Mode `purchase` (the server reports it only while something is for sale, see `/me`): the period, the
 * prices from `GET /billing/catalog` and one pay button per provider — Stars opens the Telegram invoice,
 * Payme / Click the payment page — then the order is polled until the webhook has paid it. The switch is
 * `billing.canPurchase` (mode `purchase` AND a non-empty catalog): anything else, including a catalog that
 * failed to load, keeps the `coming_soon` copy.
 *
 * `reason = 'quota'` is the end of today's new words; `'decks'` is a refused deck / deck-word limit
 * (docs/04 A.10); `'streak_freeze'` is the Free teaser of streak freezes (docs/13 §7.2); `'exam_simulator'`
 * is a refused IELTS / SAT mock (docs/13 §8.4); `'info'` is the learner opening it from the Today screen. A promo code that works or a
 * confirmed payment refreshes `/me` (billing store) and `/today`.
 *
 * "Repeat words" (shown when the Today store knows of due cards) emits `review`: reviews are never
 * limited on Free, so the quota wall is not a dead end. The parent opens the daily_review session.
 *
 * a11y (minimal): the focus moves into the dialog on open and returns to the opener on close;
 * Escape closes it.
 */
const props = withDefaults(defineProps<{ reason?: PaywallReason }>(), { reason: 'info' })
const emit = defineEmits<{ close: []; redeemed: [me: MeResponse]; review: [] }>()

const { t, locale } = useI18n()
const billing = useBillingStore()
const today = useTodayStore()

const redeemed = ref(false)
const sheet = ref<HTMLElement | null>(null)
let opener: HTMLElement | null = null

const dueCount = computed(() => today.data?.due_count ?? 0)

const limit = computed(() => billing.entitlement?.new_words_per_day ?? null)
const contact = computed(() => supportContactLink(billing.paywall?.contact))

const purchaseMode = computed(() => billing.canPurchase)
const paid = computed(() => billing.purchase.status === 'paid')
const done = computed(() => redeemed.value || paid.value)
const busy = computed(() => billing.purchase.status === 'starting' || billing.purchase.status === 'awaiting')

const periods = computed<BillingPeriod[]>(() => (billing.catalog?.items ?? []).map((item) => item.period))
const chosenPeriod = ref<BillingPeriod | null>(null)
const period = computed<BillingPeriod | null>(() => chosenPeriod.value ?? periods.value[0] ?? null)
const item = computed(() => billing.catalog?.items.find((i) => i.period === period.value) ?? null)
const offer = computed(() => billing.catalog?.offer ?? null)
/** Stars renew by themselves: paying again would charge twice (the server refuses with checkout_conflict). */
const renewing = computed(() => billing.catalog?.subscription?.auto_renew === true)

interface PayOption {
  provider: BillingProviderKey
  price: string
  /** Price before the offer, only when the offer lowered it. */
  listPrice: string | null
}

const options = computed<PayOption[]>(() => {
  const current = item.value
  if (!current) return []

  const result: PayOption[] = []
  for (const provider of PROVIDER_ORDER) {
    const currency = PROVIDER_CURRENCY[provider]
    const price = current.prices[currency]
    if (!billing.catalog?.providers.includes(provider) || price === undefined) continue

    const list = current.list_prices[currency]
    result.push({
      provider,
      price: formatPrice(price, currency, locale.value),
      listPrice: list !== undefined && list > price ? formatPrice(list, currency, locale.value) : null,
    })
  }
  return result
})

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso))
}

const paidMessage = computed(() => {
  const until = billing.entitlement?.until
  return until ? t('billing.paid_until', { date: formatDate(until) }) : t('billing.paid_forever')
})

function pay(provider: BillingProviderKey): void {
  if (period.value && !busy.value) void billing.buy(period.value, provider)
}

function onRedeemed(me: MeResponse): void {
  redeemed.value = true
  billing.apply(me)
  // The numbers on the Today screen (quota, plan badge) change with the plan.
  void today.load()
  emit('redeemed', me)
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => {
  void billing.load().then(() => {
    if (billing.paywall?.mode === 'purchase') void billing.loadCatalog()
  })
  opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
  document.addEventListener('keydown', onKeydown)
  void nextTick(() => sheet.value?.focus())
})

onBeforeUnmount(() => {
  // Closing the sheet stops the wait; a payment already made still arrives through the webhook.
  billing.resetPurchase()
  document.removeEventListener('keydown', onKeydown)
  opener?.focus()
})
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40" data-testid="paywall-backdrop" @click.self="emit('close')">
    <section
      class="w-full max-w-xl rounded-t-3xl p-5 space-y-4 max-h-[90vh] overflow-y-auto outline-none"
      style="background: var(--tg-bg)"
      ref="sheet"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      :aria-label="purchaseMode ? t('paywall.title_purchase') : t('paywall.title')"
      data-testid="paywall"
    >
      <h2 class="text-xl font-bold text-center" data-testid="paywall-title">
        {{ done ? 'Pro' : purchaseMode ? t('paywall.title_purchase') : t('paywall.title') }}
      </h2>

      <p v-if="paid" class="font-semibold text-center" data-testid="paywall-paid">{{ paidMessage }}</p>

      <template v-if="!done">
        <p v-if="props.reason === 'quota'" class="text-center" data-testid="paywall-lead">
          {{ t('paywall.lead_quota') }} {{ purchaseMode ? t('paywall.lead_purchase') : t('paywall.soon') }}
        </p>
        <p v-else-if="props.reason === 'decks'" class="text-center" data-testid="paywall-lead">
          {{ t('paywall.lead_decks') }} {{ purchaseMode ? t('paywall.lead_purchase') : t('paywall.soon') }}
        </p>
        <p v-else-if="props.reason === 'streak_freeze'" class="text-center" data-testid="paywall-lead">
          {{ t('paywall.lead_streak_freeze') }} {{ purchaseMode ? t('paywall.lead_purchase') : t('paywall.soon') }}
        </p>
        <p v-else-if="props.reason === 'exam_simulator'" class="text-center" data-testid="paywall-lead">
          {{ t('paywall.lead_exam_simulator') }} {{ purchaseMode ? t('paywall.lead_purchase') : t('paywall.soon') }}
        </p>
        <p v-else class="text-center" data-testid="paywall-lead">{{ purchaseMode ? t('paywall.lead_purchase') : t('paywall.soon') }}</p>

        <p class="text-sm text-tg-hint text-center" data-testid="paywall-free">
          {{ limit !== null ? t('paywall.free', { count: limit }) : t('paywall.free_generic') }}
        </p>
      </template>

      <section v-if="purchaseMode && !done" class="space-y-3" data-testid="purchase">
        <p v-if="offer" class="text-sm text-center font-semibold" data-testid="purchase-offer">
          {{ t('billing.offer', { pct: offer.discount_pct, date: formatDate(offer.expires_at) }) }}
        </p>

        <div v-if="periods.length > 1" class="flex gap-2" role="tablist">
          <button
            v-for="p in periods"
            :key="p"
            type="button"
            role="tab"
            class="btn flex-1"
            :class="p === period ? 'btn-primary' : 'btn-secondary'"
            :aria-selected="p === period"
            :disabled="busy"
            :data-testid="`period-${p}`"
            @click="chosenPeriod = p"
          >
            {{ t(`billing.period.${p}`) }}
          </button>
        </div>

        <p v-if="renewing" class="text-sm text-center text-tg-hint" data-testid="purchase-renewing">{{ t('billing.auto_renew_running') }}</p>

        <div v-else class="space-y-2">
          <button
            v-for="option in options"
            :key="option.provider"
            type="button"
            class="btn btn-primary w-full"
            :disabled="busy"
            :data-testid="`pay-${option.provider}`"
            @click="pay(option.provider)"
          >
            {{ t('billing.pay_with', { provider: t(`billing.provider.${option.provider}`), price: option.price }) }}
            <s v-if="option.listPrice" class="ml-1 opacity-70" data-testid="list-price">{{ option.listPrice }}</s>
          </button>
        </div>

        <p v-if="billing.purchase.status === 'awaiting' && billing.purchase.notice" class="text-sm text-center text-tg-hint" role="status" data-testid="purchase-notice">
          {{ billing.purchase.notice }}
        </p>
        <p v-if="billing.purchase.status === 'starting'" class="text-sm text-center text-tg-hint" role="status" data-testid="purchase-starting">
          {{ t('billing.starting') }}
        </p>
        <p v-else-if="billing.purchase.status === 'awaiting'" class="text-sm text-center text-tg-hint" role="status" data-testid="purchase-awaiting">
          {{ t('billing.awaiting') }}
        </p>
        <p v-else-if="billing.purchase.status === 'replaced'" class="text-sm text-center text-tg-hint" role="status" data-testid="purchase-replaced">
          {{ billing.purchase.notice }}
        </p>
        <div v-else-if="billing.purchase.status === 'timeout'" class="space-y-2 text-center" data-testid="purchase-timeout">
          <p class="text-sm text-tg-hint">{{ t('billing.timeout') }}</p>
          <button type="button" class="btn btn-secondary w-full" data-testid="purchase-check" @click="billing.waitForPayment()">
            {{ t('billing.check_again') }}
          </button>
        </div>
        <p v-else-if="billing.purchase.status === 'failed'" class="text-red-600 text-sm text-center" role="alert" data-testid="purchase-error">
          {{ billing.purchase.error }}
        </p>
      </section>

      <button
        v-if="!done && dueCount > 0"
        type="button"
        class="btn btn-primary w-full"
        data-testid="paywall-review"
        @click="emit('review')"
      >
        {{ t('paywall.review') }}
      </button>

      <PromoRedeem @redeemed="onRedeemed" />

      <p v-if="contact && !done" class="text-sm text-center" data-testid="paywall-contact">
        {{ t('paywall.contact') }}
        <a
          v-if="contact.href"
          :href="contact.href"
          target="_blank"
          rel="noopener noreferrer"
          class="text-tg-link underline break-all"
          data-testid="paywall-contact-link"
        >{{ contact.label }}</a>
        <span v-else class="font-semibold break-all" data-testid="paywall-contact-text">{{ contact.label }}</span>
      </p>

      <button type="button" class="btn btn-secondary w-full" data-testid="paywall-close" @click="emit('close')">
        {{ t('paywall.dismiss') }}
      </button>
    </section>
  </div>
</template>
