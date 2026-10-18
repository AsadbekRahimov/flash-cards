<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import FlashCard from '@/components/FlashCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useTrainingStore } from '@/stores/training'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { Quality } from '@/api/types'

const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const training = useTrainingStore()
const { close, showBackButton } = useTelegram()
const { t } = useI18n()

const { status, card, progress, lesson, errorMessage } = storeToRefs(training)

const isBusy = computed(() => status.value === 'submitting' || status.value === 'loading')
const showSpinner = computed(() =>
  status.value === 'starting' || (status.value === 'loading' && card.value === null),
)

let hideBack: (() => void) | null = null

onMounted(async () => {
  hideBack = showBackButton(() => close())
  try {
    await auth.ensure({ type: 'training', sessionId: props.sessionId })
    await training.start(props.sessionId)
    if (training.status === 'finished') {
      void redirectToFinished()
    }
  } catch (err) {
    // start() handles its own errors; this is an auth failure.
    training.handleApiError(err)
  }
})

onBeforeUnmount(() => {
  hideBack?.()
})

async function onGrade(q: Quality): Promise<void> {
  await training.submitReview(q)
  if (training.status === 'finished') {
    await redirectToFinished()
  }
}

async function redirectToFinished(): Promise<void> {
  await router.replace({
    name: 'finished',
    query: {
      reviewed: String(training.reviewedCount),
      next: String(training.lastIntervalDays),
    },
  })
}

function onRetry(): void {
  void training.loadNext()
}
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <header class="flex items-center justify-between mb-4">
      <div class="text-sm text-tg-hint" data-testid="progress">
        {{ progress.done }} / {{ progress.total }}
      </div>
      <div v-if="lesson" class="text-sm font-semibold">
        {{ t('training.lesson_header', { stage: lesson.stage ?? '—', lesson: lesson.lesson }) }}
      </div>
      <button
        type="button"
        class="text-tg-hint"
        :aria-label="t('common.close')"
        @click="close()"
      >
        ✕
      </button>
    </header>

    <div v-if="showSpinner" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="status === 'closed'" class="flex-1 flex items-center justify-center text-center" data-testid="closed">
      <div class="space-y-4">
        <p class="text-lg font-semibold">{{ t('training.closed_title') }}</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" data-testid="close-btn" @click="close()">{{ t('common.close') }}</button>
      </div>
    </div>

    <div v-else-if="status === 'error'" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="onRetry">{{ t('common.retry') }}</button>
      </div>
    </div>

    <FlashCard
      v-else-if="card"
      :card="card"
      :show-translation="training.showTranslation"
      :disabled="isBusy"
      @reveal="training.reveal()"
      @grade="onGrade"
    />
  </section>
</template>
