<script setup lang="ts">
import { ref } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * The text field of the typed exercises (spelling, gap-fill), in the sticky bottom block of the screen: one answer
 * per card — "Check" (or Enter) sends the text, "I don't know" sends an empty one (the server grades it as a miss
 * and shows the answer).
 */
const props = defineProps<{
  answerLength: number | null
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'answer', text: string): void
}>()

const { haptic } = useTelegram()
const { t } = useI18n()

const text = ref('')
const sent = ref(false)

function submit(value: string): void {
  if (props.disabled || sent.value) return
  sent.value = true
  haptic('medium')
  emit('answer', value)
}
</script>

<template>
  <StickyActions>
    <form class="flex flex-col gap-2" data-testid="answer-form" @submit.prevent="submit(text)">
      <!-- The TextField of the kit: 56 px, radius md, the hairline; focused - a 2 px frame of `border/strong` and the muhr caret. -->
      <input
        v-model="text"
        type="text"
        lang="en"
        class="field text-center text-[20px] leading-7 font-medium"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        spellcheck="false"
        enterkeyhint="done"
        maxlength="100"
        :placeholder="t('exercise.input.placeholder')"
        :aria-label="t('exercise.input.placeholder')"
        :disabled="disabled || sent"
        data-testid="answer-input"
      />
      <p v-if="answerLength" class="t-caption text-center text-muted" data-testid="answer-length">
        {{ t('exercise.answer_length', { count: answerLength }) }}
      </p>
      <!-- "I don't know" is the Ghost button of the kit on the left, the one Primary takes the rest of the row. -->
      <div class="mt-1 flex items-center gap-2">
        <button
          type="button"
          class="btn btn-ghost w-28 shrink-0 px-2 text-muted"
          :disabled="disabled || sent"
          data-testid="skip-btn"
          @click="submit('')"
        >
          {{ t('exercise.skip') }}
        </button>
        <button type="submit" class="btn btn-primary min-w-0 flex-1" :disabled="disabled || sent" data-testid="check-btn">
          {{ t('exercise.check') }}
        </button>
      </div>
    </form>
  </StickyActions>
</template>
