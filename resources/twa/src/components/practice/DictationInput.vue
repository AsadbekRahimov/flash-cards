<script setup lang="ts">
/**
 * "Listen and write" (docs/16 §11.3, docs/15 §3.4): the learner hears a word or a phrase, writes what was heard and presses
 * "Check"; "I don't know" shows the answer as a hint. The text of the task is NOT on the device: the element is the URL of the
 * recording, the number of words and the swaps left - so there is nothing to read aloud to a speech synthesizer, and Web Speech
 * (`useTts`) is not used here and must not be.
 *
 * - `element` - the dictation. The clip is fetched with the token into a `blob:` (`usePracticeAudio`) as soon as the element is
 *   shown - the session screen does it for the next element while the verdict is read, this component asks when nobody has - and
 *   it is played from the tap, as many times as the learner wants.
 * - `mode` - `solo` / `battle`. Solo: "Check" sends the text, and a refusal that leaves the learner here (no network) lets them try
 *   again. Battle: the answer is final (docs/15 §3.5) - after "Check" or "I don't know" the component locks.
 * - `disabled` - an answer is on its way: the text and the answer buttons wait. Listening is not an answer and stays possible.
 * Events: `answer` (`{ text }`), `skip` ("I don't know").
 *
 * No guessing in the dark: when the clip cannot be loaded and the learner has not heard it, the field and both answer buttons wait,
 * and the circle becomes "Retry" (the session screen offers the sheet: retry / replace the task / go on without sound). A clip
 * that stops playing AFTER the learner has heard it does not take the answer away.
 *
 * Layout for the on-screen keyboard: the field sits right under the circle, in the upper part of the screen; "I don't know" and
 * "Check" are one row in `StickyActions`, above the keyboard. On a short window (a phone with the keyboard up) the title goes and
 * the circle shrinks to 64 px, so the field and the buttons stay in view. The empty tiles are one per word (no letters).
 */
import { computed, onMounted, ref, watch } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { modeTitle } from '@/composables/practiceLabels'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { DictationElement, PracticeAnswerPayload } from '@/api/types'

const props = defineProps<{
  element: DictationElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload]; skip: [] }>()

const { selectionHaptic } = useTelegram()
const { t, tn } = useI18n()
const audio = usePracticeAudio()

const text = ref('')
/** The learner has started the clip at least once: a later failure of the sound does not take the answer away. */
const heard = ref(false)
/** Battle: the one final answer has gone. */
const sent = ref(false)

const trimmed = computed((): string => text.value.trim())
const typedWords = computed((): number => (trimmed.value === '' ? 0 : trimmed.value.split(/\s+/).length))
const total = computed((): number => Math.max(1, props.element.words))
const filled = computed((): number => Math.min(typedWords.value, total.value))

const state = computed(() => audio.state.value)
const loading = computed((): boolean => state.value === 'loading')
const failed = computed((): boolean => state.value === 'failed')
const playing = computed((): boolean => audio.playing.value && state.value === 'ready')

/** The clip cannot be loaded and has never been heard: nothing to write down, so no answer is offered. */
const blocked = computed((): boolean => failed.value && !heard.value)
const locked = computed((): boolean => props.disabled === true || sent.value)
const canCheck = computed((): boolean => trimmed.value !== '' && !locked.value && !blocked.value)
const canSkip = computed((): boolean => !locked.value && !blocked.value)

const listenLabel = computed((): string => (failed.value ? t('practice.audio.retry') : loading.value ? t('practice.audio.loading') : t('practice.audio.play')))

const caption = computed((): string => {
  if (failed.value) return t('practice.audio.trouble.title')
  if (loading.value) return t('practice.audio.loading')
  if (playing.value) return t('practice.dictation.playing')
  return t('practice.dictation.hint')
})

// The clip of the element on screen. The screen has asked already when it could (the same URL is not fetched twice); an element that
// replaces another in this very component (a battle round) brings its own clip.
onMounted(() => {
  if (state.value === 'idle') void audio.load(props.element.audio_url)
})

watch(
  () => props.element.id,
  () => {
    text.value = ''
    heard.value = false
    sent.value = false
    void audio.load(props.element.audio_url)
  },
)

/** The tap that plays: the clip is started in this very call (a gesture on iOS). A clip that failed to load is loaded again instead. */
function listen(): void {
  if (failed.value) {
    void audio.load(props.element.audio_url, { force: true })
    return
  }
  if (state.value !== 'ready') {
    if (state.value === 'idle') void audio.load(props.element.audio_url)
    return
  }
  selectionHaptic()
  void audio.play().then((started) => {
    if (started) heard.value = true
  })
}

function check(): void {
  if (!canCheck.value) return
  emit('answer', { text: trimmed.value })
  if (props.mode === 'battle') sent.value = true
}

function skip(): void {
  if (!canSkip.value) return
  emit('skip')
  if (props.mode === 'battle') sent.value = true
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="dictation-input" :data-mode="mode">
    <div class="flex flex-1 flex-col gap-3 px-4 pt-3 pb-4">
      <article class="card flex flex-col items-center gap-2 px-5 py-4 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle [@media(max-height:560px)]:hidden" data-testid="dictation-title">
          {{ modeTitle('dictation') }}
        </p>

        <button
          type="button"
          class="flex size-20 shrink-0 items-center justify-center rounded-full transition-[transform,box-shadow] duration-150 active:scale-95 disabled:opacity-60 [@media(max-height:560px)]:size-16"
          :class="[
            failed ? 'bg-warning-soft text-warning-ink' : 'bg-accent text-tg-button-text',
            playing ? 'shadow-[0_0_0_6px_var(--app-accent-soft)]' : '',
          ]"
          :disabled="loading"
          :aria-label="listenLabel"
          :aria-busy="loading ? 'true' : undefined"
          :data-playing="playing ? 'true' : undefined"
          data-testid="listen-btn"
          @click="listen"
        >
          <span
            v-if="loading"
            class="size-7 animate-spin rounded-full border-[3px] border-current/30 border-t-current motion-reduce:animate-none"
            aria-hidden="true"
            data-testid="listen-spinner"
          />
          <AppIcon v-else :name="failed ? 'arrow-path' : 'speaker-wave'" :size="36" />
        </button>

        <p
          class="text-[13px] leading-[18px]"
          :class="failed ? 'font-medium text-warning-ink' : 'text-tg-hint'"
          role="status"
          data-testid="audio-state"
          :data-state="state"
        >
          {{ caption }}
        </p>

        <!-- One empty tile per word, no letters; a tile fills as the words are typed. -->
        <div class="flex flex-col items-center gap-1" data-testid="word-hints">
          <div
            class="flex flex-wrap justify-center gap-1.5"
            role="img"
            :aria-label="t('practice.dictation.typed', { typed: filled, count: total })"
            data-testid="word-tiles"
          >
            <span
              v-for="n in total"
              :key="n"
              class="h-6 rounded-md border-2"
              :class="[total > 8 ? 'w-6' : 'w-9', n <= filled ? 'border-accent bg-accent-soft' : 'border-dashed border-tg-hint']"
              :data-filled="n <= filled ? 'true' : 'false'"
              data-testid="word-tile"
            />
          </div>
          <p class="text-[13px] leading-[18px] font-medium text-tg-subtitle" data-testid="word-count">{{ tn('practice.dictation.words', total) }}</p>
        </div>
      </article>

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
        :disabled="locked || blocked"
        :aria-label="t('practice.dictation.input')"
        :placeholder="t('practice.dictation.input')"
        data-testid="dictation-text"
        @keydown.enter.prevent="check"
      />

      <p v-if="blocked" class="px-2 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="blocked-note">
        {{ t('practice.dictation.blocked') }}
      </p>
    </div>

    <StickyActions>
      <div class="flex items-stretch gap-2">
        <button type="button" class="btn btn-plain shrink-0" :disabled="!canSkip" data-testid="skip-btn" @click="skip">
          {{ t('exercise.skip') }}
        </button>
        <button type="button" class="btn btn-primary btn-lg min-w-0 flex-1" :disabled="!canCheck" data-testid="check-btn" @click="check">
          {{ t('exercise.check') }}
        </button>
      </div>
    </StickyActions>
  </div>
</template>
