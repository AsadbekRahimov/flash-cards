<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import StickyActions from '@/components/StickyActions.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import { formatDate, formatNumber } from '@/composables/format'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useTodayStore } from '@/stores/today'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * The result of an exam (docs/14 §6.4), in the calm look of the end of a session (docs/design Finish.html): "Your result"
 * above the title, the numbers in one card split by thin lines (monospace numbers, a caption under each), the rest as rows of a
 * second card — the comparison of a mock, whether a test of the week was recorded — and, for a group exam, the leaderboard as a
 * grouped list. No celebration: no flag, no medals. The buttons are in the sticky block at the bottom.
 */
const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const exam = useExamStore()
const today = useTodayStore()
const { close, notificationHaptic } = useTelegram()
const { t, locale } = useI18n()

const { finalScore, finalRank, totalParticipants, leaderboard, errorMessage, mockResult, quizResult } = storeToRefs(exam)

// A personal IELTS / SAT mock (docs/13 §8.4) ends with percent + percentile instead of a leaderboard.
const isMock = computed((): boolean => mockResult.value !== null)
const unanswered = computed((): number => (mockResult.value ? mockResult.value.total - mockResult.value.answered : 0))

// The test of the week (docs/04 A.20): "N of M", whether it was recorded, and the way back to the readiness screen.
const isQuiz = computed((): boolean => quizResult.value !== null)
const quizUnanswered = computed((): number => (quizResult.value ? quizResult.value.total - quizResult.value.answered : 0))
const quizWeek = computed((): string => (quizResult.value ? formatDate(quizResult.value.week_start, 'dayMonthShort', locale.value) : ''))

/** Back to readiness: Home's block and the screen both read the numbers afresh (a scored test changed them). */
async function toReadiness(): Promise<void> {
  const result = quizResult.value
  if (result === null) return
  if (result.scored) void today.load()
  await router.push({ name: 'readiness', params: { slug: result.course_slug }, query: { source: 'today' } })
}

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

</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    data-testid="exam-result-view"
  >
    <div class="flex flex-1 flex-col px-5 pt-12 pb-4 short:pt-6">
      <p class="text-[13px] leading-[18px] text-muted" data-testid="result-eyebrow">{{ t('result.your_score') }}</p>
      <h1 class="mt-2 text-[32px] leading-[38px] font-semibold tracking-[-0.4px]" data-testid="result-title">
        {{ isQuiz ? t('result.quiz_title') : isMock ? t('result.mock_title') : t('result.title') }}
      </h1>

      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" class="mt-5" data-testid="result-error" />

      <section v-if="isQuiz && quizResult" class="mt-7" data-testid="quiz-result">
        <div class="grid grid-cols-3 rounded-card border border-hairline bg-surface">
          <div class="flex flex-col gap-1 px-3.5 py-4" data-testid="quiz-score">
            <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="stat-value">{{ quizResult.correct }}</span>
            <span class="text-[13px] leading-[18px] text-muted" data-testid="stat-label">{{ t('result.stat.correct', { total: quizResult.total }) }}</span>
          </div>
          <div class="flex flex-col gap-1 border-l border-divider px-3.5 py-4" data-testid="quiz-percent">
            <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="stat-value">{{ quizResult.percent }}%</span>
            <span class="text-[13px] leading-[18px] text-muted" data-testid="stat-label">{{ t('result.stat.percent') }}</span>
          </div>
          <div class="flex flex-col gap-1 border-l border-divider px-3.5 py-4" data-testid="quiz-unanswered">
            <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="stat-value">{{ quizUnanswered }}</span>
            <span class="text-[13px] leading-[18px] text-muted" data-testid="stat-label">{{ t('result.stat.unanswered') }}</span>
          </div>
        </div>
        <div class="mt-3.5 rounded-card border border-hairline bg-surface px-[18px] py-3.5">
          <p class="text-[15px] leading-5" :data-testid="quizResult.scored ? 'quiz-scored' : 'quiz-practice'">
            {{ quizResult.scored ? t('result.quiz_scored', { week: quizWeek }) : t('result.quiz_practice') }}
          </p>
        </div>
      </section>

      <section v-else-if="isMock && mockResult" class="mt-7" data-testid="mock-result">
        <div class="grid grid-cols-3 rounded-card border border-hairline bg-surface">
          <div class="flex flex-col gap-1 px-3.5 py-4" data-testid="mock-percent">
            <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="stat-value">{{ mockResult.percent }}%</span>
            <span class="text-[13px] leading-[18px] text-muted" data-testid="stat-label">{{ t('result.stat.percent') }}</span>
          </div>
          <div class="flex flex-col gap-1 border-l border-divider px-3.5 py-4" data-testid="mock-score">
            <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="stat-value">{{ mockResult.correct }}</span>
            <span class="text-[13px] leading-[18px] text-muted" data-testid="stat-label">{{ t('result.stat.correct', { total: mockResult.total }) }}</span>
          </div>
          <div class="flex flex-col gap-1 border-l border-divider px-3.5 py-4" data-testid="mock-unanswered">
            <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="stat-value">{{ unanswered }}</span>
            <span class="text-[13px] leading-[18px] text-muted" data-testid="stat-label">{{ t('result.stat.unanswered') }}</span>
          </div>
        </div>
        <div class="mt-3.5 rounded-card border border-hairline bg-surface px-[18px] py-3.5">
          <p class="text-[15px] leading-5" data-testid="mock-percentile">
            <template v-if="mockResult.percentile !== null && mockResult.attempts > 1">
              {{ t('result.mock_percentile', { percentile: mockResult.percentile }) }}
            </template>
            <template v-else>
              {{ t('result.mock_first') }}
            </template>
          </p>
          <p v-if="mockResult.attempts > 1" class="mt-0.5 text-[13px] leading-[18px] text-muted" data-testid="mock-attempts">
            {{ t('result.mock_attempts', { count: mockResult.attempts }) }}
          </p>
        </div>
      </section>

      <section v-else class="mt-7" data-testid="exam-result">
        <div class="grid grid-cols-2 rounded-card border border-hairline bg-surface">
          <div class="flex flex-col gap-1 px-3.5 py-4">
            <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="final-score">{{ formatNumber(finalScore, locale) }}</span>
            <span class="text-[13px] leading-[18px] text-muted">{{ t('result.stat.score') }}</span>
          </div>
          <div class="flex flex-col gap-1 border-l border-divider px-3.5 py-4" data-testid="final-rank">
            <template v-if="finalRank !== null">
              <span class="font-mono text-[26px] leading-[30px] font-medium" data-testid="stat-value">{{ finalRank }}</span>
              <span class="text-[13px] leading-[18px] text-muted" data-testid="stat-label">{{ t('result.stat.place', { total: totalParticipants }) }}</span>
            </template>
            <span v-else class="text-[15px] leading-5 text-muted" data-testid="stat-label">{{ t('result.pending') }}</span>
          </div>
        </div>
      </section>

      <ListGroup v-if="!isMock && !isQuiz && leaderboard.length > 0" class="mt-2" :header="t('result.top')" data-testid="leaderboard">
        <!-- Equal scores share a place (1, 2, 2, 4), and the API names no learner: a row is told by its position in the list. -->
        <ListRow v-for="(row, index) in leaderboard" :key="index" :title="row.name" :data-testid="`leader-${index + 1}`">
          <template #leading>
            <span class="w-6 shrink-0 font-mono text-[13px] leading-[18px] text-muted" data-testid="leader-place">{{ row.rank }}</span>
          </template>
          <template #trailing>
            <span class="shrink-0 font-mono text-[15px] leading-5 font-medium">{{ formatNumber(row.score, locale) }}</span>
          </template>
        </ListRow>
      </ListGroup>
    </div>

    <StickyActions>
      <button
        v-if="isQuiz"
        type="button"
        class="btn btn-primary btn-lg btn-block"
        data-testid="quiz-readiness-btn"
        @click="toReadiness"
      >
        {{ t('result.to_readiness') }}
      </button>
      <button type="button" class="btn btn-lg btn-block" :class="isQuiz ? 'btn-plain' : 'btn-primary'" data-testid="close-btn" @click="close()">
        {{ t('common.exit_app') }}
      </button>
      <button
        v-if="isMock"
        type="button"
        class="btn btn-plain btn-block"
        data-testid="mock-courses-btn"
        @click="router.push({ name: 'courses' })"
      >
        {{ t('result.mock_again') }}
      </button>
    </StickyActions>
  </section>
</template>
