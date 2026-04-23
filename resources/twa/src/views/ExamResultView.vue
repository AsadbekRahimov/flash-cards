<script setup lang="ts">
import { onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useTelegram } from '@/composables/useTelegram'

const props = defineProps<{ sessionId: number }>()

const auth = useAuthStore()
const exam = useExamStore()
const { close, notificationHaptic } = useTelegram()

const { finalScore, finalRank, totalParticipants, leaderboard, errorMessage } = storeToRefs(exam)

onMounted(async () => {
  try {
    await auth.ensure()
    // Only refetch if we landed on /exam/:id/result directly (e.g. deep link
    // after the tab was closed). When coming from ExamView the store is
    // already populated.
    if (exam.sessionId !== props.sessionId) {
      exam.resetForSession(props.sessionId)
    }
    await exam.loadResults()
    notificationHaptic('success')
  } catch {
    /* handled via store */
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
      <h1 class="text-2xl font-bold">Итоги экзамена</h1>

      <div v-if="errorMessage" class="card text-left" data-testid="result-error">
        <p class="text-sm text-tg-hint">{{ errorMessage }}</p>
      </div>

      <div class="card">
        <div class="text-sm text-tg-hint">Твой результат</div>
        <div class="text-3xl font-bold mt-1" data-testid="final-score">{{ finalScore }} ★</div>
        <div class="text-sm text-tg-hint mt-1" data-testid="final-rank">
          <template v-if="finalRank !== null">
            Место <b class="text-tg-text">{{ finalRank }}</b> из {{ totalParticipants }}
          </template>
          <template v-else>
            Результаты ещё считаются…
          </template>
        </div>
      </div>

      <div v-if="leaderboard.length > 0" class="card text-left" data-testid="leaderboard">
        <div class="text-sm text-tg-hint mb-2">Топ участников</div>
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

      <button class="btn btn-primary w-full" data-testid="close-btn" @click="close()">
        Закрыть
      </button>
    </div>
  </section>
</template>
