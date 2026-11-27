<script setup lang="ts">
/**
 * `gap` (docs/05 §5b.3): an example with a blank (`I ____ tea every morning.`), its translation under it, a field for the missing word
 * (`TypedAnswer`). The first letters bought at the bazaar are told above the field. Events: `answer` (`{ text }`), `skip`.
 * A phrase with its key word blanked (docs/35 §3.3 e) is longer than an example: from `GAME_LONG_TEXT_MIN` characters the text is a size
 * smaller and wraps by words, nothing is clipped.
 */
import { computed } from 'vue'
import TypedAnswer from '@/components/game/TypedAnswer.vue'
import { isLongGameText } from '@/composables/phraseText'
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
const long = computed((): boolean => isLongGameText(props.element.masked))
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="gap-task">
    <!-- The question (docs/design Question), without a card: the task in words, the example with its blank, its translation. -->
    <div class="flex flex-col items-center gap-2.5 px-5 pt-10 pb-8 text-center short:gap-1 short:pt-3 short:pb-3">
      <p class="t-body-m text-muted short:hidden" data-testid="task-title">{{ t('gameRun.task.gap') }}</p>
      <h2
        class="max-w-full font-display font-semibold tracking-[-0.02em] break-words"
        :class="long ? 'text-[20px] leading-[26px] text-balance short:text-[18px] short:leading-6' : 'text-[24px] leading-[30px] short:text-[20px] short:leading-[26px]'"
        lang="en"
        :data-long="long ? 'true' : undefined"
        data-testid="task-prompt"
      >{{ element.masked }}</h2>
      <p v-if="element.translation" class="t-body-m text-muted" data-testid="task-translation">{{ element.translation }}</p>
    </div>
    <TypedAnswer :placeholder="t('gameRun.gap.placeholder')" :disabled="disabled" :hint-prefix="prefix" @answer="emit('answer', { text: $event })" @skip="emit('skip')" />
  </div>
</template>
