<script setup lang="ts">
/**
 * `gap` (docs/05 §5b.3): an example with a blank (`I ____ tea every morning.`), its translation under it, a field for the missing word
 * (`TypedAnswer`). The first letters bought at the bazaar are told above the field. Events: `answer` (`{ text }`), `skip`.
 */
import { computed } from 'vue'
import TypedAnswer from '@/components/game/TypedAnswer.vue'
import { useI18n } from '@/i18n'
import type { GameAnswerPayload, GapElement } from '@/api/gameRunTypes'

const props = defineProps<{
  element: GapElement
  mode?: 'solo'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: GameAnswerPayload]; skip: [] }>()

const { t } = useI18n()
const prefix = computed((): string | null => {
  const hint = props.element.hint
  return hint !== undefined && 'prefix' in hint ? hint.prefix : null
})
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="gap-task">
    <div class="flex flex-col gap-4 px-4 pt-4 pb-3">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="task-title">{{ t('gameRun.task.gap') }}</p>
        <h2 class="text-[22px] leading-7 font-bold break-words" lang="en" data-testid="task-prompt">{{ element.masked }}</h2>
        <p v-if="element.translation" class="text-[15px] leading-5 text-tg-hint" data-testid="task-translation">{{ element.translation }}</p>
      </article>
    </div>
    <TypedAnswer :placeholder="t('gameRun.gap.placeholder')" :disabled="disabled" :hint-prefix="prefix" @answer="emit('answer', { text: $event })" @skip="emit('skip')" />
  </div>
</template>
