<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'

const { t, locale } = useI18n()

const open = ref(false)
const code = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso))
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
    success.value = until ? t('promo.success_until', { date: formatDate(until) }) : t('promo.success_forever')
    code.value = ''
  } catch (err) {
    error.value = errorMessage(err, 'training')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-3 text-left" data-testid="promo">
    <button v-if="!open && !success" class="text-tg-link underline w-full text-center" data-testid="promo-toggle" @click="open = true">
      {{ t('promo.toggle') }}
    </button>

    <form v-else-if="!success" class="flex gap-2" @submit.prevent="submit">
      <input
        v-model="code"
        type="text"
        maxlength="64"
        autocomplete="off"
        autocapitalize="characters"
        class="flex-1 rounded-xl px-3 py-3 uppercase"
        :placeholder="t('promo.placeholder')"
        data-testid="promo-input"
      />
      <button type="submit" class="btn btn-primary" :disabled="busy || code.trim() === ''" data-testid="promo-submit">
        {{ t('promo.submit') }}
      </button>
    </form>

    <p v-if="error" class="text-red-600 text-sm" role="alert" data-testid="promo-error">{{ error }}</p>
    <p v-if="success" class="font-semibold text-center" data-testid="promo-success">{{ success }}</p>
  </div>
</template>
