<script setup lang="ts">
/**
 * PLACEHOLDER of "Listen and write" (task 18.1-T): the final interface of the component, with the simplest working
 * content. 18.5 replaces this file as a whole; the props and the events stay.
 *
 * - `element` — the dictation: no text, only the clip (`audio_url`) and the number of words. The screen loads the clip
 *   (`usePracticeAudio`); the component plays it from the tap and shows its state.
 * - `mode` — `solo` / `battle`: "Check" sends the text once, in both.
 * - `disabled` — an answer is on its way: nothing reacts.
 * Events: `answer` (`{ text }`), `skip` ("I don't know").
 */
import { computed, ref } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { modeTitle } from '@/composables/practiceLabels'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import { useI18n } from '@/i18n'
import type { DictationElement, PracticeAnswerPayload } from '@/api/types'

const props = defineProps<{
  element: DictationElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload]; skip: [] }>()

const { t } = useI18n()
const audio = usePracticeAudio()

const text = ref('')
const canCheck = computed(() => text.value.trim() !== '')

/** The tap that plays: the clip is started in this very call. A clip that failed to load is loaded again instead. */
function listen(): void {
  if (audio.state.value === 'failed') void audio.load(props.element.audio_url, { force: true })
  else void audio.play()
}

function check(): void {
  if (!props.disabled && canCheck.value) emit('answer', { text: text.value.trim() })
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="dictation-input">
    <div class="flex flex-1 flex-col items-center gap-4 px-4 pt-4 pb-4">
      <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ modeTitle('dictation') }}</p>

      <button
        type="button"
        class="flex size-24 items-center justify-center rounded-full bg-accent text-tg-button-text transition-transform active:scale-95 disabled:opacity-50"
        :disabled="disabled || audio.state.value === 'loading'"
        :aria-label="audio.state.value === 'failed' ? t('practice.audio.retry') : t('practice.audio.play')"
        :aria-busy="audio.state.value === 'loading' ? 'true' : undefined"
        data-testid="listen-btn"
        @click="listen"
      >
        <AppIcon :name="audio.state.value === 'failed' ? 'arrow-path' : 'speaker-wave'" :size="40" />
      </button>
      <p class="text-[15px] leading-5 text-tg-hint" data-testid="audio-state" :data-state="audio.state.value">
        {{ audio.state.value === 'loading' ? t('practice.audio.loading') : audio.state.value === 'failed' ? t('practice.audio.trouble.title') : t('practice.audio.play') }}
      </p>

      <input
        v-model="text"
        type="text"
        class="field field-surface text-center text-xl"
        autocapitalize="off"
        autocorrect="off"
        autocomplete="off"
        spellcheck="false"
        enterkeyhint="done"
        :disabled="disabled"
        :aria-label="t('exercise.input.placeholder')"
        :placeholder="t('exercise.input.placeholder')"
        data-testid="dictation-text"
        @keydown.enter.prevent="check"
      />
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="disabled || !canCheck" data-testid="check-btn" @click="check">
        {{ t('exercise.check') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" :disabled="disabled" data-testid="skip-btn" @click="emit('skip')">
        {{ t('exercise.skip') }}
      </button>
    </StickyActions>
  </div>
</template>
