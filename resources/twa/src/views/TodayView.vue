<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import GoalRing from '@/components/GoalRing.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import ProgressBar from '@/components/ProgressBar.vue'
import { useAuthStore } from '@/stores/auth'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { OpenSessionRequest } from '@/api/types'

const { t, tn } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const today = useTodayStore()
const training = useTrainingStore()
const { data, status, errorMessage: loadError } = storeToRefs(today)
const { opening, openError } = storeToRefs(training)

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    today.status = 'error'
    today.errorMessage = errorMessage(err, 'today')
    return
  }
  await today.load()
}

/** Opens a personal session and goes to the training screen; a failure stays on this screen. */
async function openSession(body: OpenSessionRequest): Promise<void> {
  const sessionId = await training.open(body)
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
}

const review = (): Promise<void> => openSession({ mode: 'daily_review', source: 'self' })

function learnNext(lessonId: number): Promise<void> {
  return openSession({ mode: 'lesson', lesson_id: lessonId, source: 'self' })
}

const paywallOpen = ref(false)

const goalLeft = computed(() => (data.value ? Math.max(0, data.value.goal.target - data.value.goal.done) : 0))
const streak = computed(() => data.value?.streak ?? null)
const lessonLabel = computed(() => {
  const lesson = data.value?.active_course?.next_lesson
  if (!lesson) return ''
  const number = t('course.lesson', { number: lesson.lesson })
  return lesson.title ? `${number} · ${lesson.title}` : number
})

onMounted(() => {
  void load()
})

// An open error belongs to this screen only; it must not greet the learner on the next one.
onBeforeUnmount(() => training.clearOpenError())
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <div v-if="status === 'idle' || status === 'loading'" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="!data" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ loadError }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <div v-else class="space-y-4">
      <header class="flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ t('today.title') }}</h1>
        <span
          class="rounded-full px-3 py-1 text-xs font-semibold"
          :class="data.entitlement.plan === 'pro' ? 'bg-tg-button text-tg-button-text' : 'bg-black/10'"
          data-testid="plan-badge"
        >
          {{ t(data.entitlement.plan === 'pro' ? 'today.plan.pro' : 'today.plan.free') }}
        </span>
      </header>

      <p v-if="status === 'error'" class="text-sm text-red-600" data-testid="reload-error">{{ loadError }}</p>
      <p v-if="!data.onboarding_completed" class="card text-sm" data-testid="onboarding-hint">
        {{ t('today.onboarding_hint') }}
      </p>

      <div class="card flex items-center gap-5" data-testid="goal-card">
        <GoalRing :done="data.goal.done" :target="data.goal.target" :met="data.goal.met" />
        <div class="space-y-1">
          <div class="font-semibold">{{ t('today.goal.title') }}</div>
          <div class="text-sm text-tg-hint" data-testid="goal-text">
            {{ t('today.goal.progress', { done: data.goal.done, target: data.goal.target }) }}
          </div>
          <div v-if="data.goal.met" class="text-sm font-semibold" data-testid="goal-met">{{ t('today.goal.met') }}</div>
          <div v-else class="text-sm" data-testid="goal-left">{{ tn('today.goal.left', goalLeft) }}</div>
        </div>
      </div>

      <div v-if="streak" class="card flex items-center gap-4" data-testid="streak-card">
        <div class="text-4xl" :class="streak.current > 0 ? '' : 'grayscale opacity-60'" aria-hidden="true">🔥</div>
        <div class="space-y-1">
          <div class="font-semibold" data-testid="streak-current">
            <template v-if="streak.current > 0">{{ tn('today.streak.days', streak.current) }}</template>
            <template v-else>{{ t('today.streak.title') }}</template>
          </div>
          <div v-if="streak.at_risk" class="text-sm text-red-600" data-testid="streak-at-risk">{{ t('today.streak.at_risk') }}</div>
          <div v-else-if="streak.current > 0 && streak.met_today" class="text-sm text-tg-hint" data-testid="streak-safe">
            {{ t('today.streak.safe') }}
          </div>
          <div v-else-if="streak.current === 0" class="text-sm text-tg-hint" data-testid="streak-none">{{ t('today.streak.none') }}</div>
          <div v-if="streak.best > streak.current" class="text-xs text-tg-hint" data-testid="streak-best">
            {{ t('today.streak.best', { best: tn('today.streak.days', streak.best) }) }}
          </div>
        </div>
      </div>

      <div class="card space-y-3" data-testid="review-card">
        <div class="font-semibold">{{ t('today.review.title') }}</div>
        <div class="text-sm text-tg-hint" data-testid="due-count">
          {{ data.due_count > 0 ? tn('today.review.due', data.due_count) : t('today.review.none') }}
        </div>
        <div v-if="data.new_words_remaining !== null" class="text-sm text-tg-hint" data-testid="new-remaining">
          {{ data.new_words_remaining > 0 ? t('today.new.remaining', { count: data.new_words_remaining }) : t('today.new.exhausted') }}
        </div>
        <button
          v-if="data.entitlement.plan === 'free'"
          type="button"
          class="text-tg-link underline text-sm"
          data-testid="paywall-open"
          @click="paywallOpen = true"
        >
          {{ t('paywall.open') }}
        </button>
        <button type="button" class="btn btn-primary w-full" :disabled="opening" data-testid="review-btn" @click="review">
          {{ data.open_session_id !== null ? t('today.review.continue') : t('today.review.start') }}
        </button>
      </div>

      <div class="card space-y-3" data-testid="course-card">
        <template v-if="data.active_course">
          <div>
            <div class="text-xs text-tg-hint">{{ t('today.course.title') }}</div>
            <div class="font-semibold" data-testid="course-title">{{ data.active_course.title }}</div>
          </div>
          <ProgressBar :done="data.active_course.progress.learned" :total="data.active_course.progress.total" />
          <div class="text-sm text-tg-hint" data-testid="course-progress">
            {{ t('catalog.progress', { done: data.active_course.progress.learned, total: data.active_course.progress.total }) }}
          </div>
          <div v-if="data.active_course.next_lesson" class="space-y-2">
            <div class="text-sm">
              <span class="text-tg-hint">{{ t('today.course.next') }}: </span>
              <span class="font-semibold" data-testid="next-lesson">{{ lessonLabel }}</span>
            </div>
            <button
              type="button"
              class="btn btn-secondary w-full"
              :disabled="opening"
              data-testid="next-lesson-btn"
              @click="learnNext(data.active_course.next_lesson.id)"
            >
              {{ t('course.learn') }}
            </button>
          </div>
          <p v-else class="text-sm text-tg-hint" data-testid="course-completed">{{ t('today.course.completed') }}</p>
        </template>
        <p v-else class="text-sm" data-testid="no-course">{{ t('today.course.none') }}</p>
      </div>

      <p v-if="openError" class="text-sm text-red-600" data-testid="open-error">{{ openError }}</p>

      <RouterLink :to="{ name: 'courses' }" class="btn btn-secondary w-full" data-testid="catalog-link">
        {{ t('catalog.open') }}
      </RouterLink>
      <RouterLink :to="{ name: 'settings' }" class="btn btn-secondary w-full" data-testid="settings-link">
        ⚙️ {{ t('settings.title') }}
      </RouterLink>
    </div>

    <PaywallSheet v-if="paywallOpen" :reason="data?.new_words_remaining === 0 ? 'quota' : 'info'" @close="paywallOpen = false" />
  </section>
</template>
