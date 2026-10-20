<script setup lang="ts">
import { ref } from 'vue'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * The text field of the typed exercises (spelling, gap-fill): one answer per card — "Check" sends
 * the text, "I don't know" sends an empty one (the server grades it as a miss and shows the answer).
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
  <form class="flex flex-col gap-3" data-testid="answer-form" @submit.prevent="submit(text)">
    <input
      v-model="text"
      type="text"
      class="w-full rounded-xl px-4 py-3 text-xl text-center bg-tg-secondary-bg"
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
    <p v-if="answerLength" class="text-xs text-tg-hint text-center" data-testid="answer-length">
      {{ t('exercise.answer_length', { count: answerLength }) }}
    </p>
    <button type="submit" class="btn btn-primary w-full text-lg" :disabled="disabled || sent" data-testid="check-btn">
      {{ t('exercise.check') }}
    </button>
    <button type="button" class="btn btn-secondary w-full" :disabled="disabled || sent" data-testid="skip-btn" @click="submit('')">
      {{ t('exercise.skip') }}
    </button>
  </form>
</template>
