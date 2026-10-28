<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import LapsedBanner from '@/components/LapsedBanner.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import PromoRedeem from '@/components/PromoRedeem.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProBenefits from '@/components/ui/ProBenefits.vue'
import { PROVIDER_CURRENCY, PROVIDER_ORDER, formatPrice, graceText, inStarsGrace } from '@/composables/billingFormat'
import { formatDate } from '@/composables/format'
import { supportContactLink } from '@/composables/supportContact'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { BillingProviderKey, MeResponse } from '@/api/types'

/**
 * "Subscription" (docs/13 §5, docs/14 §6.6): the plan card, what Pro gives, the auto-renewal of a Stars subscription
 * with its cancel / resume buttons, the discount offer, "Buy / Renew" which opens the purchase paywall, the promo
 * code (moved here from the end-of-session screen) and the support contact. The history of payments is task 2A.7.
 * A page of the Profile tab: the system "Back" button leads to the Profile.
 *
 * "Buy / Renew" is there only when there is something to buy: not while the Stars renewal runs or is in grace (the
 * server refuses a payment then — the grace gets a warning instead) and not for a perpetual grant.
 */
const auth = useAuthStore()
const billing = useBillingStore()
const { t, tn, locale } = useI18n()

useBackButton({ name: 'profile' })

/** Why the screen failed (already localized); '' = no failure. */
const failed = ref('')
/** A load has finished (successfully or not), so an empty store means a failure and not "still loading". */
const attempted = ref(false)
const paywallOpen = ref(false)

const status = computed<'loading' | 'error' | 'ready'>(() => {
  if (failed.value !== '') return 'error'
  if (billing.entitlement !== null) return 'ready'
  // `billing.load()` keeps quiet on failure; another screen's request may still be in flight, so wait for it too.
  return attempted.value && !billing.loading ? 'error' : 'loading'
})

const errorText = computed(() => failed.value || t('error.generic'))

const entitlement = computed(() => billing.entitlement)
const isPro = computed(() => entitlement.value?.plan === 'pro')
const renewal = computed(() => entitlement.value?.renewal ?? null)
const subscription = computed(() => billing.catalog?.subscription ?? null)
const lapsed = computed(() => !isPro.value && (entitlement.value?.lapsed ?? null) !== null)
const sponsored = computed(() => isPro.value && renewal.value === null && entitlement.value?.source.startsWith('sponsor:') === true)
const support = computed(() => supportContactLink(billing.paywall?.contact))

/** The Stars renewal charge failed and Telegram retries it: Pro lives on, but any payment is refused (checkout_conflict). */
const grace = computed(() => inStarsGrace(subscription.value))
const graceMessage = computed(() => graceText(subscription.value?.ends_at ?? entitlement.value?.until ?? null, locale.value))

/** Stars renew by themselves (or are in grace) and a perpetual grant has an end of nothing: there is nothing to buy then. */
const canBuy = computed(() => billing.canPurchase && !subscription.value?.auto_renew && !subscription.value?.perpetual && !grace.value)

const untilText = computed(() => {
  const until = renewal.value?.ends_at ?? entitlement.value?.until ?? null
  return until ? t('billing.until', { date: formatDate(until, 'dayMonthYear', locale.value) }) : t('billing.forever')
})

const offer = computed(() => billing.catalog?.offer ?? null)
const offerText = computed(() =>
  offer.value
    ? t('billing.offer', { pct: offer.value.discount_pct, date: formatDate(offer.value.expires_at, 'dayMonthYear', locale.value) })
    : '',
)

interface OfferPrice {
  provider: BillingProviderKey
  price: string
  listPrice: string
}

/** The discounted price next to the struck-out list price, per provider, for the period the offer covers. */
const offerPrices = computed<OfferPrice[]>(() => {
  const current = offer.value
  const items = billing.catalog?.items ?? []
  const item = current ? (items.find((i) => i.period === current.period) ?? items[0]) : undefined
  if (!item) return []

  const result: OfferPrice[] = []
  for (const provider of PROVIDER_ORDER) {
    const currency = PROVIDER_CURRENCY[provider]
    const price = item.prices[currency]
    const list = item.list_prices[currency]
    if (!billing.catalog?.providers.includes(provider) || price === undefined || list === undefined || list <= price) continue
    result.push({ provider, price: formatPrice(price, currency, locale.value), listPrice: formatPrice(list, currency, locale.value) })
  }
  return result
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

/** "Repeat words" in the paywall sheet: it closes and the daily review starts (a failure is a toast). */
const { reviewFromPaywall } = useReviewFromPaywall(
  () => {
    paywallOpen.value = false
  },
  { toastErrors: true },
)

/** A promo code changes the plan: take the answer (`apply` also makes `/me` fresh), and the catalog says what can still be bought. */
function onRedeemed(me: MeResponse): void {
  billing.apply(me)
  void billing.loadCatalog()
}

async function load(): Promise<void> {
  failed.value = ''
  attempted.value = false
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  // The learner comes here to see the plan as it is now (a payment or a promo may have just changed it): always ask.
  await Promise.all([billing.load({ force: true }), billing.loadCatalog()])
  attempted.value = true
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="billing-view">
    <PageHeader :title="t('billing.title')" />

    <ErrorState v-if="status === 'error'" :message="errorText" @retry="load" />

    <LoadingState v-else-if="status === 'loading'" />

    <div v-else-if="entitlement" class="space-y-4">
      <LapsedBanner v-if="!isPro" preloaded @renew="onLapsedRenew()" />

      <section class="card" data-testid="plan-card">
        <div class="flex items-start gap-3">
          <span class="icon-tile tile-purple size-11 rounded-xl"><AppIcon name="sparkles" :size="22" /></span>
          <div class="min-w-0 flex-1 space-y-0.5">
            <div class="flex items-center gap-2">
              <h2 class="min-w-0 truncate text-[17px] leading-[22px] font-semibold" data-testid="plan-name">
                {{ isPro ? t('billing.plan.pro') : t('billing.plan.free') }}
              </h2>
              <span class="chip shrink-0" :class="isPro ? 'chip-accent' : 'chip-neutral'" data-testid="plan-chip">
                {{ t(isPro ? 'profile.chip.pro' : 'profile.chip.free') }}
              </span>
            </div>
            <template v-if="isPro">
              <p v-if="sponsored" class="text-[15px] leading-5 text-tg-subtitle" data-testid="plan-sponsored">{{ t('billing.sponsored') }}</p>
              <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="plan-until">{{ untilText }}</p>
              <p v-if="providerText" class="text-[15px] leading-5 text-tg-subtitle" data-testid="plan-provider">{{ providerText }}</p>
            </template>
            <p
              v-else-if="entitlement.new_words_per_day !== null"
              class="text-[15px] leading-5 text-tg-subtitle"
              data-testid="plan-free"
            >
              {{ tn('profile.plan.free', entitlement.new_words_per_day) }}
            </p>
          </div>
        </div>
      </section>

      <InlineAlert v-if="grace" tone="warning" :message="graceMessage" data-testid="grace-alert" />

      <section class="card space-y-3" data-testid="benefits-card">
        <h2 class="text-[17px] leading-[22px] font-semibold" data-testid="benefits-title">
          {{ isPro ? t('billing.benefits_title_pro') : t('billing.benefits_title') }}
        </h2>
        <ProBenefits />
      </section>

      <section v-if="subscription && (subscription.can_cancel || subscription.can_resume)" class="card space-y-3" data-testid="renewal-card">
        <p class="flex items-start gap-3 text-[15px] leading-5" data-testid="renewal-state">
          <AppIcon name="arrow-path" :size="20" class="mt-px text-accent" />
          <span>{{ subscription.can_cancel ? t('billing.auto_on') : t('billing.auto_off') }}</span>
        </p>
        <button
          v-if="subscription.can_cancel"
          type="button"
          class="btn btn-secondary btn-block"
          :disabled="billing.acting"
          data-testid="cancel-renewal"
          @click="billing.cancelRenewal()"
        >
          {{ t('billing.cancel') }}
        </button>
        <button
          v-else
          type="button"
          class="btn btn-primary btn-block"
          :disabled="billing.acting"
          data-testid="resume-renewal"
          @click="billing.resumeRenewal()"
        >
          {{ t('billing.resume') }}
        </button>
        <InlineAlert v-if="billing.actionError" tone="danger" :message="billing.actionError" data-testid="renewal-error" />
      </section>

      <section v-if="offer && canBuy" class="card space-y-3" data-testid="offer-card">
        <div class="flex items-start gap-3">
          <span class="chip chip-warning mt-px shrink-0" data-testid="offer-chip">{{ t('billing.offer_chip', { pct: offer.discount_pct }) }}</span>
          <p class="min-w-0 flex-1 text-[15px] leading-5 font-semibold" data-testid="offer-text">{{ offerText }}</p>
        </div>
        <div v-if="offerPrices.length > 0" class="space-y-1.5">
          <p
            v-for="p in offerPrices"
            :key="p.provider"
            class="flex items-baseline justify-between gap-3 text-[15px] leading-5"
            :data-testid="`offer-price-${p.provider}`"
          >
            <span class="text-tg-subtitle">{{ t(`billing.provider.${p.provider}`) }}</span>
            <span>
              <strong>{{ p.price }}</strong>
              <s class="ml-2 text-sm text-tg-hint" data-testid="offer-list-price">{{ p.listPrice }}</s>
            </span>
          </p>
        </div>
      </section>

      <button v-if="canBuy" type="button" class="btn btn-primary btn-lg btn-block" data-testid="buy" @click="paywallOpen = true">
        {{ isPro || lapsed ? t('billing.renew') : t('billing.buy') }}
      </button>
      <p
        v-else-if="!isPro && !billing.canPurchase"
        class="flex items-center justify-center gap-2 text-center text-[13px] leading-[18px] text-tg-hint"
        data-testid="unavailable"
      >
        <AppIcon name="clock" :size="16" />
        <span>{{ t('billing.unavailable') }}</span>
      </p>

      <section class="card space-y-2" data-testid="promo-card">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('billing.promo_title') }}</h2>
        <PromoRedeem @redeemed="onRedeemed" />
      </section>

      <p v-if="support" class="px-4 pt-1 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="billing-support">
        {{ t('profile.support') }}
        <a
          v-if="support.href"
          :href="support.href"
          target="_blank"
          rel="noopener noreferrer"
          class="break-words text-tg-link underline"
          data-testid="billing-support-link"
        >{{ support.label }}</a>
        <span v-else class="font-semibold break-words" data-testid="billing-support-text">{{ support.label }}</span>
      </p>
    </div>

    <PaywallSheet v-if="paywallOpen" @close="paywallOpen = false" @review="reviewFromPaywall" />
  </div>
</template>
