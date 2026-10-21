<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const exam = useExamStore()
const { close, notificationHaptic } = useTelegram()
const { t } = useI18n()

const { finalScore, finalRank, totalParticipants, leaderboard, errorMessage, mockResult } = storeToRefs(exam)

// A personal IELTS / SAT mock (docs/13 §8.4) ends with percent + percentile instead of a leaderboard.
const isMock = computed((): boolean => mockResult.value !== null)
const unanswered = computed((): number => (mockResult.value ? mockResult.value.total - mockResult.value.answered : 0))

onMounted(async () => {
  try {
    await auth.ensure({ type: 'exam', sessionId: props.sessionId })
    // Only refetch if we landed on /exam/:id/result directly (e.g. deep link
    // after the tab was closed). When coming from ExamView the store is
    // already populated.
    if (exam.sessionId !== props.sessionId) {
      exam.resetForSession(props.sessionId)
    }
    await exam.loadResults()
    notificationHaptic('success')
  } catch (err) {
    // loadResults handles its own errors; this is an auth failure.
    exam.handleApiError(err)
  }
})

function medal(rank: number): string {
  return rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : `${rank}.`
}
</script>

<template>
  <section class="flex-1 flex items-center justify-center p-4 w-full">
    <div class="max-w-sm w-full space-y-5 text-center">
      <div class="text-5xl">🏁</div>
      <h1 class="text-2xl font-bold" data-testid="result-title">{{ isMock ? t('result.mock_title') : t('result.title') }}</h1>

      <div v-if="errorMessage" class="card text-left" data-testid="result-error">
        <p class="text-sm text-tg-hint">{{ errorMessage }}</p>
      </div>

      <div v-if="isMock && mockResult" class="card" data-testid="mock-result">
        <div class="text-sm text-tg-hint">{{ t('result.your_score') }}</div>
        <div class="text-3xl font-bold mt-1" data-testid="mock-percent">{{ mockResult.percent }}%</div>
        <div class="text-sm text-tg-hint mt-1" data-testid="mock-score">
          {{ t('result.mock_score', { correct: mockResult.correct, total: mockResult.total, percent: mockResult.percent }) }}
        </div>
        <div v-if="unanswered > 0" class="text-sm text-tg-hint" data-testid="mock-unanswered">
          {{ t('result.mock_unanswered', { count: unanswered }) }}
        </div>
        <div class="text-sm mt-3" data-testid="mock-percentile">
          <template v-if="mockResult.percentile !== null && mockResult.attempts > 1">
            {{ t('result.mock_percentile', { percentile: mockResult.percentile }) }}
          </template>
          <template v-else>
            {{ t('result.mock_first') }}
          </template>
        </div>
        <div v-if="mockResult.attempts > 1" class="text-xs text-tg-hint mt-1" data-testid="mock-attempts">
          {{ t('result.mock_attempts', { count: mockResult.attempts }) }}
        </div>
      </div>

      <div v-else class="card">
        <div class="text-sm text-tg-hint">{{ t('result.your_score') }}</div>
        <div class="text-3xl font-bold mt-1" data-testid="final-score">{{ finalScore }} ★</div>
        <div class="text-sm text-tg-hint mt-1" data-testid="final-rank">
          <template v-if="finalRank !== null">
            {{ t('result.rank', { rank: finalRank, total: totalParticipants }) }}
          </template>
          <template v-else>
            {{ t('result.pending') }}
          </template>
        </div>
      </div>

      <div v-if="!isMock && leaderboard.length > 0" class="card text-left" data-testid="leaderboard">
        <div class="text-sm text-tg-hint mb-2">{{ t('result.top') }}</div>
        <ul class="space-y-1">
          <li
            v-for="row in leaderboard"
            :key="row.rank"
            class="flex justify-between items-center"
            :data-testid="`leader-${row.rank}`"
          >
            <span>
              <span class="mr-2">{{ medal(row.rank) }}</span>
              <span class="font-medium">{{ row.name }}</span>
            </span>
            <span class="font-semibold">{{ row.score }}</span>
          </li>
        </ul>
      </div>

      <button
        v-if="isMock"
        class="btn btn-secondary w-full"
        data-testid="mock-courses-btn"
        @click="router.push({ name: 'courses' })"
      >
        {{ t('result.mock_again') }}
      </button>

      <button class="btn btn-primary w-full" data-testid="close-btn" @click="close()">
        {{ t('common.close') }}
      </button>
    </div>
  </section>
</template>
