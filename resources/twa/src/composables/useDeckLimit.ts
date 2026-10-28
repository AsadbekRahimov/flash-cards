import { computed, onBeforeUnmount, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useBillingStore } from '@/stores/billing'
import { useDecksStore } from '@/stores/decks'
import { useI18n } from '@/i18n'

/**
 * A write refused by the plan limit of decks (422 `deck_limit_reached` / `deck_words_limit_reached`, docs/04 A.10),
 * told apart by the plan: Free is shown the paywall, Pro has nothing to buy — the paywall would sell them what they
 * already have — and is told that this is the limit of their plan, with the numbers the server applies and what to do.
 *
 * Call it once in `setup()` of a screen that writes decks (My decks, Deck):
 * - `paywall` — open the `PaywallSheet` (Free, or a plan that is not known yet: the sheet loads `/me` itself and its
 *   texts follow the plan);
 * - `proNotice` — the explanation for Pro, '' otherwise (and while the numbers are not at hand: the screen then
 *   shows its general refusal text).
 *
 * It asks `/me` on mount (a young answer is reused, `ME_FRESH_MS`), so the plan is known by the time of a refusal, and
 * drops the refusal when the screen is left: it must not greet the learner on the other deck screen.
 *
 * @example
 * const { paywall, proNotice } = useDeckLimit()
 * // <PaywallSheet v-if="paywall" reason="decks" :deck-limits="limits" @close="store.dismissLimit()" />
 */
export function useDeckLimit() {
  const store = useDecksStore()
  const billing = useBillingStore()
  const { limitReached, limitKind, limits } = storeToRefs(store)
  const { t, tn } = useI18n()

  const isPro = computed(() => billing.entitlement?.plan === 'pro')

  const paywall = computed(() => limitReached.value && !isPro.value)

  const proNotice = computed((): string => {
    const current = limits.value
    if (!limitReached.value || !isPro.value || current === null) return ''

    return limitKind.value === 'deck_words'
      ? t('decks.limit_pro.words', { words: tn('decks.words_up_to', current.deck_words) })
      : t('decks.limit_pro.decks', { decks: tn('decks.count', current.decks) })
  })

  onMounted(() => {
    void billing.load()
  })

  onBeforeUnmount(() => {
    store.dismissLimit()
  })

  return { paywall, proNotice }
}
