<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useTrainingStore } from '@/stores/training'
import { errorMessage } from '@/i18n/errors'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

const { isInTelegram } = useTelegram()
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const training = useTrainingStore()
const { opening, openError } = storeToRefs(training)
const inTelegram = ref(false)

// Minimal entry to the daily review until the "Today" screen (task 1.3b) takes it over.
async function reviewAll(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    training.openError = errorMessage(err, 'training')
    return
  }
  const sessionId = await training.open({ mode: 'daily_review', source: 'self' })
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
}

onMounted(() => {
  inTelegram.value = isInTelegram()
})

onBeforeUnmount(() => training.clearOpenError())
</script>

<template>
  <section class="flex-1 flex items-center justify-center p-6">
    <div class="max-w-sm text-center space-y-4">
      <h1 class="text-2xl font-bold">LexiFlow</h1>
      <p v-if="inTelegram" class="text-tg-hint">
        {{ t('landing.in_telegram') }}
      </p>
      <RouterLink
        v-if="inTelegram"
        :to="{ name: 'courses' }"
        class="btn btn-secondary w-full"
        data-testid="catalog-link"
      >
        {{ t('catalog.open') }}
      </RouterLink>
      <button
        v-if="inTelegram"
        type="button"
        class="btn btn-primary w-full"
        :disabled="opening"
        data-testid="review-all-btn"
        @click="reviewAll"
      >
        {{ t('landing.review_all') }}
      </button>
      <p v-if="openError" class="text-sm text-red-600" data-testid="open-error">{{ openError }}</p>
      <p v-else-if="!inTelegram" class="text-tg-hint">
        {{ t('landing.outside_telegram') }}
      </p>
    </div>
  </section>
</template>
