<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, onUpdated, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDialogFocus } from '@/composables/useDialogFocus'
import PromoRedeem from '@/components/PromoRedeem.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ProBenefits from '@/components/ui/ProBenefits.vue'
import { PROVIDER_CURRENCY, PROVIDER_ORDER, formatPrice, graceText, inStarsGrace, orderedItems, periodSavingPct } from '@/composables/billingFormat'
import { formatDate } from '@/composables/format'
import { paywallPlacement, reportOfferViewed } from '@/composables/offerViewed'
import { resetWhen } from '@/composables/practiceLimits'
import { supportContactLink } from '@/composables/supportContact'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { GameBoosterLimitHit } from '@/api/gameShopTypes'
import type { BillingPeriod, BillingProviderKey, CatalogItem, DeckLimits, MeResponse, PracticeLimitHit, PracticeLimitKind } from '@/api/types'

export type PaywallReason = 'quota' | 'info' | 'decks' | 'streak_freeze' | 'exam_simulator' | 'practice' | 'practice_limit' | 'game'

/**
 * Paywall (docs/12 §5.4, docs/13 §5, docs/14 §6.6, the Pro mock-up of docs/design): a white bottom sheet with a grabber and a close
 * button; "LexiFlow Pro" above the title, the title is the reason the sheet is open (`HEADINGS`, by the placement), the lead, what Free
 * gives and the three main things Pro gives (`ProBenefits short`: the name on the left, the note on the right).
 *
 * Mode `coming_soon` (Phase 1): "Pro is coming", what Free gives, a promo code field and — only when
 * the owner configured one — the support contact for centers.
 *
 * Mode `purchase` (the server reports it only while something is for sale, see `/me`): the offer chip, the periods as cards in a row
 * (month → quarter → year, only the ones `GET /billing/catalog` sent; each with its price and, when it saves, "N % cheaper"), the
 * primary button with the price in it ("Get Pro for 150 Stars", the first provider that prices the chosen period), "Not now", a small
 * line on how the payment goes (the way of the payment in progress once one was started here, else the primary button's), and the
 * other providers below — then the order is polled until the webhook has paid it. Every period offers the providers whose currency
 * the catalog prices it in (the quarter, like the month and the year, in Stars and in sums). Stars opens the
 * Telegram invoice, Payme / Click the payment page. The switch is `billing.canPurchase` (mode `purchase` AND a
 * non-empty catalog): anything else, including a catalog that failed to load, keeps the `coming_soon` copy.
 *
 * `reason = 'quota'` is the end of today's new words; `'decks'` is a refused deck / deck-word limit
 * (docs/04 A.10; `deckLimits` are the numbers of `GET /decks`, the lead quotes them); `'streak_freeze'` is the Free
 * teaser of streak freezes (docs/13 §7.2); `'exam_simulator'` is a refused IELTS / SAT mock (docs/13 §8.4); `'practice'` is
 * the practice (or a battle) behind Pro while `PRACTICE_PLAN=pro` (docs/16 §3.2; its lead names the battles only where there are battles,
 * `practiceLead`); `'practice_limit'` is the end of the free sessions of practice or of the free battles of today (docs/18 §3; `limitHit`
 * holds the numbers the lead quotes, and the time the counter is full again is told in the learner's zone, the one of the Today screen; a
 * limit of 0 is "only Pro": the lead says so, with no day to wait for - sessions: the practice, and the battles with it only when they are
 * for Pro only too); `'game'` is a run of the Karvon map or a boss behind the Free allowance (docs/18 §3; the lead names no numbers: they are on the stop sheet); `'info'` is
 * the learner opening it from the Today screen or from "Renew Pro". A promo code that works or a confirmed payment
 * refreshes `/me` (billing store) and `/today`.
 *
 * What the sheet says follows the plan from `/me`: the line "you are on Free: N new words a day" is for Free only (Pro
 * has `new_words_per_day: null` = no limit, which is not "unknown"). A Stars subscription in grace (`past_due`) cannot
 * be paid over — the server refuses it — so the pay buttons give way to the reason and the date.
 *
 * A Pro learner comes here to extend (the "Renew Pro" button of the Subscription screen), not to be sold Pro: when
 * their own subscription has an end, the title is "Renew Pro" and the lead says what a payment does — it is added to
 * that end (Payme / Click and a Stars year do exactly that, `CheckoutService::assertCanBuyOver`); the sales pitch and the
 * line for students of a center ("ask your center") are for Free. A Stars month over a period that is paid far ahead is
 * refused by the server (`subscribe_too_early`); the error text says what to choose instead.
 *
 * Under the pay buttons (Free only) a text link says "Or get Pro free - invite friends" and opens the referrals screen.
 *
 * "Repeat words" (shown when the Today store knows of due cards) emits `review`: reviews are never
 * limited on Free, so the quota wall is not a dead end. The parent opens the daily_review session
 * (`useReviewFromPaywall`, the same for every screen that shows the sheet).
 *
 * A refused purchase is shown under the pay buttons and scrolled into view: on a phone the sheet is taller than the
 * screen and the message (it may name a way out, like `subscribe_too_early`) would start below the fold.
 *
 * a11y (minimal): the focus moves into the dialog on open and returns to the opener on close;
 * Escape closes it.
 */
const props = withDefaults(
  defineProps<{
    reason?: PaywallReason
    deckLimits?: DeckLimits | null
    limitHit?: PracticeLimitHit | null
    boosterHit?: GameBoosterLimitHit | null
    /** The period chosen when the sheet opens (a tariff row of the Subscription screen); the first one when absent or not sold. */
    period?: BillingPeriod | null
  }>(),
  {
    reason: 'info',
    deckLimits: null,
    limitHit: null,
    boosterHit: null,
    period: null,
  },
)
const emit = defineEmits<{ close: []; redeemed: [me: MeResponse]; review: [] }>()

const { t, tn, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const billing = useBillingStore()
const today = useTodayStore()

const redeemed = ref(false)
const sheet = ref<HTMLElement | null>(null)
const purchaseError = ref<InstanceType<typeof InlineAlert> | null>(null)

const dueCount = computed(() => today.data?.due_count ?? 0)

const isPro = computed(() => billing.entitlement?.plan === 'pro')

/**
 * What Free gives; nothing for Pro. While Pro is for sale nothing for Free with a known limit either: the row of the new words already
 * reads "no limit instead of 20 a day", and the price has to fit the first screen. The generic line is for a plan that is not known
 * (`/me` has not come).
 */
const freeLine = computed((): string => {
  if (isPro.value) return ''
  const limit = billing.entitlement?.new_words_per_day ?? null
  if (purchaseMode.value && limit !== null && billing.entitlement?.plan === 'free') return ''
  return limit !== null ? tn('paywall.free', limit) : t('paywall.free_generic')
})
const contact = computed(() => supportContactLink(billing.paywall?.contact))

const purchaseMode = computed(() => billing.canPurchase)
const paid = computed(() => billing.purchase.status === 'paid')
const done = computed(() => redeemed.value || paid.value)
const busy = computed(() => billing.purchase.status === 'starting' || billing.purchase.status === 'awaiting')

/** What the reason adds before the sales pitch (none for `info`; `decks` quotes the limits, see `reasonLead`; `practice`, see `practiceLead`). */
const REASON_LEADS: Partial<Record<PaywallReason, StringKey>> = {
  quota: 'paywall.lead_quota',
  streak_freeze: 'paywall.lead_streak_freeze',
  exam_simulator: 'paywall.lead_exam_simulator',
  game: 'paywall.lead_game',
}

/**
 * The plain word on the practice behind Pro: "Practice - word games - and battles with friends is in Pro". The battles are in the sentence
 * only where there are battles at all: with the battles switched off (`practiceAccess.battle` false) it speaks of the practice alone, as the
 * Pro line of the list does - no battles are sold that do not exist.
 */
const practiceLead = computed((): string => t(billing.practiceAccess.battle ? 'paywall.lead_practice' : 'paywall.lead_practice_solo'))

/**
 * The battles are for Pro only: they exist and their limit is 0 (not "all used" - there were none to use). Then, when the sessions of
 * practice are for Pro only too, "practice and battles" is said together; when the battles are Free's (a limit of 1 or more, or none) or
 * are switched off, the sentence is about the practice alone.
 */
const battlesProOnly = computed((): boolean => billing.practiceAccess.battle && billing.limitReached('battles')?.limit === 0)

/**
 * The lead of the end of a free limit, by the kind that is spent: "Free practice for today is over (2 of 2). A new one - tomorrow at 00:00
 * or right away with Pro", or the same about the battles. "Right away with Pro" is said only while Pro is for sale (`purchase`); while it
 * is just coming (`soon`) the lead promises nothing, and the pitch that follows it says "Pro opens soon".
 */
const LIMIT_LEADS: Record<PracticeLimitKind, { purchase: StringKey; soon: StringKey }> = {
  sessions: { purchase: 'paywall.lead_practice_limit_sessions', soon: 'paywall.lead_practice_limit_sessions_soon' },
  battles: { purchase: 'paywall.lead_practice_limit_battles', soon: 'paywall.lead_practice_limit_battles_soon' },
}

const limitLead = computed((): string => {
  const hit = props.limitHit
  // Without the numbers of a refusal there is nothing to quote: the plain word on the practice stands.
  if (hit === null) return practiceLead.value
  // A limit of 0 is "only Pro": nothing was spent and nothing comes back tomorrow, so the lead says what Pro has - no "over", no time.
  if (hit.limit === 0) {
    if (hit.kind === 'battles') return t('paywall.lead_practice_limit_battles_pro')
    return t(battlesProOnly.value ? 'paywall.lead_practice' : 'paywall.lead_practice_solo')
  }
  return t(LIMIT_LEADS[hit.kind][purchaseMode.value ? 'purchase' : 'soon'], {
    limit: hit.limit,
    when: resetWhen(hit.resets_at, locale.value, today.data?.timezone),
  })
})

/** `game` + `boosterHit`: the week's free boosters are used up (docs/05 §5b.5): "(1 of 1). New ones - on 9 November". */
const boosterLead = computed((): string => {
  const hit = props.boosterHit
  if (hit === null) return t('paywall.lead_game')
  return t(purchaseMode.value ? 'paywall.lead_game_boosters' : 'paywall.lead_game_boosters_soon', {
    limit: hit.limit,
    when: formatDate(hit.resets_at, 'dayMonth', locale.value, today.data?.timezone),
  })
})

const reasonLead = computed((): string => {
  if (props.reason === 'game') return boosterLead.value
  if (props.reason === 'practice_limit') return limitLead.value
  if (props.reason === 'practice') return practiceLead.value
  if (props.reason === 'decks') {
    const limits = props.deckLimits
    return limits
      ? t('paywall.lead_decks', { decks: tn('decks.count', limits.decks), words: tn('decks.words_up_to', limits.deck_words) })
      : t('paywall.lead_decks_generic')
  }
  const key = REASON_LEADS[props.reason]
  return key ? t(key) : ''
})

/**
 * The line under the title. While Pro is for sale it is one short reason (the title and the rows of benefits say the rest, and the
 * price has to fit the first screen): for the new words "reviews are always open, Pro lifts the limit" (the Pro mock-up), for the
 * other reasons their own sentence, for `info` nothing. While Pro is only coming the reason is followed by "payment opens later".
 */
const lead = computed(() => {
  if (extendUntil.value !== null) return t('paywall.lead_renew', { date: formatDate(extendUntil.value, 'dayMonthYear', locale.value) })
  if (purchaseMode.value) return props.reason === 'quota' ? t('paywall.lead_quota_short') : reasonLead.value
  const pitch = t('paywall.soon')
  return reasonLead.value ? `${reasonLead.value} ${pitch}` : pitch
})

/** The title says why the sheet is open (the place it was opened from, as the offer placement does). */
const HEADINGS: Record<PaywallReason, StringKey> = {
  quota: 'paywall.heading.quota',
  info: 'paywall.heading.info',
  decks: 'paywall.heading.decks',
  streak_freeze: 'paywall.heading.streak_freeze',
  exam_simulator: 'paywall.heading.exam_simulator',
  practice: 'paywall.heading.practice',
  practice_limit: 'paywall.heading.practice_limit',
  game: 'paywall.heading.game',
}

/**
 * The title of the sheet: the reason it is open; "Renew Pro" for a Pro learner who extends; "What Pro will have" when opened just to
 * read about Pro while it is not for sale; "LexiFlow Pro" for a Pro learner with nothing to extend; the name of the plan once the learner is Pro (`done`).
 */
const title = computed(() => {
  if (done.value) return 'Pro'
  if (extendUntil.value !== null) return t('paywall.title_renew')
  if (props.reason === 'info' && !purchaseMode.value) return t('paywall.title')
  // A Pro learner with nothing to extend (the renewal runs, a perpetual grant, Pro from a center) is not sold anything: the plan's name.
  if (props.reason === 'info' && isPro.value) return t('billing.plan.pro')
  return t(HEADINGS[props.reason])
})

/** The rows of the catalog this build sells, month → quarter → year. */
const items = computed<CatalogItem[]>(() => orderedItems(billing.catalog?.items ?? []))
const periods = computed<BillingPeriod[]>(() => items.value.map((i) => i.period))
const chosenPeriod = ref<BillingPeriod | null>(props.period)
const period = computed<BillingPeriod | null>(() =>
  chosenPeriod.value !== null && periods.value.includes(chosenPeriod.value) ? chosenPeriod.value : (periods.value[0] ?? null),
)
const item = computed(() => items.value.find((i) => i.period === period.value) ?? null)
const offer = computed(() => billing.catalog?.offer ?? null)
/** The discount belongs to one period (`period: null` = any): its chip is shown next to that period only. */
const offerShown = computed(() => offer.value !== null && (offer.value.period === null || offer.value.period === period.value))
/** Stars renew by themselves: paying again would charge twice (the server refuses with checkout_conflict). */
const renewing = computed(() => billing.catalog?.subscription?.auto_renew === true)
/** A perpetual grant leaves nothing to buy (the server refuses with already_perpetual). */
const perpetual = computed(() => billing.catalog?.subscription?.perpetual === true)
/** The renewal charge of Stars failed and Telegram retries it: any new payment is refused (checkout_conflict). */
const grace = computed(() => inStarsGrace(billing.catalog?.subscription))
const graceMessage = computed(() => graceText(billing.catalog?.subscription?.ends_at ?? billing.entitlement?.until ?? null, locale.value))
/**
 * The end of the paid period of a Pro learner's own subscription while a payment can still be added to it: what the
 * sheet is for then. `null` for Free, for a Pro that is only a center's and for the cases with nothing to pay (above).
 */
const extendUntil = computed((): string | null => {
  if (!purchaseMode.value || !isPro.value || renewing.value || perpetual.value || grace.value) return null
  return billing.catalog?.subscription?.ends_at ?? null
})

/** The price a card shows: in the currency of the first provider that prices the period (Stars, then sums). */
function cardPrice(row: CatalogItem): { price: string; listPrice: string | null } | null {
  for (const provider of PROVIDER_ORDER) {
    const currency = PROVIDER_CURRENCY[provider]
    const price = row.prices[currency]
    if (!billing.catalog?.providers.includes(provider) || price === undefined) continue
    const list = row.list_prices[currency]
    return {
      price: formatPrice(price, currency, locale.value),
      listPrice: list !== undefined && list > price ? formatPrice(list, currency, locale.value) : null,
    }
  }
  return null
}

interface PeriodCard {
  period: BillingPeriod
  label: string
  price: string
  listPrice: string | null
  /** How much cheaper than month by month (the server's `saving_pct`, else worked out here); null when it saves nothing. */
  pct: number | null
}

/** One card per period that has a price the learner can pay with; month → quarter → year. */
const periodCards = computed<PeriodCard[]>(() =>
  items.value.flatMap((row) => {
    const shown = cardPrice(row)
    if (!shown) return []
    const pct = periodSavingPct(items.value, row.period)
    return [{ period: row.period, label: t(`billing.period.${row.period}`), ...shown, pct }]
  }),
)

/** Three cards share a narrow row: the saving is said short there ("−13%"), two cards have room for "13% cheaper". */
const savingText = (pct: number | null): string =>
  pct === null ? '' : t(periodCards.value.length > 2 ? 'billing.offer_chip' : 'billing.saving', { pct })

function choosePeriod(value: BillingPeriod): void {
  if (!busy.value) chosenPeriod.value = value
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

/** The pay button is on the sheet (with "Not now" under it). */
const showsPay = computed(
  () => purchaseMode.value && !done.value && !renewing.value && !perpetual.value && !grace.value && primaryOption.value !== null,
)

const paidMessage = computed(() => {
  const until = billing.entitlement?.until
  return until ? t('billing.paid_until', { date: formatDate(until, 'dayMonthYear', locale.value) }) : t('billing.paid_forever')
})

/** "Or get Pro free - invite friends": the sheet gives way to the invite screen (the router is absent in a bare mount of the sheet). */
function openInvite(): void {
  emit('close')
  void router?.push({ name: 'referrals', query: { from: route?.name === 'billing' || route?.name === 'today' ? route.name : undefined } })
}

/** "1 300 Stars" → the number and the currency, drawn on two lines in a narrow card (the number never breaks). */
function splitPrice(price: string): { amount: string; unit: string } {
  const match = /^(.*\S)\s+(\S+)$/u.exec(price)
  return match ? { amount: match[1] ?? price, unit: match[2] ?? '' } : { amount: price, unit: '' }
}

/** The first way to pay is the primary button with the price in it; the others are quieter buttons below. */
const primaryOption = computed((): PayOption | null => options.value[0] ?? null)
const otherOptions = computed((): PayOption[] => options.value.slice(1))

const primaryLabel = computed((): string => {
  const option = primaryOption.value
  if (!option) return ''
  return t(extendUntil.value !== null ? 'billing.pay_cta_renew' : 'billing.pay_cta', { price: option.price })
})

/** The way the learner started paying from this sheet: the line under the button names it while that payment goes on. */
const payingWith = ref<BillingProviderKey | null>(null)

/** "Payment via …": the started payment's provider while it is starting / awaited / timed out, otherwise the primary button's. */
const payMethod = computed((): BillingProviderKey | null => {
  const status = billing.purchase.status
  if (payingWith.value !== null && status !== 'idle' && status !== 'failed') return payingWith.value
  return primaryOption.value?.provider ?? null
})

function pay(provider: BillingProviderKey): void {
  if (period.value && !busy.value) {
    payingWith.value = provider
    void billing.buy(period.value, provider, paywallPlacement(props.reason))
  }
}

function onRedeemed(me: MeResponse): void {
  redeemed.value = true
  billing.apply(me)
  // The numbers on the Today screen (quota, plan badge) change with the plan.
  void today.load()
  emit('redeemed', me)
}

watch(
  () => billing.purchase.status,
  async (status) => {
    if (status !== 'failed') return
    await nextTick()
    purchaseError.value?.$el.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
  },
)

/**
 * The actions stay at the bottom of the sheet (the price button has to be on the first screen at 320 × 568): the content scrolls
 * above them, and a hairline over the actions shows there is more of it below.
 */
const scroller = ref<HTMLElement | null>(null)
const moreBelow = ref(false)

function measure(): void {
  const el = scroller.value
  moreBelow.value = el !== null && el.scrollHeight - el.scrollTop - el.clientHeight > 1
}

let resizeObserver: ResizeObserver | null = null

onMounted(() => {
  measure()
  if (typeof ResizeObserver !== 'undefined' && scroller.value) {
    resizeObserver = new ResizeObserver(measure)
    resizeObserver.observe(scroller.value)
    if (scroller.value.firstElementChild) resizeObserver.observe(scroller.value.firstElementChild)
  }
})
onUpdated(measure)
onBeforeUnmount(() => resizeObserver?.disconnect())

/** The bottom block: the pay button with "Not now", and what the payment is doing (starting, waiting, refused). */
const showsActions = computed(() => purchaseMode.value && !done.value && (showsPay.value || billing.purchase.status !== 'idle'))

useDialogFocus(sheet, () => emit('close'))

onMounted(() => {
  void billing.load().then(() => {
    if (billing.paywall?.mode !== 'purchase') return
    void billing.loadCatalog()
    // The sheet with prices is the offer itself: the first step of the Pro funnel (docs/13 §5.1).
    reportOfferViewed(paywallPlacement(props.reason))
  })
})

onBeforeUnmount(() => {
  // Closing the sheet stops the wait; a payment already made still arrives through the webhook.
  billing.resetPurchase()
})
</script>

<template>
  <div class="scrim fixed inset-0 z-50 flex items-end justify-center" data-testid="paywall-backdrop" @click.self="emit('close')">
    <section
      ref="sheet"
      class="sheet relative flex max-h-[90dvh] flex-col overflow-hidden outline-hidden"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
      data-testid="paywall"
    >
      <!-- A 44 px target around the cross; outside the scrolling part, so it stays in reach. -->
      <button
        type="button"
        class="absolute top-1.5 right-1.5 z-10 flex size-11 items-center justify-center text-muted"
        :aria-label="t('common.close')"
        data-testid="paywall-x"
        @click="emit('close')"
      >
        <AppIcon name="x-mark" :size="18" />
      </button>

      <div ref="scroller" class="-mx-4 min-h-0 flex-1 overflow-y-auto overscroll-contain px-4" data-testid="paywall-scroll" @scroll.passive="measure">
        <div class="pb-4">
          <header class="flex flex-col gap-2.5 pt-3 pr-8 short:gap-1.5 short:pt-1">
            <p class="text-[13px] leading-[18px] text-muted" data-testid="paywall-eyebrow">{{ t('billing.plan.pro') }}</p>
            <h2 class="text-[26px] leading-8 font-semibold tracking-[-0.4px] short:text-[22px] short:leading-7" data-testid="paywall-title">{{ title }}</h2>

            <InlineAlert v-if="paid" tone="success" :message="paidMessage" data-testid="paywall-paid" />

            <template v-if="!done">
              <p v-if="lead" class="text-[15px] leading-[22px] text-muted" data-testid="paywall-lead">{{ lead }}</p>

              <p v-if="freeLine" class="text-[13px] leading-[18px] text-muted" data-testid="paywall-free">{{ freeLine }}</p>
            </template>
          </header>

          <ProBenefits v-if="!done" short compact class="mt-6 short:mt-4" />

          <section v-if="purchaseMode && !done" class="mt-6 space-y-3 short:mt-4" data-testid="purchase">
            <p v-if="offerShown">
              <span class="chip chip-warning h-auto py-1 whitespace-normal" data-testid="purchase-offer">{{ offerText }}</span>
            </p>

            <div
              v-if="periodCards.length > 1"
              class="grid"
              :class="periodCards.length > 2 ? 'grid-cols-3 gap-2' : 'grid-cols-2 gap-2.5'"
              role="radiogroup"
              :aria-label="t('billing.period.label')"
              data-testid="periods"
            >
              <button
                v-for="card in periodCards"
                :key="card.period"
                type="button"
                role="radio"
                :aria-checked="card.period === period"
                :disabled="busy"
                class="flex min-h-11 min-w-0 flex-col gap-1 rounded-card text-left disabled:opacity-50"
                :class="[
                  periodCards.length > 2 ? 'px-2.5 py-3' : 'p-3.5',
                  card.period === period ? 'bg-accent-soft shadow-[inset_0_0_0_1.5px_var(--app-primary)]' : 'border border-hairline bg-surface',
                ]"
                :data-testid="`period-${card.period}`"
                @click="choosePeriod(card.period)"
              >
                <span class="text-[13px] leading-[18px] text-muted">{{ card.label }}</span>
                <span data-testid="period-price">
                  <span
                    class="block font-semibold whitespace-nowrap"
                    :class="periodCards.length > 2 ? 'text-[17px] leading-[22px]' : 'text-[22px] leading-[26px]'"
                  >{{ splitPrice(card.price).amount }}</span> <span class="block text-[13px] leading-[18px] text-muted">{{ splitPrice(card.price).unit }}</span>
                </span>
                <s v-if="card.listPrice" class="text-[13px] leading-[18px] text-muted" data-testid="period-list-price">{{ card.listPrice }}</s>
                <span
                  class="min-h-[18px] text-[13px] leading-[18px]"
                  :class="card.period === period ? 'text-accent-ink' : 'text-muted'"
                  data-testid="period-saving"
                >{{ savingText(card.pct) }}</span>
              </button>
            </div>

            <p v-if="renewing" class="flex items-start gap-2 text-sm leading-5 text-muted" data-testid="purchase-renewing">
              <AppIcon name="arrow-path" :size="18" class="mt-px text-accent" />
              <span>{{ t('billing.auto_renew_running') }}</span>
            </p>

            <p v-else-if="perpetual" class="flex items-start gap-2 text-sm leading-5 text-muted" data-testid="purchase-perpetual">
              <AppIcon name="check-circle" solid :size="18" class="mt-px text-success" />
              <span>{{ t('billing.perpetual') }}</span>
            </p>

            <InlineAlert v-else-if="grace" tone="warning" :message="graceMessage" data-testid="purchase-grace" />

            <div v-else-if="otherOptions.length > 0" class="space-y-2" data-testid="pay-other">
              <p class="text-[13px] leading-[18px] text-muted">{{ t('billing.pay_other') }}</p>
              <button
                v-for="option in otherOptions"
                :key="option.provider"
                type="button"
                class="btn btn-secondary btn-block justify-between"
                :disabled="busy"
                :aria-label="t('billing.pay_with', { provider: t(`billing.provider.${option.provider}`), price: option.price })"
                :data-price="option.price"
                :data-testid="`pay-${option.provider}`"
                @click="pay(option.provider)"
              >
                <span>{{ t(`billing.provider.${option.provider}`) }}</span>
                <span class="flex items-baseline gap-2">
                  <span>{{ option.price }}</span>
                  <s v-if="option.listPrice" class="text-sm font-normal text-muted" data-testid="list-price">{{ option.listPrice }}</s>
                </span>
              </button>
            </div>
          </section>

          <div class="mt-4 space-y-3">
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

            <p v-if="!done && !isPro" class="text-center text-[15px] leading-5">
              <button type="button" class="text-accent underline" data-testid="invite-link" @click="openInvite">
                {{ t('paywall.invite') }}
              </button>
            </p>

            <PromoRedeem @redeemed="onRedeemed" />

            <p v-if="contact && !done && !isPro" class="text-center text-[13px] leading-[18px] text-muted" data-testid="paywall-contact">
              {{ t('paywall.contact') }}
              <a
                v-if="contact.href"
                :href="contact.href"
                target="_blank"
                rel="noopener noreferrer"
                class="break-words text-accent underline"
                data-testid="paywall-contact-link"
              >{{ contact.label }}</a>
              <span v-else class="font-semibold break-words" data-testid="paywall-contact-text">{{ contact.label }}</span>
            </p>

            <!-- "Not now" sits under the price while something can be paid; otherwise it closes the sheet at the end. -->
            <button v-if="!showsPay" type="button" class="btn btn-plain btn-block" data-testid="paywall-close" @click="emit('close')">
              {{ t('paywall.dismiss') }}
            </button>
          </div>
        </div>
      </div>

      <!-- The actions: under the content, never scrolled away; a hairline over them while there is more content below. -->
      <div
        v-if="showsActions"
        class="-mx-4 space-y-1.5 border-t bg-surface px-4 pt-3"
        :class="moreBelow ? 'border-divider' : 'border-transparent'"
        data-testid="paywall-actions"
      >
        <p
          v-if="billing.purchase.status === 'awaiting' && billing.purchase.notice"
          class="flex items-start gap-2 text-sm leading-5 text-muted"
          role="status"
          data-testid="purchase-notice"
        >
          <AppIcon name="information-circle" :size="18" class="mt-px" />
          <span>{{ billing.purchase.notice }}</span>
        </p>
        <p
          v-if="billing.purchase.status === 'starting'"
          class="flex items-start gap-2 text-sm leading-5 text-muted"
          role="status"
          data-testid="purchase-starting"
        >
          <AppIcon name="arrow-path" :size="18" class="mt-px animate-spin motion-reduce:animate-none" />
          <span>{{ t('billing.starting') }}</span>
        </p>
        <p
          v-else-if="billing.purchase.status === 'awaiting'"
          class="flex items-start gap-2 text-sm leading-5 text-muted"
          role="status"
          data-testid="purchase-awaiting"
        >
          <AppIcon name="clock" :size="18" class="mt-px" />
          <span>{{ t('billing.awaiting') }}</span>
        </p>
        <p
          v-else-if="billing.purchase.status === 'replaced'"
          class="flex items-start gap-2 text-sm leading-5 text-muted"
          role="status"
          data-testid="purchase-replaced"
        >
          <AppIcon name="information-circle" :size="18" class="mt-px" />
          <span>{{ billing.purchase.notice }}</span>
        </p>
        <div v-else-if="billing.purchase.status === 'timeout'" class="space-y-2" data-testid="purchase-timeout">
          <p class="flex items-start gap-2 text-sm leading-5 text-muted">
            <AppIcon name="clock" :size="18" class="mt-px text-warning" />
            <span>{{ t('billing.timeout') }}</span>
          </p>
          <button type="button" class="btn btn-tinted btn-block" data-testid="purchase-check" @click="billing.waitForPayment()">
            {{ t('billing.check_again') }}
          </button>
        </div>
        <InlineAlert
          v-else-if="billing.purchase.status === 'failed'"
          ref="purchaseError"
          tone="danger"
          :message="billing.purchase.error"
          data-testid="purchase-error"
        />
        <template v-if="showsPay && primaryOption">
          <button
            type="button"
            class="btn btn-primary btn-lg btn-block"
            :disabled="busy"
            :data-price="primaryOption.price"
            :data-testid="`pay-${primaryOption.provider}`"
            @click="pay(primaryOption.provider)"
          >
            <span>{{ primaryLabel }}</span>
            <s v-if="primaryOption.listPrice" class="ml-2 text-sm font-normal opacity-70" data-testid="list-price">{{ primaryOption.listPrice }}</s>
          </button>
          <button type="button" class="btn btn-plain btn-block" data-testid="paywall-close" @click="emit('close')">
            {{ t('paywall.not_now') }}
          </button>
          <p v-if="payMethod" class="text-center text-xs leading-4 text-muted" data-testid="pay-method">
            {{ t('billing.pay_via', { provider: t(`billing.provider.${payMethod}`) }) }}
          </p>
        </template>
      </div>
    </section>
  </div>
</template>
