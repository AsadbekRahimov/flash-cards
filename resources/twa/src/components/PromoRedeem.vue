<script setup lang="ts">
import { nextTick, ref } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/api'
import { formatDate } from '@/composables/format'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { MeResponse } from '@/api/types'

/**
 * Promo code (docs/14 §6.6): a plain "Have a promo code?" button that opens a field with an "Activate" button.
 * It lives on the Subscription screen and in the paywall. A code that works replaces the field with a success line
 * and `redeemed` carries the fresh `/me` — the parent puts it into the billing store (`billing.apply`).
 */
const emit = defineEmits<{ redeemed: [me: MeResponse] }>()

const { t, locale } = useI18n()

const open = ref(false)
const code = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const input = ref<HTMLInputElement | null>(null)

function expand(): void {
  open.value = true
  // The learner asked to type a code: the keyboard comes up with the field.
  void nextTick(() => input.value?.focus())
}

async function submit(): Promise<void> {
  const value = code.value.trim()
  if (!value || busy.value) return

  busy.value = true
  error.value = null
  success.value = null
  try {
    const me = await api.promo.redeem(value)
    const until = me.entitlement.until
    success.value = until
      ? t('promo.success_until', { date: formatDate(until, 'dayMonthYear', locale.value) })
      : t('promo.success_forever')
    code.value = ''
    emit('redeemed', me)
  } catch (err) {
    error.value = errorMessage(err, 'training')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-2" data-testid="promo">
    <button v-if="!open && !success" type="button" class="btn btn-plain btn-block" data-testid="promo-toggle" @click="expand">
      {{ t('promo.toggle') }}
    </button>

    <form v-else-if="!success" class="flex items-stretch gap-2" @submit.prevent="submit">
      <input
        ref="input"
        v-model="code"
        type="text"
        maxlength="64"
        autocomplete="off"
        autocapitalize="characters"
        spellcheck="false"
        class="field min-w-0 flex-1 uppercase"
        :aria-label="t('promo.placeholder')"
        :placeholder="t('promo.placeholder')"
        data-testid="promo-input"
      />
      <button type="submit" class="btn btn-primary shrink-0" :disabled="busy || code.trim() === ''" data-testid="promo-submit">
        {{ t('promo.submit') }}
      </button>
    </form>

    <p v-if="error" class="flex items-start gap-2 text-sm leading-5 text-danger-ink" role="alert" data-testid="promo-error">
      <AppIcon name="exclamation-circle" :size="18" class="mt-0.5" />
      <span>{{ error }}</span>
    </p>
    <p v-if="success" class="flex items-start gap-2 text-[15px] leading-5 font-semibold" role="status" data-testid="promo-success">
      <AppIcon name="check-circle" solid :size="20" class="text-success" />
      <span>{{ success }}</span>
    </p>
  </div>
</template>
