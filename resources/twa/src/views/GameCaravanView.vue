<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute } from 'vue-router'
import PaywallSheet from '@/components/PaywallSheet.vue'
import GameItemImage from '@/components/game/GameItemImage.vue'
import ShopItemCard from '@/components/game/ShopItemCard.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedControl, { type SegmentOption } from '@/components/ui/SegmentedControl.vue'
import { formatDate } from '@/composables/format'
import { showErrorToast } from '@/composables/errorToast'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useTodayStore } from '@/stores/today'
import { useGameShopStore } from '@/stores/gameShop'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { GameItemSlot, GameShopItem } from '@/api/gameShopTypes'

/**
 * "My caravan" (docs/05 §5b.5, docs/18 §12.9): what the learner wears, then two tabs - the wallet (coins, tickets, the last entries of the
 * ledger) and the shop (camels, carpets, tents, frames, boosters). A purchase asks first ("Buy for N?") and goes out with a `request_id`
 * that a repeat keeps; wearing is optimistic and goes back when the server refuses. The paywall of the game opens for a Pro item.
 *
 * States: loading, "not available yet" (`game_unavailable`), error (Retry), the screen. Telegram Back leads to the map.
 */
const SLOTS: readonly GameItemSlot[] = ['camel', 'carpet', 'tent', 'frame']
const CATEGORIES = ['camel', 'carpet', 'tent', 'frame', 'booster'] as const
type Category = (typeof CATEGORIES)[number]

const auth = useAuthStore()
const route = useRoute()
const today = useTodayStore()
const store = useGameShopStore()
const { haptic, notificationHaptic } = useTelegram()
const { t, locale } = useI18n()

const { wallet, shop, profile, status, error, buying } = storeToRefs(store)

// Back: to the profile when the learner came from it (`?from=profile`), else to the map of the course the map put in the address (`?course=`; the
// map reads the active course when there was none). The address, not the store of the map: that one is reset when the map is left.
const fromProfile = route.query.from === 'profile'
const backCourse = typeof route.query.course === 'string' && route.query.course !== '' ? route.query.course : null
useBackButton(fromProfile ? { name: 'profile' } : { name: 'game', ...(backCourse ? { query: { course: backCourse } } : {}) })

let left = false

const tab = ref<'wallet' | 'shop'>('wallet')
const category = ref<Category>('camel')

const view = computed((): 'loading' | 'unavailable' | 'error' | 'ready' => {
  if (status.value === 'unavailable') return 'unavailable'
  if (status.value === 'error' && wallet.value === null) return 'error'
  if (wallet.value === null || shop.value === null || profile.value === null) return 'loading'
  return 'ready'
})

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch {
    // the reading below tells the failure
  }
  if (!left) await store.load()
}

onMounted(() => void load())
onBeforeUnmount(() => {
  left = true
})

const tabOptions = computed((): SegmentOption[] => [
  { value: 'wallet', label: t('gameCaravan.tab.wallet'), testid: 'tab-wallet' },
  { value: 'shop', label: t('gameCaravan.tab.shop'), testid: 'tab-shop' },
])

// ---- what is worn ----

const worn = computed(() =>
  SLOTS.map((slot) => ({ slot, label: t(`gameCaravan.slot.${slot}` as StringKey), item: profile.value?.equipped[slot] ?? null })),
)

// ---- the wallet ----

const entries = computed(() =>
  (wallet.value?.ledger ?? []).map((entry, index) => ({
    ...entry,
    key: `${index}-${entry.created_at}`,
    amountText: t(entry.amount >= 0 ? 'gameCaravan.wallet.plus' : 'gameCaravan.wallet.minus', { count: Math.abs(entry.amount) }),
    date: formatDate(entry.created_at, 'dayMonthShortTime', locale.value, today.data?.timezone),
  })),
)

// ---- the shop ----

const categoryOptions = computed(() => CATEGORIES.map((value) => ({ value, label: t(`gameCaravan.cat.${value}` as StringKey) })))

const inCategory = computed((): GameShopItem[] =>
  store.items.filter((item) => (category.value === 'booster' ? item.kind === 'booster' : item.slot === category.value)),
)

// ---- wearing ----

async function onEquip(item: GameShopItem): Promise<void> {
  if (item.slot === null) return
  haptic('light')
  const result = await store.equip(item.slot, item.key)
  if (!result.ok && result.error.code !== 'busy') showErrorToast(result.error.message)
}

// ---- the paywall of the game ----

const paywallOpen = ref(false)
const { reviewFromPaywall } = useReviewFromPaywall(() => undefined, { toastErrors: true })

// ---- buying ----

const confirming = ref<GameShopItem | null>(null)
/** The refusal that the sheet itself tells (not enough coins). */
const sheetError = ref('')
const bought = ref('')

function onBuy(item: GameShopItem): void {
  haptic('light')
  sheetError.value = ''
  confirming.value = item
}

function closeSheet(): void {
  confirming.value = null
  sheetError.value = ''
}

async function confirmBuy(): Promise<void> {
  const item = confirming.value
  if (item === null) return
  const result = await store.buy(item.key)
  if (left) return
  if (result.ok) {
    notificationHaptic('success')
    bought.value = t('gameCaravan.buy.done', { title: item.title })
    closeSheet()
    // The button that was pressed is gone (the card of a bought cosmetic has "Wear" now): the focus goes to the new one, not to the page.
    if (item.kind === 'cosmetic') {
      await nextTick()
      document.querySelector<HTMLElement>(`[data-testid="item-${item.key}"] [data-testid="item-equip"]`)?.focus()
    }
    return
  }
  const { code, details, message } = result.error
  if (code === 'busy') return
  if (code === 'coins_insufficient') {
    sheetError.value = t('gameCaravan.buy.coins_insufficient')
    return
  }
  if (code === 'item_not_purchasable') {
    closeSheet()
    const reason = details?.reason
    if (reason === 'pro') paywallOpen.value = true
    else showErrorToast(reason === 'achievement' || reason === 'not_for_sale' ? t(`gameCaravan.buy.not_purchasable.${reason}` as StringKey) : message)
    return
  }
  if (code === 'item_already_owned' || code === 'item_not_found') closeSheet()
  showErrorToast(message)
}

const shortOfCoins = computed(() => confirming.value !== null && store.balance < confirming.value.price)
</script>

<template>
  <div class="page" data-testid="game-caravan-view">
    <PageHeader :title="t('gameCaravan.title')" />

    <!-- Always there, so that a purchase is announced. -->
    <p class="sr-only" role="status" aria-live="polite" data-testid="bought-live">{{ bought }}</p>

    <LoadingState v-if="view === 'loading'" variant="cards" />

    <EmptyState v-else-if="view === 'unavailable'" icon="lock-closed" :title="t('gameCaravan.unavailable_title')" :text="t('gameCaravan.unavailable_text')" data-testid="unavailable" />

    <ErrorState v-else-if="view === 'error'" :message="error" @retry="load" />

    <template v-else>
      <section class="card mb-4" :aria-label="t('gameCaravan.profile.title')" data-testid="profile">
        <h2 class="section-label !mt-0 !px-0">{{ t('gameCaravan.profile.title') }}</h2>
        <ul class="grid grid-cols-4 gap-2">
          <li v-for="slot in worn" :key="slot.slot" class="flex min-w-0 flex-col items-center gap-1 text-center" :data-testid="`worn-${slot.slot}`">
            <span class="flex size-14 items-center justify-center rounded-xl bg-fill">
              <GameItemImage v-if="slot.item" :name="slot.item.asset" :size="44" />
            </span>
            <span class="w-full truncate text-[12px] leading-4 text-tg-subtitle">{{ slot.label }}</span>
            <span v-if="!slot.item" class="sr-only">{{ t('gameCaravan.profile.none') }}</span>
          </li>
        </ul>
        <p class="mt-3 text-[13px] leading-[18px] text-tg-subtitle" data-testid="profile-stats">
          {{ t('gameCaravan.profile.stops', { count: profile?.stops_cleared ?? 0 }) }} · {{ t('gameCaravan.profile.cities', { count: profile?.cities_cleared ?? 0 }) }}
        </p>
      </section>

      <SegmentedControl :model-value="tab" class="mb-4" :options="tabOptions" :ariaLabel="t('gameCaravan.tabs_aria')" @update:model-value="tab = $event === 'shop' ? 'shop' : 'wallet'" />

      <section v-if="tab === 'wallet' && wallet" data-testid="wallet">
        <div class="card flex flex-col gap-3">
          <div>
            <p class="text-[13px] leading-[18px] font-semibold tracking-wide text-tg-subtitle uppercase">{{ t('gameCaravan.wallet.balance') }}</p>
            <p class="text-[34px] leading-10 font-bold tabular-nums" data-testid="wallet-balance">{{ wallet.balance }}</p>
          </div>
          <dl class="grid grid-cols-2 gap-3 text-[15px] leading-5">
            <div>
              <dt class="text-[13px] leading-[18px] text-tg-subtitle">{{ t('gameCaravan.wallet.tickets') }}</dt>
              <dd class="font-semibold tabular-nums" data-testid="wallet-tickets">{{ t('gameCaravan.wallet.tickets_value', { balance: wallet.tickets.balance, max: wallet.tickets.max }) }}</dd>
            </div>
            <div>
              <dt class="text-[13px] leading-[18px] text-tg-subtitle">{{ t('gameCaravan.wallet.earned') }} / {{ t('gameCaravan.wallet.spent') }}</dt>
              <dd class="font-semibold tabular-nums" data-testid="wallet-totals">{{ wallet.earned_total }} / {{ wallet.spent_total }}</dd>
            </div>
          </dl>
          <p class="text-[13px] leading-[18px] text-tg-hint">{{ t('gameCaravan.wallet.hint') }}</p>
        </div>

        <h2 class="section-label">{{ t('gameCaravan.wallet.ledger') }}</h2>
        <p v-if="entries.length === 0" class="px-4 text-[15px] leading-5 text-tg-hint" data-testid="ledger-empty">{{ t('gameCaravan.wallet.ledger_empty') }}</p>
        <ul v-else class="list" data-testid="ledger">
          <li v-for="entry in entries" :key="entry.key" class="list-row flex min-h-11 items-center justify-between gap-3" data-testid="ledger-entry">
            <span class="min-w-0">
              <span class="block truncate text-[15px] leading-5" data-testid="ledger-title">{{ entry.title }}</span>
              <span class="block text-[13px] leading-[18px] text-tg-subtitle">{{ entry.date }}</span>
            </span>
            <span class="shrink-0 text-[15px] leading-5 font-semibold tabular-nums" :class="entry.amount >= 0 ? 'text-success-ink' : 'text-tg-text'" data-testid="ledger-amount">{{ entry.amountText }}</span>
          </li>
        </ul>
      </section>

      <section v-else-if="tab === 'shop'" data-testid="shop">
        <div class="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4" role="tablist" :aria-label="t('gameCaravan.cats_aria')" data-testid="categories">
          <button
            v-for="option in categoryOptions"
            :key="option.value"
            type="button"
            role="tab"
            :aria-selected="option.value === category"
            class="min-h-11 shrink-0 rounded-xl px-4 text-[15px] leading-5 font-semibold"
            :class="option.value === category ? 'bg-accent text-tg-button-text' : 'bg-surface text-tg-text'"
            :data-testid="`cat-${option.value}`"
            @click="category = option.value"
          >
            {{ option.label }}
          </button>
        </div>

        <p v-if="inCategory.length === 0" class="px-4 py-6 text-center text-[15px] leading-5 text-tg-hint" data-testid="cat-empty">{{ t('gameCaravan.cat_empty') }}</p>
        <ul v-else class="flex flex-col gap-3" data-testid="items">
          <ShopItemCard
            v-for="item in inCategory"
            :key="item.key"
            :item="item"
            :busy="buying !== null || store.equipping"
            @buy="onBuy"
            @equip="onEquip"
            @pro="paywallOpen = true"
          />
        </ul>
      </section>
    </template>

    <BottomSheet v-if="confirming" :label="t('gameCaravan.buy.title', { price: confirming.price })" @close="closeSheet">
      <div class="flex flex-col gap-3 px-4 pt-2 pb-4" data-testid="buy-sheet">
        <div class="flex items-center gap-3">
          <GameItemImage :name="confirming.asset" :size="64" />
          <div class="min-w-0">
            <p class="text-[15px] leading-5 font-semibold">{{ confirming.title }}</p>
            <h2 class="text-[22px] leading-7 font-bold">{{ t('gameCaravan.buy.title', { price: confirming.price }) }}</h2>
          </div>
        </div>
        <p class="text-[15px] leading-5 text-tg-subtitle tabular-nums" data-testid="buy-balance">{{ t('gameCaravan.buy.balance', { balance: store.balance }) }}</p>
        <InlineAlert v-if="sheetError || shortOfCoins" tone="warning" :message="t('gameCaravan.buy.coins_insufficient')" data-testid="buy-short" />
        <button type="button" class="btn btn-primary btn-block" :disabled="buying !== null" data-testid="buy-confirm" @click="confirmBuy">{{ t('gameCaravan.buy.confirm') }}</button>
        <button type="button" class="btn btn-plain btn-block" data-testid="buy-cancel" @click="closeSheet">{{ t('gameCaravan.buy.cancel') }}</button>
      </div>
    </BottomSheet>

    <PaywallSheet v-if="paywallOpen" reason="game" @close="paywallOpen = false" @review="reviewFromPaywall" />
  </div>
</template>
