<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import { formatNumber } from '@/composables/format'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * The result of an exam (docs/14 §6.4): a flag, the title, the card with the learner's result and — for a group exam —
 * the leaderboard as a grouped list; a personal mock shows the percent and the comparison instead. The buttons are in the
 * sticky block at the bottom.
 */
const props = defineProps<{ sessionId: number }>()

const router = useRouter()
const auth = useAuthStore()
const exam = useExamStore()
const { close, notificationHaptic } = useTelegram()
const { t, locale } = useI18n()

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

/** The podium gets medals (content, not an icon of a control); the rest of the table gets the plain place. */
function place(rank: number): string {
  return rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : String(rank)
}
</script>

<template>
  <section
    class="mx-auto flex w-full max-w-xl flex-1 flex-col"
    style="min-height: var(--tg-viewport-stable-height, 100dvh)"
    data-testid="exam-result-view"
  >
    <div class="flex flex-1 flex-col items-center gap-4 px-4 pt-8 pb-4 text-center">
      <span class="flex size-[72px] shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent">
        <AppIcon name="flag" solid :size="36" />
      </span>
      <h1 class="text-[22px] leading-7 font-bold" data-testid="result-title">
        {{ isMock ? t('result.mock_title') : t('result.title') }}
      </h1>

      <p
        v-if="errorMessage"
        class="w-full rounded-xl bg-danger-soft px-4 py-3 text-left text-[15px] leading-5 text-danger-ink"
        role="alert"
        data-testid="result-error"
      >
        {{ errorMessage }}
      </p>

      <section v-if="isMock && mockResult" class="card w-full px-5 py-6" data-testid="mock-result">
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('result.your_score') }}</p>
        <p class="mt-1 text-5xl leading-[52px] font-bold tabular-nums" data-testid="mock-percent">{{ mockResult.percent }}%</p>
        <p class="mt-1 text-[15px] leading-5 text-tg-subtitle" data-testid="mock-score">
          {{ t('result.mock_score', { correct: mockResult.correct, total: mockResult.total, percent: mockResult.percent }) }}
        </p>
        <p v-if="unanswered > 0" class="mt-1 text-[15px] leading-5 text-warning-ink" data-testid="mock-unanswered">
          {{ t('result.mock_unanswered', { count: unanswered }) }}
        </p>
        <hr class="my-4 border-hairline" />
        <p class="text-[15px] leading-5" data-testid="mock-percentile">
          <template v-if="mockResult.percentile !== null && mockResult.attempts > 1">
            {{ t('result.mock_percentile', { percentile: mockResult.percentile }) }}
          </template>
          <template v-else>
            {{ t('result.mock_first') }}
          </template>
        </p>
        <p v-if="mockResult.attempts > 1" class="mt-1 text-[13px] leading-[18px] text-tg-hint" data-testid="mock-attempts">
          {{ t('result.mock_attempts', { count: mockResult.attempts }) }}
        </p>
      </section>

      <section v-else class="card w-full px-5 py-6" data-testid="exam-result">
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('result.your_score') }}</p>
        <p class="mt-1 flex items-center justify-center gap-2 text-5xl leading-[52px] font-bold tabular-nums">
          <span data-testid="final-score">{{ formatNumber(finalScore, locale) }}</span>
          <AppIcon name="star" solid :size="32" class="text-warning" />
        </p>
        <span class="chip chip-accent mt-3" data-testid="final-rank">
          <template v-if="finalRank !== null">
            {{ t('result.rank', { rank: finalRank, total: totalParticipants }) }}
          </template>
          <template v-else>
            {{ t('result.pending') }}
          </template>
        </span>
      </section>

      <ListGroup v-if="!isMock && leaderboard.length > 0" class="w-full text-left" :header="t('result.top')" data-testid="leaderboard">
        <ListRow v-for="row in leaderboard" :key="row.rank" :title="row.name" :data-testid="`leader-${row.rank}`">
          <template #leading>
            <span
              class="w-[30px] shrink-0 text-center"
              :class="row.rank <= 3 ? 'text-2xl leading-7' : 'text-base leading-[22px] font-medium text-tg-hint tabular-nums'"
            >
              {{ place(row.rank) }}
            </span>
          </template>
          <template #trailing>
            <span class="shrink-0 text-base font-semibold tabular-nums">{{ formatNumber(row.score, locale) }}</span>
          </template>
        </ListRow>
      </ListGroup>
    </div>

    <StickyActions>
      <button
        v-if="isMock"
        type="button"
        class="btn btn-tinted btn-lg btn-block"
        data-testid="mock-courses-btn"
        @click="router.push({ name: 'courses' })"
      >
        {{ t('result.mock_again') }}
      </button>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="close-btn" @click="close()">
        {{ t('common.close') }}
      </button>
    </StickyActions>
  </section>
</template>
