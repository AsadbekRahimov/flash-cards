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
  <StickyActions surface>
    <form class="flex flex-col gap-2" data-testid="answer-form" @submit.prevent="submit(text)">
      <input
        v-model="text"
        type="text"
        class="field field-surface text-center text-[22px] leading-7 font-medium"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        spellcheck="false"
        enterkeyhint="done"
        maxlength="100"
        :placeholder="t('exercise.input.placeholder')"
        :disabled="disabled || sent"
        data-testid="answer-input"
      />
      <p v-if="answerLength" class="text-center text-[13px] leading-[18px] text-muted" data-testid="answer-length">
        {{ t('exercise.answer_length', { count: answerLength }) }}
      </p>
      <!-- docs/design Question: "I don't know" as quiet text on the left, the main button takes the rest of the row. -->
      <div class="mt-1 flex items-center gap-2">
        <button
          type="button"
          class="btn btn-plain btn-lg w-28 shrink-0 px-2 text-base font-semibold text-muted"
          :disabled="disabled || sent"
          data-testid="skip-btn"
          @click="submit('')"
        >
          {{ t('exercise.skip') }}
        </button>
        <button type="submit" class="btn btn-primary btn-lg min-w-0 flex-1" :disabled="disabled || sent" data-testid="check-btn">
          {{ t('exercise.check') }}
        </button>
      </div>
    </form>
  </StickyActions>
</template>
