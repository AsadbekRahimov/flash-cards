<script setup lang="ts">
/**
 * `spell` (docs/05 §5b.3): the translation is shown, the learner writes the English word. The field is `TypedAnswer`: no autocorrection,
 * "I don't know" sends `skip`. The first letters bought at the bazaar (`element.hint.prefix`) are told above the field.
 * Events: `answer` (`{ text }`), `skip`.
 */
import { computed } from 'vue'
import TypedAnswer from '@/components/game/TypedAnswer.vue'
import { useI18n } from '@/i18n'
import type { GameAnswerPayload, SpellElement } from '@/api/gameRunTypes'

const props = defineProps<{
  element: SpellElement
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
  <div class="flex flex-1 flex-col" data-testid="spell-task">
    <!-- The question (docs/design Question), without a card: the task in words and the word to write, big. -->
    <div class="flex flex-col items-center gap-2.5 px-5 pt-10 pb-8 text-center short:gap-1 short:pt-3 short:pb-3">
      <p class="text-[13px] leading-[18px] text-muted short:hidden" data-testid="task-title">{{ t('gameRun.task.spell') }}</p>
      <h2 class="max-w-full text-[32px] leading-[38px] font-semibold tracking-[-0.4px] break-words short:text-[24px] short:leading-[30px]" data-testid="task-prompt">{{ element.prompt }}</h2>
    </div>
    <TypedAnswer
      :placeholder="t('gameRun.spell.placeholder')"
      :disabled="disabled"
      :hint-prefix="prefix"
      :length="element.answer_length"
      @answer="emit('answer', { text: $event })"
      @skip="emit('skip')"
    />
  </div>
</template>
