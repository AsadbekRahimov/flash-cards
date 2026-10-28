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
      <p v-if="answerLength" class="text-center text-[13px] leading-[18px] text-tg-hint" data-testid="answer-length">
        {{ t('exercise.answer_length', { count: answerLength }) }}
      </p>
      <button type="submit" class="btn btn-primary btn-lg btn-block mt-1" :disabled="disabled || sent" data-testid="check-btn">
        {{ t('exercise.check') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" :disabled="disabled || sent" data-testid="skip-btn" @click="submit('')">
        {{ t('exercise.skip') }}
      </button>
    </form>
  </StickyActions>
</template>
