<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { Lapsed } from '@/api/types'
import AppIcon from '@/components/ui/AppIcon.vue'
import { formatDate } from '@/composables/format'
import { reportOfferViewed } from '@/composables/offerViewed'
import { LAPSED_DISMISSED_KEY } from '@/composables/proReminders'
import { useBillingStore } from '@/stores/billing'
import { useI18n } from '@/i18n'

/**
 * "Pro ended — renew" (docs/13 §4.4, 2A.6): shown to a Free learner whose own paid subscription
 * expired lately (the server's `entitlement.lapsed`). The dismissal
 * is remembered on the device per `ended_at`, so a later lapse shows the banner again.
 *
 * Today passes `lapsed` (including null). BillingView already loaded `/me` and passes `preloaded`.
 * With an older Today response, the banner loads `/me` itself and waits for fresh data. A visible banner whose `/me` is not known yet
 * asks for it in the background only to report the offer (below), never to decide whether it shows.
 *
 * An ended welcome trial (`lapsed.provider === 'trial'`, docs/04 A.2) has its own words: "Trial Pro ended: buy Pro or invite
 * friends" and a second button `invite` (the parent opens the "Invite a friend" screen; the banner knows no router).
 *
 * `renewable` (default true): there is something to buy. A screen that knows nothing is for sale (BillingView while
 * payment is not open) sets it to false: the banner still says Pro ended, but has no "Renew" button that does nothing.
 */
const props = withDefaults(defineProps<{ lapsed?: Lapsed | null; preloaded?: boolean; renewable?: boolean }>(), { preloaded: false, renewable: true })
const emit = defineEmits<{ renew: []; invite: [] }>()

const DISMISSED_KEY = LAPSED_DISMISSED_KEY

const billing = useBillingStore()
const { t, locale } = useI18n()

function readDismissed(): string | null {
  try {
    return window.localStorage.getItem(DISMISSED_KEY)
  } catch {
    return null
  }
}

const ready = ref(props.preloaded === true)
const dismissedAt = ref(readDismissed())

const lapsed = computed(() => props.lapsed !== undefined
  ? props.lapsed
  : (billing.entitlement?.plan === 'free' ? (billing.entitlement.lapsed ?? null) : null))
const visible = computed(() => (props.lapsed !== undefined || ready.value) && lapsed.value !== null && lapsed.value.ended_at !== dismissedAt.value)

const isTrial = computed(() => lapsed.value?.provider === 'trial')
const dateText = computed(() => (lapsed.value ? formatDate(lapsed.value.ended_at, 'dayMonth', locale.value) : ''))

/** `/me` was asked for the report below once in this banner's life (a failure is not asked again: the next open will). */
let meRequested = false

/**
 * A banner with its "Renew" button offers Pro (docs/13 §5.1): reported once `/me` says Pro is for sale (`paywall.mode` purchase), which
 * is why the mode is part of the source - the paywall may come after the banner. On Today the banner lives on `/today` alone and nothing
 * else loads `/me` on a cold open of a Free learner, so the banner asks for it itself, once, in the background (the store shares a request
 * in flight and keeps a fresh answer; `load()` never throws): nothing waits for it and nothing is shown of a failure.
 */
watch(() => [visible.value && props.renewable, billing.paywall?.mode === 'purchase'] as const, ([on, forSale]) => {
  if (!on) return
  if (forSale) {
    reportOfferViewed('lapsed_banner')
    return
  }
  if (billing.paywall === null && !meRequested) {
    meRequested = true
    billing.load().catch(() => {
      // analytics only
    })
  }
}, { immediate: true })

function dismiss(): void {
  if (!lapsed.value) return
  dismissedAt.value = lapsed.value.ended_at
  try {
    window.localStorage.setItem(DISMISSED_KEY, lapsed.value.ended_at)
  } catch {
    // private mode: the banner simply comes back on the next visit
  }
}

onMounted(async () => {
  if (props.lapsed !== undefined || props.preloaded) return
  await billing.load()
  ready.value = true
})
</script>

<template>
  <!-- A calm card of the kit (radius lg, no shadow): a status dot instead of a bright tile, the action under the text. -->
  <section v-if="visible" class="card flex flex-col gap-3" role="status" data-testid="lapsed-banner">
    <div class="flex items-start gap-3">
      <span class="mt-[7px] size-2 shrink-0 rounded-full bg-warning" aria-hidden="true" data-testid="lapsed-dot" />
      <div class="min-w-0 flex-1">
        <p class="t-body-m-strong" data-testid="lapsed-title">{{ t(isTrial ? 'lapsed.trial.title' : 'lapsed.title') }}</p>
        <p class="t-label-s mt-0.5 text-muted" data-testid="lapsed-text">
          {{ isTrial ? t('lapsed.trial.text') : t('lapsed.text', { date: dateText }) }}
        </p>
      </div>
      <!-- 44 px target; the negative margins keep the glyph in the corner of the card padding. -->
      <button
        type="button"
        class="-mt-3 -mr-3 flex size-11 shrink-0 items-center justify-center rounded-full text-muted active:bg-fill"
        :aria-label="t('lapsed.dismiss')"
        data-testid="lapsed-dismiss"
        @click="dismiss()"
      >
        <AppIcon name="x-mark" :size="20" />
      </button>
    </div>
    <!-- Tinted, not primary: the screens around it (Home, Subscription) have their own one primary button. -->
    <button v-if="renewable" type="button" class="btn btn-tinted btn-block" data-testid="lapsed-renew" @click="emit('renew')">
      {{ t(isTrial ? 'lapsed.trial.renew' : 'lapsed.renew') }}
    </button>
    <button v-if="isTrial" type="button" class="btn btn-plain btn-block" data-testid="lapsed-invite" @click="emit('invite')">
      {{ t('lapsed.invite') }}
    </button>
  </section>
</template>
