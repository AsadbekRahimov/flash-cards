<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useBillingStore } from '@/stores/billing'
import { useI18n } from '@/i18n'

/**
 * "Pro ended — renew" (docs/13 §4.4, 2A.6): shown to a Free learner whose own paid subscription
 * expired lately (`/me.entitlement.lapsed`, a server decision — nothing is derived here). The dismissal
 * is remembered on the device per `ended_at`, so a later lapse shows the banner again.
 *
 * `preloaded`: the screen has already loaded `/me` into the billing store (BillingView); otherwise the
 * banner asks for it once on mount and stays hidden until the answer is in, so a stale store never flashes.
 */
const props = defineProps<{ preloaded?: boolean }>()
const emit = defineEmits<{ renew: [] }>()

const DISMISSED_KEY = 'lexiflow:lapsed-dismissed'

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

const lapsed = computed(() => (billing.entitlement?.plan === 'free' ? (billing.entitlement.lapsed ?? null) : null))
const visible = computed(() => ready.value && lapsed.value !== null && lapsed.value.ended_at !== dismissedAt.value)

const dateText = computed(() =>
  lapsed.value
    ? new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long' }).format(new Date(lapsed.value.ended_at))
    : '',
)

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
  if (props.preloaded) return
  await billing.load()
  ready.value = true
})
</script>

<template>
  <div v-if="visible" class="card space-y-3" role="status" data-testid="lapsed-banner">
    <div class="flex items-start justify-between gap-3">
      <div class="space-y-1">
        <p class="font-semibold" data-testid="lapsed-title">{{ t('lapsed.title') }}</p>
        <p class="text-sm text-tg-hint" data-testid="lapsed-text">{{ t('lapsed.text', { date: dateText }) }}</p>
      </div>
      <button type="button" class="text-tg-hint text-lg leading-none" :aria-label="t('lapsed.dismiss')" data-testid="lapsed-dismiss" @click="dismiss()">
        ×
      </button>
    </div>
    <button type="button" class="btn btn-primary w-full" data-testid="lapsed-renew" @click="emit('renew')">
      {{ t('lapsed.renew') }}
    </button>
  </div>
</template>
