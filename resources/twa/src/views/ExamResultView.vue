<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import { formatDate, formatNumber } from '@/composables/format'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useTodayStore } from '@/stores/today'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * The result of an exam (docs/14 §6.4), in the look of the end of a lesson (kit Muhrla 06-urok-proyden): the mascot (the trophy of a group
 * exam, celebrating after a mock or a test of the week), "Your result" above the title in Display/L, the numbers in one card of columns
 * split by 1 px lines (Numeric/L, a caption under each), a line under it — the comparison of a mock, whether a test of the week was
 * recorded — and, for a group exam, the leaderboard as a grouped list. The buttons are in the sticky block at the bottom. A link straight to
 * the result shows a skeleton of the numbers until they come; a failure is the alert above them.
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

/**
 * A link straight to the result (the tab was closed, the bot's message): the store has nothing of this exam yet, so the numbers are a
 * skeleton until `loadResults` answers. Coming from the exam screen the store already holds them: no skeleton.
 */
const pending = ref(exam.sessionId !== props.sessionId)

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
  } finally {
    pending.value = false
  }
})

</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    data-testid="exam-result-view"
  >
    <div class="flex flex-1 flex-col px-5 pt-6 pb-4 short:pt-3">
      <!-- Kit Muhrla 06-urok-proyden: the hero on the central axis - the mascot, "Your result" and the title in Display/L. -->
      <header class="flex flex-col items-center gap-1.5 text-center">
        <MascotArt :pose="isMock || isQuiz ? 'celebrate' : 'trophy'" :height="112" class="mb-2 short:hidden" />
        <p class="t-label-s text-muted" data-testid="result-eyebrow">{{ t('result.your_score') }}</p>
        <h1 class="t-display-l" data-testid="result-title">
          {{ isQuiz ? t('result.quiz_title') : isMock ? t('result.mock_title') : t('result.title') }}
        </h1>
      </header>

      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" class="mt-5" data-testid="result-error" />

      <!-- A link straight to the result (the store knows nothing yet): the skeleton of the numbers until they come. -->
      <div v-if="pending" class="mt-6 flex flex-col gap-3" role="status" aria-busy="true" data-testid="spinner">
        <span class="sr-only">{{ t('common.loading') }}</span>
        <span class="skeleton block h-[86px] rounded-lg" aria-hidden="true" />
        <span class="skeleton block h-12 rounded-lg" aria-hidden="true" />
      </div>

      <section v-else-if="isQuiz && quizResult" class="mt-6" data-testid="quiz-result">
        <div class="flex rounded-lg border border-hairline bg-surface">
          <div class="flex min-w-0 flex-1 flex-col items-center gap-1 px-2 py-4 text-center" data-testid="quiz-score">
            <span class="t-numeric-l" data-testid="stat-value">{{ quizResult.correct }}</span>
            <span class="t-label-s text-muted" data-testid="stat-label">{{ t('result.stat.correct', { total: quizResult.total }) }}</span>
          </div>
          <div class="my-4 flex min-w-0 flex-1 flex-col items-center gap-1 border-l border-divider px-2 text-center" data-testid="quiz-percent">
            <span class="t-numeric-l" data-testid="stat-value">{{ quizResult.percent }}%</span>
            <span class="t-label-s text-muted" data-testid="stat-label">{{ t('result.stat.percent') }}</span>
          </div>
          <div class="my-4 flex min-w-0 flex-1 flex-col items-center gap-1 border-l border-divider px-2 text-center" data-testid="quiz-unanswered">
            <span class="t-numeric-l" data-testid="stat-value">{{ quizUnanswered }}</span>
            <span class="t-label-s text-muted" data-testid="stat-label">{{ t('result.stat.unanswered') }}</span>
          </div>
        </div>
        <p class="t-body-m mt-3 flex items-center justify-center gap-2 text-center text-muted">
          <AppIcon :name="quizResult.scored ? 'check-circle' : 'information-circle'" :size="18" class="shrink-0" :class="quizResult.scored ? 'text-success' : 'text-muted'" />
          <span :data-testid="quizResult.scored ? 'quiz-scored' : 'quiz-practice'">
            {{ quizResult.scored ? t('result.quiz_scored', { week: quizWeek }) : t('result.quiz_practice') }}
          </span>
        </p>
      </section>

      <section v-else-if="isMock && mockResult" class="mt-6" data-testid="mock-result">
        <div class="flex rounded-lg border border-hairline bg-surface">
          <div class="flex min-w-0 flex-1 flex-col items-center gap-1 px-2 py-4 text-center" data-testid="mock-percent">
            <span class="t-numeric-l" data-testid="stat-value">{{ mockResult.percent }}%</span>
            <span class="t-label-s text-muted" data-testid="stat-label">{{ t('result.stat.percent') }}</span>
          </div>
          <div class="my-4 flex min-w-0 flex-1 flex-col items-center gap-1 border-l border-divider px-2 text-center" data-testid="mock-score">
            <span class="t-numeric-l" data-testid="stat-value">{{ mockResult.correct }}</span>
            <span class="t-label-s text-muted" data-testid="stat-label">{{ t('result.stat.correct', { total: mockResult.total }) }}</span>
          </div>
          <div class="my-4 flex min-w-0 flex-1 flex-col items-center gap-1 border-l border-divider px-2 text-center" data-testid="mock-unanswered">
            <span class="t-numeric-l" data-testid="stat-value">{{ unanswered }}</span>
            <span class="t-label-s text-muted" data-testid="stat-label">{{ t('result.stat.unanswered') }}</span>
          </div>
        </div>
        <div class="mt-3 text-center">
          <p class="t-body-m" data-testid="mock-percentile">
            <template v-if="mockResult.percentile !== null && mockResult.attempts > 1">
              {{ t('result.mock_percentile', { percentile: mockResult.percentile }) }}
            </template>
            <template v-else>
              {{ t('result.mock_first') }}
            </template>
          </p>
          <p v-if="mockResult.attempts > 1" class="t-label-s mt-0.5 text-muted" data-testid="mock-attempts">
            {{ t('result.mock_attempts', { count: mockResult.attempts }) }}
          </p>
        </div>
      </section>

      <section v-else class="mt-6" data-testid="exam-result">
        <div class="flex rounded-lg border border-hairline bg-surface">
          <div class="flex min-w-0 flex-1 flex-col items-center gap-1 px-2 py-4 text-center">
            <span class="t-numeric-l" data-testid="final-score">{{ formatNumber(finalScore, locale) }}</span>
            <span class="t-label-s text-muted">{{ t('result.stat.score') }}</span>
          </div>
          <div class="my-4 flex min-w-0 flex-1 flex-col items-center justify-center gap-1 border-l border-divider px-2 text-center" data-testid="final-rank">
            <template v-if="finalRank !== null">
              <span class="t-numeric-l" data-testid="stat-value">{{ finalRank }}</span>
              <span class="t-label-s text-muted" data-testid="stat-label">{{ t('result.stat.place', { total: totalParticipants }) }}</span>
            </template>
            <span v-else class="t-label-s text-muted" data-testid="stat-label">{{ t('result.pending') }}</span>
          </div>
        </div>
      </section>

      <ListGroup v-if="!pending && !isMock && !isQuiz && leaderboard.length > 0" class="mt-2" :header="t('result.top')" data-testid="leaderboard">
        <!-- Equal scores share a place (1, 2, 2, 4), and the API names no learner: a row is told by its position in the list. -->
        <ListRow v-for="(row, index) in leaderboard" :key="index" :title="row.name" :data-testid="`leader-${index + 1}`">
          <template #leading>
            <span class="w-6 shrink-0 text-center font-mono text-[13px] leading-[18px] text-muted" data-testid="leader-place">{{ row.rank }}</span>
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
        class="btn btn-primary btn-block"
        data-testid="quiz-readiness-btn"
        @click="toReadiness"
      >
        {{ t('result.to_readiness') }}
        <AppIcon name="arrow-right" :size="20" />
      </button>
      <button type="button" class="btn btn-block" :class="isQuiz ? 'btn-plain' : 'btn-primary'" data-testid="close-btn" @click="close()">
        {{ t('common.exit_app') }}
      </button>
      <button
        v-if="isMock"
        type="button"
        class="btn btn-secondary btn-block"
        data-testid="mock-courses-btn"
        @click="router.push({ name: 'courses' })"
      >
        {{ t('result.mock_again') }}
      </button>
    </StickyActions>
  </section>
</template>
