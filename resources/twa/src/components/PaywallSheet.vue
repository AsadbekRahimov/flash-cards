<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import PromoRedeem from '@/components/PromoRedeem.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ProBenefits from '@/components/ui/ProBenefits.vue'
import SegmentedControl, { type SegmentOption } from '@/components/ui/SegmentedControl.vue'
import { PROVIDER_CURRENCY, PROVIDER_ORDER, formatPrice, yearSavingPct } from '@/composables/billingFormat'
import { formatDate } from '@/composables/format'
import { supportContactLink } from '@/composables/supportContact'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { BillingPeriod, BillingProviderKey, MeResponse } from '@/api/types'

export type PaywallReason = 'quota' | 'info' | 'decks' | 'streak_freeze' | 'exam_simulator'

/**
 * Paywall (docs/12 §5.4, docs/13 §5, docs/14 §6.6): a bottom sheet with a grabber and a close button, the
 * sparkles icon, the title, the reason, what Free gives and what Pro gives (`ProBenefits compact`).
 *
 * Mode `coming_soon` (Phase 1): "Pro is coming", what Free gives, a promo code field and — only when
 * the owner configured one — the support contact for centers.
 *
 * Mode `purchase` (the server reports it only while something is for sale, see `/me`): the offer chip, the period
 * (`SegmentedControl`, the year says how much it saves), the prices from `GET /billing/catalog` and one pay button per
 * provider — the first is the primary one — then the order is polled until the webhook has paid it. Stars opens the
 * Telegram invoice, Payme / Click the payment page. The switch is `billing.canPurchase` (mode `purchase` AND a
 * non-empty catalog): anything else, including a catalog that failed to load, keeps the `coming_soon` copy.
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

/** What the reason adds before the sales pitch (none for `info`). */
const REASON_LEADS: Partial<Record<PaywallReason, StringKey>> = {
  quota: 'paywall.lead_quota',
  decks: 'paywall.lead_decks',
  streak_freeze: 'paywall.lead_streak_freeze',
  exam_simulator: 'paywall.lead_exam_simulator',
}

const lead = computed(() => {
  const pitch = purchaseMode.value ? t('paywall.lead_purchase') : t('paywall.soon')
  const reason = REASON_LEADS[props.reason]
  return reason ? `${t(reason)} ${pitch}` : pitch
})

const periods = computed<BillingPeriod[]>(() => (billing.catalog?.items ?? []).map((item) => item.period))
const chosenPeriod = ref<BillingPeriod | null>(null)
const period = computed<BillingPeriod | null>(() => chosenPeriod.value ?? periods.value[0] ?? null)
const item = computed(() => billing.catalog?.items.find((i) => i.period === period.value) ?? null)
const offer = computed(() => billing.catalog?.offer ?? null)
/** Stars renew by themselves: paying again would charge twice (the server refuses with checkout_conflict). */
const renewing = computed(() => billing.catalog?.subscription?.auto_renew === true)
/** A perpetual grant leaves nothing to buy (the server refuses with already_perpetual). */
const perpetual = computed(() => billing.catalog?.subscription?.perpetual === true)

const periodOptions = computed<SegmentOption[]>(() => {
  const saving = yearSavingPct(billing.catalog?.items ?? [])

  return periods.value.map((p) => ({
    value: p,
    label: p === 'year' && saving !== null ? t('billing.period.year_save', { pct: saving }) : t(`billing.period.${p}`),
    testid: `period-${p}`,
  }))
})

function choosePeriod(value: string | number): void {
  const found = periods.value.find((p) => p === value)
  if (found) chosenPeriod.value = found
}

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

const offerText = computed(() =>
  offer.value
    ? t('billing.offer', { pct: offer.value.discount_pct, date: formatDate(offer.value.expires_at, 'dayMonthYear', locale.value) })
    : '',
)

const paidMessage = computed(() => {
  const until = billing.entitlement?.until
  return until ? t('billing.paid_until', { date: formatDate(until, 'dayMonthYear', locale.value) }) : t('billing.paid_forever')
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
    <!-- The backdrop above is a neutral scrim: the theme has no token for it, and black dims both the light and the dark page. -->
    <section
      ref="sheet"
      class="sheet relative flex max-h-[90dvh] flex-col overflow-hidden outline-hidden"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      :aria-label="purchaseMode ? t('paywall.title_purchase') : t('paywall.title')"
      data-testid="paywall"
    >
      <!-- A 44 px target around a 32 px circle; outside the scrolling part, so it stays in reach. -->
      <button
        type="button"
        class="absolute top-1.5 right-1.5 z-10 flex size-11 items-center justify-center text-tg-hint"
        :aria-label="t('common.close')"
        data-testid="paywall-x"
        @click="emit('close')"
      >
        <span class="flex size-8 items-center justify-center rounded-full bg-fill"><AppIcon name="x-mark" :size="18" /></span>
      </button>

      <div class="-mx-4 min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-4">
        <header class="flex flex-col items-center gap-2 text-center">
          <span class="flex size-14 items-center justify-center rounded-full bg-accent-soft text-accent">
            <AppIcon name="sparkles" solid :size="28" />
          </span>

          <h2 class="text-[22px] leading-7 font-bold" data-testid="paywall-title">
            {{ done ? 'Pro' : purchaseMode ? t('paywall.title_purchase') : t('paywall.title') }}
          </h2>

          <p v-if="paid" class="flex items-start gap-2 text-[15px] leading-5 font-semibold" data-testid="paywall-paid">
            <AppIcon name="check-circle" solid :size="20" class="text-success" />
            <span>{{ paidMessage }}</span>
          </p>

          <template v-if="!done">
            <p class="text-[15px] leading-5" data-testid="paywall-lead">{{ lead }}</p>

            <p class="text-[13px] leading-[18px] text-tg-hint" data-testid="paywall-free">
              {{ limit !== null ? t('paywall.free', { count: limit }) : t('paywall.free_generic') }}
            </p>
          </template>
        </header>

        <div v-if="!done" class="rounded-xl bg-fill px-3.5 py-3">
          <ProBenefits compact />
        </div>

        <section v-if="purchaseMode && !done" class="space-y-3" data-testid="purchase">
          <p v-if="offer" class="flex justify-center">
            <span class="chip chip-warning h-auto py-1 text-center whitespace-normal" data-testid="purchase-offer">{{ offerText }}</span>
          </p>

          <SegmentedControl
            v-if="periodOptions.length > 1"
            :model-value="period ?? ''"
            :options="periodOptions"
            :disabled="busy"
            :ariaLabel="t('billing.period.label')"
            @update:model-value="choosePeriod"
          />

          <p v-if="renewing" class="flex items-start gap-2 text-sm leading-5 text-tg-subtitle" data-testid="purchase-renewing">
            <AppIcon name="arrow-path" :size="18" class="mt-px text-accent" />
            <span>{{ t('billing.auto_renew_running') }}</span>
          </p>

          <p v-else-if="perpetual" class="flex items-start gap-2 text-sm leading-5 text-tg-subtitle" data-testid="purchase-perpetual">
            <AppIcon name="check-circle" solid :size="18" class="mt-px text-success" />
            <span>{{ t('billing.perpetual') }}</span>
          </p>

          <div v-else class="space-y-2">
            <p class="text-[13px] leading-[18px] text-tg-hint" data-testid="pay-method">{{ t('billing.pay_method') }}</p>

            <button
              v-for="(option, index) in options"
              :key="option.provider"
              type="button"
              class="btn btn-block justify-between"
              :class="index === 0 ? 'btn-primary' : 'btn-secondary'"
              :disabled="busy"
              :aria-label="t('billing.pay_with', { provider: t(`billing.provider.${option.provider}`), price: option.price })"
              :data-testid="`pay-${option.provider}`"
              @click="pay(option.provider)"
            >
              <span>{{ t(`billing.provider.${option.provider}`) }}</span>
              <span class="flex items-baseline gap-2">
                <span>{{ option.price }}</span>
                <s v-if="option.listPrice" class="text-sm font-normal opacity-70" data-testid="list-price">{{ option.listPrice }}</s>
              </span>
            </button>
          </div>

          <p
            v-if="billing.purchase.status === 'awaiting' && billing.purchase.notice"
            class="flex items-start gap-2 text-sm leading-5 text-tg-subtitle"
            role="status"
            data-testid="purchase-notice"
          >
            <AppIcon name="information-circle" :size="18" class="mt-px" />
            <span>{{ billing.purchase.notice }}</span>
          </p>
          <p
            v-if="billing.purchase.status === 'starting'"
            class="flex items-start gap-2 text-sm leading-5 text-tg-subtitle"
            role="status"
            data-testid="purchase-starting"
          >
            <AppIcon name="arrow-path" :size="18" class="mt-px animate-spin motion-reduce:animate-none" />
            <span>{{ t('billing.starting') }}</span>
          </p>
          <p
            v-else-if="billing.purchase.status === 'awaiting'"
            class="flex items-start gap-2 text-sm leading-5 text-tg-subtitle"
            role="status"
            data-testid="purchase-awaiting"
          >
            <AppIcon name="clock" :size="18" class="mt-px" />
            <span>{{ t('billing.awaiting') }}</span>
          </p>
          <p
            v-else-if="billing.purchase.status === 'replaced'"
            class="flex items-start gap-2 text-sm leading-5 text-tg-subtitle"
            role="status"
            data-testid="purchase-replaced"
          >
            <AppIcon name="information-circle" :size="18" class="mt-px" />
            <span>{{ billing.purchase.notice }}</span>
          </p>
          <div v-else-if="billing.purchase.status === 'timeout'" class="space-y-2" data-testid="purchase-timeout">
            <p class="flex items-start gap-2 text-sm leading-5 text-tg-subtitle">
              <AppIcon name="clock" :size="18" class="mt-px text-warning-ink" />
              <span>{{ t('billing.timeout') }}</span>
            </p>
            <button type="button" class="btn btn-tinted btn-block" data-testid="purchase-check" @click="billing.waitForPayment()">
              {{ t('billing.check_again') }}
            </button>
          </div>
          <p
            v-else-if="billing.purchase.status === 'failed'"
            class="flex items-start gap-2 text-sm leading-5 text-danger-ink"
            role="alert"
            data-testid="purchase-error"
          >
            <AppIcon name="exclamation-circle" :size="18" class="mt-px" />
            <span>{{ billing.purchase.error }}</span>
          </p>
        </section>

        <button
          v-if="!done && dueCount > 0"
          type="button"
          class="btn btn-block"
          :class="purchaseMode ? 'btn-tinted' : 'btn-primary'"
          data-testid="paywall-review"
          @click="emit('review')"
        >
          {{ t('paywall.review') }}
        </button>

        <PromoRedeem @redeemed="onRedeemed" />

        <p v-if="contact && !done" class="text-center text-[13px] leading-[18px] text-tg-hint" data-testid="paywall-contact">
          {{ t('paywall.contact') }}
          <a
            v-if="contact.href"
            :href="contact.href"
            target="_blank"
            rel="noopener noreferrer"
            class="break-words text-tg-link underline"
            data-testid="paywall-contact-link"
          >{{ contact.label }}</a>
          <span v-else class="font-semibold break-words" data-testid="paywall-contact-text">{{ contact.label }}</span>
        </p>

        <button type="button" class="btn btn-plain btn-block" data-testid="paywall-close" @click="emit('close')">
          {{ t('paywall.dismiss') }}
        </button>
      </div>
    </section>
  </div>
</template>
