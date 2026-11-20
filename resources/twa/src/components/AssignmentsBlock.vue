<script setup lang="ts">
import { formatDate } from '@/composables/format'
import { useI18n } from '@/i18n'
import type { AssignmentItem } from '@/api/types'

/**
 * "Assignments of the center" on Today (docs/04 A.9, docs/14 §6.1; mock-up Main, the list "For today"): one row per assignment,
 * drawn inside the list of the parent (no card of its own) - a status dot, the lesson, "group · module, lesson · done N %" and the
 * state of the deadline on the right. An open assignment is a button that opens the lesson (`open` carries the lesson id); a done
 * one is a plain row. `busy` disables the buttons while a session is being opened.
 *
 * The dot and the text on the right are the state of the assignment: amber with the due date, red when it is overdue, green when
 * it was done (the text amber when it was done late).
 */
defineProps<{ items: AssignmentItem[]; busy: boolean }>()
defineEmits<{ open: [lessonId: number] }>()

const { t, locale } = useI18n()

/** «30 окт., 18:00» in the device's time zone. */
function dueText(iso: string): string {
  return formatDate(iso, 'dayMonthShortTime', locale.value)
}

function dotClass(item: AssignmentItem): string {
  if (item.completed) return 'bg-success'
  return item.overdue ? 'bg-danger' : 'bg-warning'
}
</script>

<template>
  <div class="divide-y divide-divider" data-testid="assignments-card">
    <div v-for="item in items" :key="item.id" data-testid="assignment">
      <component
        :is="item.completed ? 'div' : 'button'"
        :type="item.completed ? undefined : 'button'"
        :disabled="item.completed ? undefined : busy"
        class="flex min-h-11 w-full items-center gap-3 px-[18px] py-3 text-left disabled:opacity-50"
        :class="{ 'active:bg-fill': !item.completed }"
        :data-testid="item.completed ? undefined : 'assignment-btn'"
        :data-state="item.completed ? 'done' : (item.overdue ? 'overdue' : 'open')"
        @click="item.completed ? undefined : $emit('open', item.lesson_id)"
      >
        <span class="size-2 shrink-0 rounded-full" :class="dotClass(item)" aria-hidden="true" data-testid="assignment-dot" />
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
          <span class="text-[15px] leading-5 font-semibold" data-testid="assignment-title">{{ item.title }}</span>
          <span class="text-[13px] leading-[18px] text-muted">
            {{ item.group }} · {{ t('assignments.lesson', { stage: item.stage, lesson: item.lesson }) }} ·
            <span data-testid="assignment-progress">{{ t('assignments.progress_short', { pct: item.progress_pct }) }}</span>
          </span>
        </span>
        <span
          v-if="item.completed"
          class="max-w-[45%] shrink-0 text-right text-[13px] leading-[18px]"
          :class="item.late ? 'text-warning-ink' : 'text-success-ink'"
          data-testid="assignment-done"
        >
          {{ t(item.late ? 'assignments.done_late' : 'assignments.done') }}
        </span>
        <span
          v-else
          class="max-w-[45%] shrink-0 text-right text-[13px] leading-[18px]"
          :class="item.overdue ? 'text-danger-ink' : 'text-warning-ink'"
          data-testid="assignment-due"
        >
          {{ t(item.overdue ? 'assignments.overdue' : 'assignments.due', { date: dueText(item.due_at) }) }}
        </span>
      </component>
    </div>
  </div>
</template>
