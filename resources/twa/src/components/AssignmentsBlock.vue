<script setup lang="ts">
import { formatDate } from '@/composables/format'
import { useI18n } from '@/i18n'
import type { AssignmentItem } from '@/api/types'

/**
 * "Assignments of the center" on Today (docs/04 A.9, docs/14 §6.1): a label and one card per assignment with the
 * lesson, a chip with the state of the deadline, the learner's own progress and the button that opens the lesson
 * (`open` carries the lesson id). `busy` disables the buttons while a session is being opened.
 *
 * The chip is the state of the assignment: neutral with the due date, red when it is overdue, green when it was done
 * in time, amber when it was done late.
 */
defineProps<{ items: AssignmentItem[]; busy: boolean }>()
defineEmits<{ open: [lessonId: number] }>()

const { t, locale } = useI18n()

/** «30 окт., 18:00» in the device's time zone. */
function dueText(iso: string): string {
  return formatDate(iso, 'dayMonthShortTime', locale.value)
}
</script>

<template>
  <section data-testid="assignments-card">
    <h2 class="section-label">{{ t('assignments.title') }}</h2>

    <div class="space-y-3">
      <article v-for="item in items" :key="item.id" class="card space-y-3" data-testid="assignment">
        <div class="space-y-2">
          <div>
            <h3 class="text-[17px] leading-[22px] font-semibold" data-testid="assignment-title">{{ item.title }}</h3>
            <p class="text-[13px] leading-[18px] text-tg-hint">
              {{ item.group }} · {{ t('assignments.lesson', { stage: item.stage, lesson: item.lesson }) }}
            </p>
          </div>

          <span v-if="item.completed" class="chip" :class="item.late ? 'chip-warning' : 'chip-success'" data-testid="assignment-done">
            {{ t(item.late ? 'assignments.done_late' : 'assignments.done') }}
          </span>
          <span v-else class="chip" :class="item.overdue ? 'chip-danger' : 'chip-neutral'" data-testid="assignment-due">
            {{ t(item.overdue ? 'assignments.overdue' : 'assignments.due', { date: dueText(item.due_at) }) }}
          </span>
        </div>

        <div class="flex items-center gap-3">
          <div
            class="progress flex-1"
            :class="{ 'progress-success': item.completed }"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="item.progress_pct"
            :aria-label="t('assignments.progress', { pct: item.progress_pct })"
          >
            <div class="progress-fill" :style="{ width: `${item.progress_pct}%` }" />
          </div>
          <span class="w-10 text-right text-[13px] leading-[18px] font-medium text-tg-subtitle" data-testid="assignment-progress">
            {{ item.progress_pct }}%
          </span>
        </div>

        <button
          v-if="!item.completed"
          type="button"
          class="btn btn-tinted btn-block"
          :disabled="busy"
          data-testid="assignment-btn"
          @click="$emit('open', item.lesson_id)"
        >
          {{ t(item.progress_pct > 0 ? 'assignments.continue' : 'assignments.start') }}
        </button>
      </article>
    </div>
  </section>
</template>
