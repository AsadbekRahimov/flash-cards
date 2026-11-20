<script setup lang="ts">
/**
 * A task of four options (docs/05 §5b.3): `translate_fwd` (an English word, choose the translation), `translate_back` (a word of the
 * learner's language, choose the English one) and `listen` (a clip, choose the word - "Listen" plays it from the start, as often as wanted).
 *
 * - The tap on an option is final: ONE answer per element. When the request got no reply the pick stays marked and "Retry" sends the same
 *   again (`useResend`). "I don't know" sends `skip`; the server shows the answer.
 * - The hint of the bazaar takes options away (`element.hint.options_removed`): they stay on the screen, dimmed and disabled, so that
 *   the list does not jump.
 * - The clip of `listen` is loaded by the screen as soon as the element is shown (the same URL is not fetched twice); this component asks
 *   when nobody has, and plays it from the tap (a gesture on iOS). Nothing is read aloud by the browser: the word must not be solved by a machine.
 * Events: `answer` (`{ option_id }`), `skip`.
 */
import { computed, onMounted, ref } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { useResend } from '@/composables/useResend'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import { useTelegram } from '@/composables/useTelegram'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import type { GameAnswerPayload, GameChoiceElement } from '@/api/gameRunTypes'
import type { PracticeChoice } from '@/api/types'

const props = defineProps<{
  element: GameChoiceElement
  mode?: 'solo'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: GameAnswerPayload]; skip: [] }>()

const { selectionHaptic } = useTelegram()
const { t } = useI18n()
const audio = usePracticeAudio({ fetchBlob: (url, signal) => api.gameRuns.audioBlob(url, signal) })

const picked = ref<string | null>(null)
const { lastSent, retrying, mark } = useResend(() => props.disabled)

const removed = computed((): Set<string> => {
  const hint = props.element.hint
  return new Set(hint !== undefined && 'options_removed' in hint ? hint.options_removed : [])
})

const locked = computed((): boolean => props.disabled === true || lastSent.value !== null)

/** The state of `.option`: the pick; the rest once the answer has gone, and an option a hint took away, step back. */
function stateOf(option: PracticeChoice): 'idle' | 'selected' | 'dimmed' {
  if (picked.value === option.id) return 'selected'
  return locked.value || removed.value.has(option.id) ? 'dimmed' : 'idle'
}

function pick(option: PracticeChoice): void {
  if (locked.value || removed.value.has(option.id)) return
  picked.value = option.id
  mark('answer')
  emit('answer', { option_id: option.id })
}

function skip(): void {
  if (locked.value) return
  mark('skip')
  emit('skip')
}

/** The same again: the pick, or "I don't know", whichever went first. */
function resend(): void {
  if (props.disabled === true || !retrying.value) return
  if (lastSent.value === 'skip') emit('skip')
  else if (picked.value !== null) emit('answer', { option_id: picked.value })
  retrying.value = false
}

// ---- the clip of `listen` ----

const listening = computed((): boolean => props.element.kind === 'listen')
const clipUrl = computed((): string | null => (props.element.kind === 'listen' ? props.element.audio_url : null))
const state = computed(() => audio.state.value)
const loading = computed((): boolean => state.value === 'loading')
const failed = computed((): boolean => state.value === 'failed')
const playing = computed((): boolean => audio.playing.value && state.value === 'ready')
const listenLabel = computed((): string => (failed.value ? t('practice.audio.retry') : loading.value ? t('practice.audio.loading') : t('practice.audio.play')))

onMounted(() => {
  if (clipUrl.value !== null && state.value === 'idle') void audio.load(clipUrl.value)
})

function listen(): void {
  const url = clipUrl.value
  if (url === null) return
  if (failed.value) {
    void audio.load(url, { force: true })
    return
  }
  if (state.value !== 'ready') {
    if (state.value === 'idle') void audio.load(url)
    return
  }
  selectionHaptic()
  void audio.play()
}
</script>

<template>
  <div class="flex flex-1 flex-col" :data-kind="element.kind" data-testid="choice-task">
    <!-- The screen of a question (docs/design Question): the task in words, the word big, the options in thin frames under it. -->
    <div class="flex flex-1 flex-col gap-11 px-5 pt-10 pb-4 short:gap-4 short:pt-3">
      <div class="flex flex-col items-center gap-2.5 text-center short:gap-1.5">
        <p class="text-[13px] leading-[18px] text-muted short:hidden" data-testid="task-title">{{ t(`gameRun.task.${element.kind}`) }}</p>

        <h2 v-if="element.kind === 'translate_fwd'" class="max-w-full text-[40px] leading-[46px] font-semibold tracking-[-0.5px] break-words short:text-[30px] short:leading-9" lang="en" data-testid="task-prompt">{{ element.prompt }}</h2>
        <h2 v-else-if="element.kind === 'translate_back'" class="max-w-full text-[32px] leading-[38px] font-semibold tracking-[-0.4px] break-words short:text-[26px] short:leading-8" data-testid="task-prompt">{{ element.prompt }}</h2>
        <!-- The clip is the question of `listen`: a big round button in a thin frame, framed in the primary colour while it plays. -->
        <button
          v-else
          type="button"
          class="flex size-20 short:size-16 shrink-0 items-center justify-center rounded-full border transition-[transform,background-color,border-color] duration-150 active:scale-95 disabled:opacity-60"
          :class="failed ? 'border-warning-line bg-warning-soft text-warning-ink' : playing ? 'border-primary bg-accent-soft text-accent-ink' : 'border-hairline bg-surface text-accent'"
          :disabled="loading"
          :aria-label="listenLabel"
          :aria-busy="loading ? 'true' : undefined"
          :data-playing="playing ? 'true' : undefined"
          data-testid="listen-btn"
          @click="listen"
        >
          <span v-if="loading" class="size-7 animate-spin rounded-full border-[3px] border-current/30 border-t-current motion-reduce:animate-none" aria-hidden="true" />
          <AppIcon v-else :name="failed ? 'arrow-path' : 'speaker-wave'" :size="36" />
        </button>
        <p v-if="listening" class="text-[13px] leading-[18px]" :class="failed ? 'text-warning-ink' : 'text-muted'" role="status" data-testid="audio-state" :data-state="state">
          {{ listenLabel }}
        </p>
      </div>

      <div class="flex flex-col gap-2.5 short:gap-2">
        <InlineAlert v-if="retrying" tone="warning" :message="t('practice.session.resend_note')" data-testid="resend-note" />

        <div class="flex flex-col gap-2.5 short:gap-2" role="group" :aria-label="t('gameRun.options')">
          <!-- `.option` (style.css): picked - `selected`; the others once the answer is gone and the ones a hint took away - `dimmed`. -->
          <button
            v-for="(option, index) in element.options"
            :key="option.id"
            type="button"
            class="option short:min-h-11"
            :data-state="stateOf(option)"
            :data-removed="removed.has(option.id) ? 'true' : undefined"
            :data-testid="`option-${index}`"
            :disabled="locked || removed.has(option.id)"
            :aria-pressed="picked === option.id ? 'true' : undefined"
            @click="pick(option)"
          >
            <span class="w-3 shrink-0 font-mono text-[13px] leading-[18px] font-normal" :class="stateOf(option) === 'selected' ? 'text-accent-ink' : 'text-muted'" aria-hidden="true">{{ index + 1 }}</span>
            <span class="min-w-0 flex-1 break-words">
              <span class="block" :lang="element.kind === 'translate_fwd' ? undefined : 'en'">{{ option.text }}</span>
              <span v-if="removed.has(option.id)" class="mt-0.5 block text-[13px] leading-[18px] font-normal">{{ t('gameRun.option_removed') }}</span>
            </span>
          </button>
        </div>
      </div>
    </div>

    <StickyActions surface>
      <button v-if="retrying" type="button" class="btn btn-primary btn-lg btn-block" :disabled="disabled" data-testid="resend-btn" @click="resend">
        {{ t('common.retry') }}
      </button>
      <button v-else type="button" class="btn btn-plain btn-block text-muted" :disabled="locked" data-testid="skip-btn" @click="skip">
        {{ t('exercise.skip') }}
      </button>
    </StickyActions>
  </div>
</template>
