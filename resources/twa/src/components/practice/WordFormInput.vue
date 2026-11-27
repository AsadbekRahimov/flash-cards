<script setup lang="ts">
/**
 * "Word form" (docs/16 §5.6, §11.3): an English sentence with one blank and the base word under it (`decide`); the learner types the
 * form the sentence needs (`decision`) and presses "Check"; "I don't know" shows the answer as a hint. The server grades the text.
 *
 * - `element` - the sentence (`text`, one `___`) and the base word (`base`). What is typed is shown in the blank too (only shown).
 * - `mode` - `solo` / `battle` (the mode is solo only; the prop is the interface of every mode component). Solo: ONE answer per
 *   element (docs/16 §11.4): when the request got no reply (the screen lets go of `disabled`) the server may have counted that very
 *   text, so the field stays as it was, read-only, and "Check" (or "I don't know", if that went first) sends the same again - with the
 *   same request id, which the store keeps. Battle: the answer is final, the component locks.
 * - `disabled` - an answer is on its way: the text and the buttons wait. The field is read-only, not disabled, so the keyboard stays up.
 * Events: `answer` (`{ text }`), `skip` ("I don't know").
 *
 * For the phone keyboard: no capitals, no autocorrection and no spell check (a "corrected" word would be the learner's mistake turned
 * into the answer, or the other way round); Enter is "Check". On a short window (the keyboard is up) the instruction goes, so the
 * sentence, the field and the buttons stay in view. The typed text is kept in the memory of the page (`wordFormDraft`).
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { phraseSize, splitPhrase } from '@/composables/collocationPhrase'
import { clearWordFormDraft, readWordFormDraft, writeWordFormDraft } from '@/composables/wordFormDraft'
import { useI18n } from '@/i18n'
import type { PracticeAnswerPayload, WordFormElement } from '@/api/types'

const props = defineProps<{
  element: WordFormElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload]; skip: [] }>()

const { t } = useI18n()

/** The server reads at most 60 characters of the answer (a form is up to three words). */
const MAX_LENGTH = 60

const text = ref(readWordFormDraft(props.element.id))
/** Battle: the one final answer has gone. */
const sent = ref(false)
/** What went out last in a solo session: the text ("Check") or "I don't know". */
const lastSent = ref<'answer' | 'skip' | null>(null)
/** Solo: the last request got no reply. The text is as it was and cannot be changed; the same action goes again. */
const retrying = ref(false)

const trimmed = computed((): string => text.value.trim().replace(/\s+/g, ' '))
const locked = computed((): boolean => props.disabled === true || sent.value)
const canCheck = computed((): boolean => trimmed.value !== '' && !locked.value && (!retrying.value || lastSent.value === 'answer'))
const canSkip = computed((): boolean => !locked.value && (!retrying.value || lastSent.value === 'skip'))

const parts = computed(() => splitPhrase(props.element.text))
const sentenceClass = computed((): string => phraseSize(props.element.text))

watch(text, (value) => writeWordFormDraft(props.element.id, value))

// Another element in the same component (a screen that does not key it by the element): its own text.
watch(
  () => props.element.id,
  (id) => {
    text.value = readWordFormDraft(id)
    sent.value = false
    lastSent.value = null
    retrying.value = false
  },
)

// The request ended and the element is still this one: in a solo session what was sent got no reply (or the screen was brought up to
// date). The answer may have been counted, so the text stays as it is and goes again (`retrying`); a battle has its own retry.
watch(
  () => props.disabled,
  (now, before) => {
    if (before === true && now !== true && props.mode === 'solo' && lastSent.value !== null) retrying.value = true
  },
)

function check(): void {
  if (!canCheck.value) return
  lastSent.value = 'answer'
  emit('answer', { text: trimmed.value })
  if (props.mode === 'battle') sent.value = true
}

function skip(): void {
  if (!canSkip.value) return
  lastSent.value = 'skip'
  emit('skip')
  if (props.mode === 'battle') sent.value = true
}

// The answer went out and the element is over (the screen moves on to the review): nothing to bring back. A text that got no reply is
// kept: it is the one to send again when the learner comes back.
onBeforeUnmount(() => {
  if (lastSent.value !== null && !retrying.value) clearWordFormDraft(props.element.id)
})
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="word-form-input" :data-mode="mode">
    <div class="flex flex-1 flex-col gap-3 px-5 pt-4 pb-4 [@media(max-height:560px)]:gap-2 [@media(max-height:560px)]:pt-2">
      <article class="flex flex-col items-center gap-3 px-5 py-5 text-center [@media(max-height:560px)]:gap-2 [@media(max-height:560px)]:py-3">
        <p class="text-[13px] leading-[18px] text-muted [@media(max-height:560px)]:hidden" data-testid="word-form-instruction">
          {{ t('practice.word_form.instruction') }}
        </p>

        <h2 class="max-w-full font-semibold break-words" :class="sentenceClass" lang="en" data-testid="word-form-text">
          <template v-if="parts">
            <span>{{ parts.before }}</span>
            <span
              class="mx-0.5 inline-block min-w-[4.5rem] max-w-full rounded-sm border-2 px-2.5 text-center align-baseline text-[0.9em] leading-6 font-semibold break-words"
              :class="trimmed !== '' ? 'border-accent bg-accent-soft text-accent-ink' : 'border-dashed border-accent bg-accent-soft'"
              :data-tone="trimmed !== '' ? 'typed' : 'empty'"
              data-testid="word-form-blank"
            >
              <template v-if="trimmed !== ''">{{ trimmed }}</template>
              <template v-else>
                <span class="sr-only">{{ t('practice.collocation.blank') }}</span>
                <span aria-hidden="true">&nbsp;</span>
              </template>
            </span>
            <span>{{ parts.after }}</span>
          </template>
          <template v-else>{{ element.text }}</template>
        </h2>

        <!-- The word to make the form of: a chip with the word as it is (its own case: a small-caps "I" is all but invisible), named for a screen reader. -->
        <p class="flex flex-wrap items-center justify-center gap-2" data-testid="word-form-base-row">
          <span class="text-[13px] leading-[18px] text-muted">{{ t('practice.word_form.base_hint') }}</span>
          <span
            class="chip chip-accent h-8 px-3 text-[17px] leading-[22px] font-semibold"
            lang="en"
            data-testid="word-form-base"
          >{{ element.base }}</span>
        </p>
      </article>

      <input
        v-model="text"
        type="text"
        lang="en"
        inputmode="text"
        class="field field-surface scroll-mb-28 text-center text-xl"
        autocapitalize="off"
        autocorrect="off"
        autocomplete="off"
        spellcheck="false"
        enterkeyhint="done"
        :maxlength="MAX_LENGTH"
        :readonly="locked || retrying"
        :aria-label="`${t('practice.word_form.placeholder')}. ${t('practice.word_form.base_hint')}: ${element.base}`"
        :placeholder="t('practice.word_form.placeholder')"
        data-testid="word-form-field"
        @keydown.enter.prevent="check"
      />

      <!-- The text did not get its reply: it cannot be changed any more, and it is sent again. -->
      <InlineAlert v-if="retrying" tone="warning" :message="t('practice.session.resend_note')" data-testid="word-form-resend-note" />
    </div>

    <StickyActions surface>
      <div class="flex items-stretch gap-2">
        <button type="button" class="btn btn-plain shrink-0 text-muted" :disabled="!canSkip" data-testid="skip-btn" @click="skip">
          {{ t('exercise.skip') }}
        </button>
        <button
          type="button"
          class="btn btn-primary btn-lg min-w-0 flex-1 [@media(max-height:560px)]:min-h-12"
          :disabled="!canCheck"
          data-testid="check-btn"
          @click="check"
        >
          {{ t('exercise.check') }}
        </button>
      </div>
    </StickyActions>
  </div>
</template>
