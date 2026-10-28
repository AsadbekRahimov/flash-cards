<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { formatDate } from '@/composables/format'
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

const dateText = computed(() => (lapsed.value ? formatDate(lapsed.value.ended_at, 'dayMonth', locale.value) : ''))

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
  <section v-if="visible" class="card space-y-3" role="status" data-testid="lapsed-banner">
    <div class="flex items-start gap-3">
      <span class="icon-tile size-10 rounded-xl bg-warning"><AppIcon name="clock" :size="22" /></span>
      <div class="min-w-0 flex-1">
        <p class="text-[17px] leading-[22px] font-semibold" data-testid="lapsed-title">{{ t('lapsed.title') }}</p>
        <p class="mt-0.5 text-[15px] leading-5 text-tg-subtitle" data-testid="lapsed-text">
          {{ t('lapsed.text', { date: dateText }) }}
        </p>
      </div>
      <!-- 44 px target; the negative margins keep the glyph in the corner of the card padding. -->
      <button
        type="button"
        class="-mt-3 -mr-3 flex size-11 shrink-0 items-center justify-center rounded-full text-tg-hint active:bg-track"
        :aria-label="t('lapsed.dismiss')"
        data-testid="lapsed-dismiss"
        @click="dismiss()"
      >
        <AppIcon name="x-mark" :size="20" />
      </button>
    </div>
    <button type="button" class="btn btn-primary btn-block" data-testid="lapsed-renew" @click="emit('renew')">
      {{ t('lapsed.renew') }}
    </button>
  </section>
</template>
