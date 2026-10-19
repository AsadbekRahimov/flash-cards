<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import PromoRedeem from '@/components/PromoRedeem.vue'
import { supportContactLink } from '@/composables/supportContact'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { MeResponse } from '@/api/types'

/**
 * Paywall of Phase 1 (docs/12 §5.4, mode `coming_soon`): "Pro is coming", what Free gives, a promo
 * code field and — only when the owner configured one — the support contact for centers.
 *
 * `reason = 'quota'` is the end of today's new words; `'info'` is the learner opening it from the
 * Today screen. A promo code that works refreshes `/me` (billing store) and `/today`.
 *
 * "Repeat words" (shown when the Today store knows of due cards) emits `review`: reviews are never
 * limited on Free, so the quota wall is not a dead end. The parent opens the daily_review session.
 *
 * TODO(phase 2): `billing.paywall.mode === 'purchase'` — plans and the buy button (docs/12 §5.4).
 * Until then the sheet always shows the `coming_soon` copy, whatever the mode is.
 *
 * a11y (minimal): the focus moves into the dialog on open and returns to the opener on close;
 * Escape closes it.
 */
const props = withDefaults(defineProps<{ reason?: 'quota' | 'info' }>(), { reason: 'info' })
const emit = defineEmits<{ close: []; redeemed: [me: MeResponse]; review: [] }>()

const { t } = useI18n()
const billing = useBillingStore()
const today = useTodayStore()

const redeemed = ref(false)
const sheet = ref<HTMLElement | null>(null)
let opener: HTMLElement | null = null

const dueCount = computed(() => today.data?.due_count ?? 0)

const limit = computed(() => billing.entitlement?.new_words_per_day ?? null)
const contact = computed(() => supportContactLink(billing.paywall?.contact))

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
  void billing.load()
  opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
  document.addEventListener('keydown', onKeydown)
  void nextTick(() => sheet.value?.focus())
})

onBeforeUnmount(() => {
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
      :aria-label="t('paywall.title')"
      data-testid="paywall"
    >
      <h2 class="text-xl font-bold text-center" data-testid="paywall-title">{{ redeemed ? 'Pro' : t('paywall.title') }}</h2>

      <template v-if="!redeemed">
        <p v-if="props.reason === 'quota'" class="text-center" data-testid="paywall-lead">
          {{ t('paywall.lead_quota') }} {{ t('paywall.soon') }}
        </p>
        <p v-else class="text-center" data-testid="paywall-lead">{{ t('paywall.soon') }}</p>

        <p class="text-sm text-tg-hint text-center" data-testid="paywall-free">
          {{ limit !== null ? t('paywall.free', { count: limit }) : t('paywall.free_generic') }}
        </p>
      </template>

      <button
        v-if="!redeemed && dueCount > 0"
        type="button"
        class="btn btn-primary w-full"
        data-testid="paywall-review"
        @click="emit('review')"
      >
        {{ t('paywall.review') }}
      </button>

      <PromoRedeem @redeemed="onRedeemed" />

      <p v-if="contact && !redeemed" class="text-sm text-center" data-testid="paywall-contact">
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
