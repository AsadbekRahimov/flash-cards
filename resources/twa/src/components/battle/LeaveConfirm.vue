<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { useI18n } from '@/i18n'

/**
 * "Surrender and leave?" / "Leave the room?" (docs/16 §11.3): the question before a battle is left.
 *
 * - `variant="match"` — the match is on: leaving is a surrender, the opponent wins. `btn-danger` "Surrender", `btn-secondary` "Stay".
 * - `variant="room"` — the invitation, the room or the countdown: leaving cancels the battle. `btn-danger` "Leave", `btn-secondary` "Stay".
 * - `invite` — the learner sent the invitation and no one has accepted it: the text says it is withdrawn.
 * - `busy` — the answer of the server is awaited: the buttons wait.
 *
 * Events: `confirm` (do it) and `stay` (the ✕, the dimmed page, the Escape key and the "Stay" button: nothing happens).
 */
const props = defineProps<{
  variant: 'match' | 'room'
  invite?: boolean
  busy?: boolean
}>()

const emit = defineEmits<{ confirm: []; stay: [] }>()

const { t } = useI18n()

const title = computed(() => (props.variant === 'match' ? t('battle.leave.match_title') : t('battle.leave.room_title')))
const text = computed(() => {
  if (props.variant === 'match') return t('battle.leave.match_text')
  return props.invite ? t('battle.leave.invite_text') : t('battle.leave.room_text')
})
</script>

<template>
  <BottomSheet :label="title" data-testid="leave-confirm" :data-variant="variant" @close="emit('stay')">
    <header class="flex flex-col items-center gap-2 pt-1 text-center">
      <span class="flex size-14 items-center justify-center rounded-full bg-warning-soft text-warning-ink">
        <AppIcon :name="variant === 'match' ? 'flag' : 'exclamation-triangle'" solid :size="28" />
      </span>
      <h2 class="text-[22px] leading-7 font-bold" data-testid="leave-title">{{ title }}</h2>
      <p class="text-[15px] leading-5" data-testid="leave-text">{{ text }}</p>
    </header>

    <div class="space-y-2 pb-1">
      <button type="button" class="btn btn-danger btn-block" :disabled="busy" data-testid="leave-yes" @click="emit('confirm')">
        {{ variant === 'match' ? t('battle.leave.match_confirm') : t('battle.leave.room_confirm') }}
      </button>
      <button type="button" class="btn btn-secondary btn-block" :disabled="busy" data-testid="leave-stay" @click="emit('stay')">
        {{ t('battle.leave.stay') }}
      </button>
    </div>
  </BottomSheet>
</template>
