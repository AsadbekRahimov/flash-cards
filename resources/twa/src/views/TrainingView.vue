<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import FlashCard from '@/components/FlashCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useTrainingStore } from '@/stores/training'
import { useTelegram } from '@/composables/useTelegram'
import type { Quality } from '@/api/types'

const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const training = useTrainingStore()
const { close, showBackButton } = useTelegram()

const { status, card, progress, lesson, errorMessage } = storeToRefs(training)

const isBusy = computed(() => status.value === 'submitting' || status.value === 'loading')
const showSpinner = computed(() =>
  status.value === 'starting' || (status.value === 'loading' && card.value === null),
)

let hideBack: (() => void) | null = null

onMounted(async () => {
  hideBack = showBackButton(() => close())
  try {
    await auth.ensure()
    await training.start(props.sessionId)
    if (training.status === 'finished') {
      void redirectToFinished()
    }
  } catch {
    // auth failure → training.status stays 'idle'; render landing message
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
        Stage {{ lesson.stage ?? '—' }} · Lesson {{ lesson.lesson }}
      </div>
      <button
        type="button"
        class="text-tg-hint"
        aria-label="Закрыть"
        @click="close()"
      >
        ✕
      </button>
    </header>

    <div v-if="showSpinner" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">Загрузка…</p>
    </div>

    <div v-else-if="status === 'closed'" class="flex-1 flex items-center justify-center text-center" data-testid="closed">
      <div class="space-y-4">
        <p class="text-lg font-semibold">🔒 Тренировка завершена учителем</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" @click="close()">Закрыть</button>
      </div>
    </div>

    <div v-else-if="status === 'error'" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ Ошибка</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" @click="onRetry">Повторить</button>
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
