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
    <div class="flex flex-col gap-4 px-4 pt-4 pb-3">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="task-title">{{ t('gameRun.task.spell') }}</p>
        <h2 class="text-[28px] leading-8 font-bold break-words" data-testid="task-prompt">{{ element.prompt }}</h2>
      </article>
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
