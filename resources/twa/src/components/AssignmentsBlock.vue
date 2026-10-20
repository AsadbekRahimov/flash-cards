<script setup lang="ts">
import { getLocale, useI18n } from '@/i18n'
import ProgressBar from '@/components/ProgressBar.vue'
import type { AssignmentItem } from '@/api/types'

defineProps<{ items: AssignmentItem[]; busy: boolean }>()
defineEmits<{ open: [lessonId: number] }>()

const { t } = useI18n()

/** «20 окт., 18:00» in the device's time zone. */
function formatDue(iso: string): string {
  return new Intl.DateTimeFormat(getLocale() === 'uz' ? 'uz-Latn' : 'ru', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(iso))
}
</script>

<template>
  <div class="card space-y-3" data-testid="assignments-card">
    <div class="font-semibold">{{ t('assignments.title') }}</div>
    <div v-for="item in items" :key="item.id" class="space-y-2" data-testid="assignment">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="font-semibold" data-testid="assignment-title">{{ item.title }}</div>
          <div class="text-xs text-tg-hint">{{ item.group }} · {{ t('assignments.lesson', { stage: item.stage, lesson: item.lesson }) }}</div>
        </div>
        <span
          v-if="item.completed"
          class="text-xs font-semibold shrink-0"
          :class="item.late ? 'text-amber-600' : 'text-green-600'"
          data-testid="assignment-done"
        >
          {{ t(item.late ? 'assignments.done_late' : 'assignments.done') }}
        </span>
      </div>
      <div class="text-sm" :class="item.overdue && !item.completed ? 'text-red-600' : 'text-tg-hint'" data-testid="assignment-due">
        {{ t(item.overdue && !item.completed ? 'assignments.overdue' : 'assignments.due', { date: formatDue(item.due_at) }) }}
      </div>
      <ProgressBar :done="item.progress_pct" :total="100" />
      <div class="text-sm text-tg-hint" data-testid="assignment-progress">{{ t('assignments.progress', { pct: item.progress_pct }) }}</div>
      <button
        v-if="!item.completed"
        type="button"
        class="btn btn-secondary w-full"
        :disabled="busy"
        data-testid="assignment-btn"
        @click="$emit('open', item.lesson_id)"
      >
        {{ t(item.progress_pct > 0 ? 'assignments.continue' : 'assignments.start') }}
      </button>
    </div>
  </div>
</template>
