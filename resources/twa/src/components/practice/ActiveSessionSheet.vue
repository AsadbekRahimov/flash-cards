<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { MODE_LOOK, modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { PracticeActiveSession } from '@/api/types'

/**
 * "Unfinished practice" (docs/16 §6.1, §11.2): the learner can have one unfinished solo session, so a new one needs an
 * answer — go on with the old one ("Continue") or close it and start the one just chosen ("Start a new one").
 * Opened on 409 `practice_session_active`; the session comes from the `details` of the refusal.
 *
 * Events: `continue`, `replace` (start the new one, `replace_active`), `close` (the ✕ and "Cancel": nothing happens).
 * `busy` disables the buttons while the new session is being opened. `place` says where the unfinished session is ("Module 2 ·
 * Lesson 4 · Food", or the course) when the screen can tell: the server names the course by its slug and the lesson by its id only,
 * so a session of another course that this run has not seen has no name to give, and the line is left out (nothing is made up).
 */
const props = defineProps<{
  session: PracticeActiveSession
  place?: string | null
  busy?: boolean
}>()

const emit = defineEmits<{ continue: []; replace: []; close: [] }>()

const { t } = useI18n()

const look = computed(() => MODE_LOOK[props.session.mode])
const text = computed(() =>
  t('practice.hub.active.text', {
    mode: modeTitle(props.session.mode),
    done: props.session.progress.done,
    total: props.session.progress.total,
  }),
)
</script>

<template>
  <BottomSheet :label="t('practice.hub.active.title')" data-testid="active-session-sheet" @close="emit('close')">
    <header class="flex flex-col items-center gap-2 pt-1 text-center">
      <span class="icon-tile size-14 rounded-2xl" :class="`tile-${look.tile}`"><AppIcon :name="look.icon" :size="28" /></span>
      <h2 class="text-[22px] leading-7 font-bold" data-testid="active-title">{{ t('practice.hub.active.title') }}</h2>
      <p v-if="place" class="text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="active-place">{{ place }}</p>
      <p class="text-[15px] leading-5" data-testid="active-text">{{ text }}</p>
    </header>

    <div class="space-y-2 pb-1">
      <button type="button" class="btn btn-primary btn-block" :disabled="busy" data-testid="active-continue" @click="emit('continue')">
        {{ t('practice.hub.continue') }}
      </button>
      <button type="button" class="btn btn-tinted btn-block" :disabled="busy" data-testid="active-replace" @click="emit('replace')">
        {{ t('practice.hub.active.new') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="active-cancel" @click="emit('close')">
        {{ t('practice.hub.active.cancel') }}
      </button>
    </div>
  </BottomSheet>
</template>
