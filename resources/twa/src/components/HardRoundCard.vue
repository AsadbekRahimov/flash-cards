<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/i18n'
import type { HardRound } from '@/api/types'

/**
 * The invitation to the round of hard words on "Today" (docs/05 §5, docs/06 §14): a compact card under the session card, shown
 * only when there is something to do - a round that is open ("Continue") or one that can be started ("Review"). A round that was
 * played today, and a refusal for any other reason, say nothing: the learner is not teased with what is not there. The press
 * is the parent's (`start`): it opens the session (the same call continues an open one).
 */
const props = defineProps<{
  /** `hard_round` of `/today`; null (the feature is off) or absent (an older server): no card. */
  round: HardRound | null | undefined
  busy?: boolean
}>()

const emit = defineEmits<{ (e: 'start'): void }>()

const { t, tn } = useI18n()

const resuming = computed((): boolean => props.round?.open_session_id != null)
const visible = computed((): boolean => props.round != null && (resuming.value || props.round.available))
const words = computed((): number => props.round?.words ?? 0)
</script>

<template>
  <section v-if="visible" class="card flex min-h-11 items-center gap-3" data-testid="hard-round-card">
    <div class="min-w-0 flex-1">
      <h2 class="text-[15px] leading-5 font-semibold">{{ t('today.hard_round.title') }}</h2>
      <p v-if="words > 0" class="text-[13px] leading-[18px] text-muted" data-testid="hard-round-words">
        {{ tn('today.hard_round.subtitle', words) }}
      </p>
    </div>
    <button type="button" class="btn btn-tinted shrink-0" :disabled="busy" data-testid="hard-round-btn" @click="emit('start')">
      {{ resuming ? t('today.hard_round.continue') : t('today.hard_round.start') }}
    </button>
  </section>
</template>
