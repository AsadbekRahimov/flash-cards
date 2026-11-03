<script setup lang="ts">
/**
 * The typed answer of `spell` and `gap` (docs/05 §5b.3): a field with no autocorrection, no capitals and no spell check (the word is
 * the answer: the keyboard must not fix it), "Check" (or Enter) and "I don't know" in the sticky block above the keyboard.
 * ONE answer per element: when the request got no reply the text stays as it was, read-only, and the button sends the same again.
 *
 * - `hintPrefix` - the first letters bought at the bazaar, shown above the field (the field stays empty: typing them is the learner's).
 * - `length` - the number of letters of `spell` ("Letters: 5"), told under the field.
 * Events: `answer` (the text), `skip`.
 */
import { computed, ref } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { useResend } from '@/composables/useResend'
import { useI18n } from '@/i18n'

const props = defineProps<{
  placeholder: string
  disabled?: boolean
  hintPrefix?: string | null
  length?: number | null
}>()

const emit = defineEmits<{ answer: [text: string]; skip: [] }>()

const { t } = useI18n()
const text = ref('')
const { lastSent, retrying, mark } = useResend(() => props.disabled)

const trimmed = computed((): string => text.value.trim())
const locked = computed((): boolean => props.disabled === true || lastSent.value !== null)
const canCheck = computed((): boolean => props.disabled !== true && trimmed.value !== '' && (lastSent.value === null || (retrying.value && lastSent.value === 'answer')))
const canSkip = computed((): boolean => props.disabled !== true && (lastSent.value === null || (retrying.value && lastSent.value === 'skip')))

function check(): void {
  if (!canCheck.value) return
  mark('answer')
  emit('answer', trimmed.value)
}

function skip(): void {
  if (!canSkip.value) return
  mark('skip')
  emit('skip')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="typed-answer">
    <div class="flex flex-col gap-3 px-4 pb-4">
      <p v-if="hintPrefix" class="text-center text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="hint-prefix">
        {{ t('gameRun.hint.prefix', { prefix: hintPrefix }) }}
      </p>

      <input
        v-model="text"
        type="text"
        lang="en"
        class="field field-surface scroll-mb-28 text-center text-xl"
        autocapitalize="off"
        autocorrect="off"
        autocomplete="off"
        spellcheck="false"
        enterkeyhint="done"
        maxlength="200"
        :readonly="locked"
        :aria-label="placeholder"
        :placeholder="placeholder"
        data-testid="answer-input"
        @keydown.enter.prevent="check"
      />
      <p v-if="length" class="text-center text-[13px] leading-[18px] text-tg-hint" data-testid="answer-length">{{ t('gameRun.spell.letters', { count: length }) }}</p>

      <InlineAlert v-if="retrying" tone="warning" :message="t('practice.session.resend_note')" data-testid="resend-note" />
    </div>

    <StickyActions>
      <div class="flex items-stretch gap-2">
        <button type="button" class="btn btn-plain shrink-0" :disabled="!canSkip" data-testid="skip-btn" @click="skip">{{ t('exercise.skip') }}</button>
        <button type="button" class="btn btn-primary btn-lg min-w-0 flex-1" :disabled="!canCheck" data-testid="check-btn" @click="check">{{ t('exercise.check') }}</button>
      </div>
    </StickyActions>
  </div>
</template>
