<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import { filledWith, phraseSize, shownOptions, splitPhrase, yourOptionId } from '@/composables/collocationPhrase'
import { verdictLabel } from '@/composables/practiceLabels'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { AudioSlot, PracticeOutcome } from '@/stores/practice'
import type { CollocationReveal, DictationReveal, PracticeReveal, PracticeVerdict, SentenceReveal } from '@/api/types'

/**
 * What the server said about the answer (docs/16 §5, §11.2): a coloured circle with a symbol and the verdict in words
 * (the colour is never alone), then the explanation in the shape of the task:
 * - dictation — the phrase as it should be written with a button to listen to it again, what was typed (word by word: every
 *   word has a symbol and a word besides its colour, the wrong ones are underlined), the translation and the explanation;
 * - sentence — the correct sentence, the other accepted orders, what was assembled;
 * - collocation — the whole phrase, the three options with the right one and the picked one marked, the translation;
 *   the picked one is the server's `your_option_id` when it sends it (the answer that COUNTED), else the pick this screen knows;
 * - the end of a round of pairs — "Round 2 of 3 is done" and the number of wrong matches in it;
 * - an answer that was counted EARLIER (`outcome.earlier`, 409 `answer_already_accepted`: another tab, a reply that was lost) —
 *   only the verdict the server counted and the words "an answer sent earlier was counted": no right answer and no pick, for
 *   neither is known here, and the pick that was just made is not the one that counted.
 * With the explanation of the task, when it has one. "Next" is at the bottom (`StickyActions`); on the last element it
 * leads to the summary (`last`).
 *
 * The verdict is announced to a screen reader by the screen, in a live region that is always there.
 */
const props = defineProps<{
  outcome: PracticeOutcome
  /** The next step is the summary, not another element. */
  last?: boolean
}>()

const emit = defineEmits<{ continue: [] }>()

const { haptic, selectionHaptic } = useTelegram()
const { t } = useI18n()

const verdict = computed((): PracticeVerdict => props.outcome.verdict)

interface Look {
  icon: IconName
  /** The circle: the soft colour and its "ink" for the symbol. */
  circle: string
}

const LOOKS: Record<PracticeVerdict, Look> = {
  correct: { icon: 'check', circle: 'bg-success-soft text-success-ink' },
  typo: { icon: 'exclamation-circle', circle: 'bg-warning-soft text-warning-ink' },
  wrong: { icon: 'x-mark', circle: 'bg-danger-soft text-danger-ink' },
  skipped: { icon: 'light-bulb', circle: 'bg-accent-soft text-accent-ink' },
}

const isRoundEnd = computed(() => props.outcome.element.kind === 'pairs_round')
const look = computed((): Look => (isRoundEnd.value ? { icon: 'check-circle', circle: LOOKS.correct.circle } : LOOKS[verdict.value]))

const reveal = computed((): PracticeReveal | null => props.outcome.reveal)
const dictation = computed((): DictationReveal | null => (reveal.value !== null && 'expected' in reveal.value ? reveal.value : null))
const sentence = computed((): SentenceReveal | null => (reveal.value !== null && 'correct_text' in reveal.value ? reveal.value : null))
const collocation = computed((): CollocationReveal | null => (reveal.value !== null && 'correct_option_ids' in reveal.value ? reveal.value : null))

const translation = computed((): string | null => dictation.value?.translation ?? collocation.value?.translation ?? null)
const explanation = computed((): string | null => dictation.value?.explanation ?? sentence.value?.explanation ?? collocation.value?.explanation ?? null)

/** What the learner typed or assembled, when it was not right. */
const yourText = computed((): string | null => {
  if (verdict.value === 'correct') return null
  const text = dictation.value?.your_text ?? sentence.value?.your_text ?? null
  return text !== null && text.trim() !== '' ? text : null
})

/** The phrase as it should be written, marked token by token where the answer differed (a typo is a miss of one word). */
const diff = computed(() => (verdict.value === 'correct' ? null : (dictation.value?.diff ?? null)))

/** The options of a collocation as the answer left them: the pick `correct` / `incorrect` (by the verdict), the right ones marked. */
const options = computed(() => {
  const element = props.outcome.element
  const done = collocation.value
  if (element.kind !== 'collocation' || done === null) return []
  const sent = 'option_id' in props.outcome.answer ? props.outcome.answer.option_id : null

  return shownOptions(element.options, yourOptionId(done, sent), verdict.value, done.correct_option_ids)
})

/** The whole phrase cut around the right option, so that the word the learner needed can be marked in it. */
const phrase = computed((): { before: string; filled: string; after: string } | null => {
  const element = props.outcome.element
  const done = collocation.value
  if (element.kind !== 'collocation' || done === null) return null
  const parts = splitPhrase(element.text)
  const filled = parts === null ? null : filledWith(done.full_text, parts)

  return parts !== null && filled !== null ? { before: parts.before, filled, after: parts.after } : null
})

/** The end of a round of pairs: which round, the wrong matches in it, and its pairs (they have left the board one by one). */
const round = computed(() => {
  const element = props.outcome.element
  if (element.kind !== 'pairs_round') return null

  // The pairs matched before the last one are on the element; the last one is what was just answered.
  const matched = new Map(element.matched)
  const last = props.outcome.pair
  if (last !== null) matched.set(last.left_id, last.right_id)

  const pairs = element.left.flatMap((word) => {
    const translation = element.right.find((card) => card.id === matched.get(word.id))
    return translation === undefined ? [] : [{ id: word.id, word: word.text, translation: translation.text }]
  })

  return { round: element.round, rounds: element.rounds, mistakes: element.mismatches, pairs }
})

// ---- dictation: listen again ----

/**
 * The recording of the dictation that was just answered. The slot of the session screen is not ours: it already holds (or
 * is loading) the clip of the NEXT element - the screen fetches it while the verdict is read - so this review keeps a slot of
 * its own, fetches the answered clip when it appears (a tap cannot wait for a download on iOS) and gives it back when it goes.
 */
const clip = computed(() => (props.outcome.element.kind === 'dictation' ? props.outcome.element : null))
const replaySlot = reactive<AudioSlot>({ state: 'idle', blobUrl: null })
const replay = usePracticeAudio({ slot: replaySlot })
const replayLoading = computed((): boolean => replay.state.value === 'loading')
const replayFailed = computed((): boolean => replay.state.value === 'failed')

onMounted(() => {
  if (clip.value !== null) void replay.load(clip.value.audio_url)
})

onBeforeUnmount(() => replay.revoke())

function listenAgain(): void {
  if (replayFailed.value && clip.value !== null) {
    void replay.load(clip.value.audio_url, { force: true })
  } else if (replay.state.value === 'ready') {
    selectionHaptic()
    void replay.play()
  }
}

function onContinue(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="answer-reveal" :data-kind="outcome.element.kind" :data-verdict="isRoundEnd ? 'round' : verdict">
    <div class="flex flex-1 flex-col gap-3 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <span class="flex size-16 items-center justify-center rounded-full" :class="look.circle" data-testid="reveal-icon">
          <AppIcon :name="look.icon" solid :size="32" />
        </span>

        <template v-if="round">
          <h2 class="text-[22px] leading-7 font-bold" data-testid="reveal-text">
            {{ t('practice.session.round_done', { round: round.round, rounds: round.rounds }) }}
          </h2>
          <p class="text-base leading-[22px] text-tg-subtitle" data-testid="round-mistakes">
            {{ round.mistakes === 0 ? t('practice.session.round_clean') : t('practice.session.round_mistakes', { count: round.mistakes }) }}
          </p>
          <div v-if="round.pairs.length > 0" class="mt-1 w-full" data-testid="round-pairs">
            <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.pairs.recap') }}</p>
            <ul class="mt-1.5 flex flex-col gap-1.5 text-left" role="list">
              <li v-for="pair in round.pairs" :key="pair.id" class="flex items-baseline justify-between gap-3 text-base leading-[22px]" data-testid="round-pair">
                <span class="min-w-0 font-medium break-words">{{ pair.word }}</span>
                <span class="min-w-0 text-right text-tg-subtitle break-words">{{ pair.translation }}</span>
              </li>
            </ul>
          </div>
        </template>

        <template v-else>
          <h2 class="text-[22px] leading-7 font-bold" data-testid="reveal-text">{{ verdictLabel(outcome.element.kind, verdict) }}</h2>
          <p v-if="outcome.earlier" class="text-[15px] leading-5 text-tg-subtitle" data-testid="reveal-earlier">{{ t('practice.reveal.earlier') }}</p>
          <p v-else-if="verdict === 'typo'" class="text-[15px] leading-5 text-tg-subtitle" data-testid="reveal-note">{{ t('practice.reveal.typo_note') }}</p>
          <p v-else-if="verdict === 'skipped'" class="text-[15px] leading-5 text-tg-subtitle" data-testid="reveal-note">{{ t('practice.reveal.skipped_note') }}</p>
        </template>

        <!-- The right answer, as the task wants it written. -->
        <div v-if="dictation || sentence" class="mt-1 w-full">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('exercise.verdict.expected') }}</p>
          <p class="mt-1 text-2xl leading-8 font-bold break-words" data-testid="expected">{{ dictation?.expected ?? sentence?.correct_text }}</p>
        </div>
        <!-- The whole collocation: the phrase with the right option in the blank, the word the learner needed marked. -->
        <div v-if="collocation" class="mt-1 w-full">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('exercise.verdict.expected') }}</p>
          <p class="mt-1 font-bold break-words" :class="phraseSize(collocation.full_text)" lang="en" data-testid="expected">
            <template v-if="phrase">
              <span>{{ phrase.before }}</span>
              <span class="rounded-md bg-success-soft px-1 text-success-ink" data-testid="expected-fill">{{ phrase.filled }}</span>
              <span>{{ phrase.after }}</span>
            </template>
            <template v-else>{{ collocation.full_text }}</template>
          </p>
        </div>

        <!-- Dictation: the recording of the task again (the clip is fetched into a slot of this review). -->
        <button
          v-if="clip"
          type="button"
          class="btn btn-tinted"
          :disabled="replayLoading"
          :aria-busy="replayLoading ? 'true' : undefined"
          data-testid="listen-again-btn"
          @click="listenAgain"
        >
          <span
            v-if="replayLoading"
            class="size-5 animate-spin rounded-full border-2 border-current/30 border-t-current motion-reduce:animate-none"
            aria-hidden="true"
            data-testid="listen-again-spinner"
          />
          <AppIcon v-else :name="replayFailed ? 'arrow-path' : 'speaker-wave'" :size="20" />
          {{ replayFailed ? t('practice.audio.retry') : t('practice.dictation.listen_again') }}
        </button>

        <div v-if="yourText" class="w-full">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.your_answer') }}</p>
          <template v-if="diff">
            <!-- Word by word: a symbol AND a word for a screen reader beside the colour, the wrong ones underlined. -->
            <ul class="mt-1.5 flex flex-wrap justify-center gap-x-3 gap-y-1.5" data-testid="diff">
              <li
                v-for="(token, index) in diff"
                :key="index"
                class="inline-flex items-center gap-1 text-base leading-[22px]"
                :class="token.ok ? 'text-success-ink' : 'font-semibold text-danger-ink'"
                :data-ok="token.ok ? 'true' : 'false'"
                data-testid="diff-token"
              >
                <AppIcon :name="token.ok ? 'check' : 'x-mark'" :size="16" />
                <span :class="token.ok ? '' : 'underline decoration-danger decoration-2 underline-offset-4'" data-testid="diff-word">{{ token.got || '…' }}</span>
                <span class="sr-only">{{ token.ok ? t('practice.dictation.diff.right') : t('practice.dictation.diff.wrong') }}</span>
              </li>
            </ul>
            <p v-if="diff.some((token) => !token.ok)" class="mt-1.5 text-[13px] leading-[18px] text-tg-hint" data-testid="diff-hint">
              {{ t('practice.dictation.diff_hint') }}
            </p>
          </template>
          <p v-else class="mt-1 text-base leading-[22px] text-tg-hint line-through break-words" data-testid="your-text">{{ yourText }}</p>
        </div>

        <div v-if="sentence && sentence.alternatives.length > 0" class="w-full" data-testid="alternatives">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.sentence.also_correct') }}</p>
          <p v-for="alternative in sentence.alternatives" :key="alternative" class="mt-1 text-base leading-[22px] break-words" data-testid="alternative">{{ alternative }}</p>
        </div>
      </article>

      <!-- The three options of a collocation: the pick and the right one, each with a symbol and a caption besides the colour. -->
      <div v-if="options.length > 0" class="flex flex-col gap-2.5" role="group" data-testid="reveal-options">
        <div v-for="option in options" :key="option.id" class="option" :data-state="option.state" :data-testid="`reveal-option-${option.id}`">
          <span class="min-w-0 flex-1 break-words">
            <span class="block" lang="en">{{ option.text }}</span>
            <span
              v-if="option.caption"
              class="mt-0.5 block text-[13px] leading-[18px] font-normal first-letter:uppercase"
              :data-testid="`reveal-caption-${option.id}`"
            >
              {{ t(option.caption === 'your' ? 'practice.reveal.your_option' : 'practice.reveal.right_option') }}
            </span>
          </span>
          <AppIcon v-if="option.state === 'correct' || option.state === 'reveal'" name="check" :size="22" />
          <AppIcon v-else-if="option.state === 'incorrect'" name="x-mark" :size="22" />
        </div>
      </div>

      <section v-if="translation || explanation" class="card flex flex-col gap-3">
        <div v-if="translation" data-testid="translation">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.translation') }}</p>
          <p class="mt-0.5 text-base leading-[22px] italic">{{ translation }}</p>
        </div>
        <div v-if="explanation" data-testid="explanation">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.explanation') }}</p>
          <p class="mt-0.5 text-base leading-[22px]">{{ explanation }}</p>
        </div>
      </section>
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="onContinue">
        {{ last ? t('practice.session.to_summary') : t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
