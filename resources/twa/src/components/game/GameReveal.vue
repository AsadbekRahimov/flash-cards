<script setup lang="ts">
/**
 * What the server said about an answer in a run (docs/05 §5b.3), drawn as the answer of a lesson (docs/design Feedback): the verdict in
 * WORDS (in the ink of its colour; the colour is never alone), the explanation, and what the answer did to the run - the points, a flask
 * lost, the oasis that gave one back. "Next" is at the bottom, in the panel of the explanation (`StickyActions`, the page colour under a
 * hairline); on the last element it leads to the summary (`last`).
 *
 * - A pairs round, a collocation, a sentence and a dictation are explained by `AnswerReveal` of the practice, through the adapter.
 * - A task made of a word (`translate_*`, `listen`, `spell`, `gap`): above, the question again and - for a choice - its options in their
 *   states (the right one, a wrong pick, the rest dimmed); below, the right answer, for `gap` the whole example, the word with its
 *   translation and - after a typed miss - what the learner typed.
 * - An answer that was counted EARLIER (409 `answer_already_accepted`): only the verdict and "an answer sent earlier was counted".
 */
import { computed } from 'vue'
import { isPracticeElement, practiceOutcomeOf } from '@/components/game/practiceAdapter'
import AnswerReveal from '@/components/practice/AnswerReveal.vue'
import StickyActions from '@/components/StickyActions.vue'
import { gameVerdictLabel } from '@/composables/practiceLabels'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { GameElement, GameWordReveal } from '@/api/gameRunTypes'
import type { PracticeChoice, PracticeVerdict } from '@/api/types'
import type { GameOutcome } from '@/stores/gameRun'

const props = defineProps<{
  outcome: GameOutcome
  /** The next step is the summary, not another element. */
  last?: boolean
}>()

const emit = defineEmits<{ continue: [] }>()

const { haptic } = useTelegram()
const { t } = useI18n()

/** The verdict in words takes the ink of its colour (docs/design Feedback: "неверно" in the ink of danger, beside the right answer). */
const INKS: Record<PracticeVerdict, string> = {
  correct: 'text-success-ink',
  typo: 'text-warning-ink',
  wrong: 'text-danger-ink',
  skipped: 'text-muted',
}

const ink = computed((): string => INKS[props.outcome.verdict])

/** The practice components' explanation, when the element is one of their kinds. */
const practiceOutcome = computed(() => {
  const outcome = props.outcome
  return isPracticeElement(outcome.element) ? practiceOutcomeOf({ ...outcome, element: outcome.element }) : null
})

const word = computed((): GameWordReveal | null => {
  const reveal = props.outcome.reveal
  return reveal !== null && 'correct_text' in reveal ? (reveal as GameWordReveal) : null
})

/** The question again, smaller (docs/design Feedback): the word or the example of the task; `listen` has none (the clip was the question). */
const prompt = computed((): { text: string; lang: string | undefined } | null => {
  const element: GameElement = props.outcome.element
  switch (element.kind) {
    case 'translate_fwd':
      return { text: element.prompt, lang: 'en' }
    case 'translate_back':
    case 'spell':
      return { text: element.prompt, lang: undefined }
    case 'gap':
      return { text: element.masked, lang: 'en' }
    default:
      return null
  }
})

/** The language of the right answer: the learner's own for `translate_fwd` (its options are translations), English for the rest. */
const answerLang = computed((): string | undefined => (props.outcome.element.kind === 'translate_fwd' ? undefined : 'en'))

/**
 * The options of a choice as they were, each in its state (docs/design Feedback): the right one `correct` (or `reveal` when it was not the
 * pick) with "right answer", a wrong pick `incorrect` with "your answer", the rest `dimmed`. Not drawn for an answer counted earlier (nothing
 * is known of it) or when the element has no options.
 */
const options = computed((): { option: PracticeChoice; state: 'correct' | 'reveal' | 'incorrect' | 'dimmed'; tag: string }[] => {
  const { element, answer, earlier } = props.outcome
  if (earlier || !('options' in element) || isPracticeElement(element)) return []
  const picked = 'option_id' in answer ? answer.option_id : null
  const right = word.value?.correct_id ?? element.options.find((option) => option.text === word.value?.correct_text)?.id ?? (props.outcome.verdict === 'correct' ? picked : null)
  return element.options.map((option) => {
    if (option.id === right) {
      return option.id === picked ? { option, state: 'correct', tag: '' } : { option, state: 'reveal', tag: t('practice.reveal.right_option') }
    }
    if (option.id === picked) return { option, state: 'incorrect', tag: t('practice.reveal.your_option') }
    return { option, state: 'dimmed', tag: '' }
  })
})

/** What the learner typed (after a miss only); a pick of a choice is told by its option above. */
const yours = computed((): string | null => {
  const { answer, verdict } = props.outcome
  if (verdict === 'correct' || props.outcome.earlier) return null
  if ('text' in answer && answer.text.trim() !== '') return answer.text
  if ('option_id' in answer && options.value.length === 0 && 'options' in props.outcome.element) {
    return props.outcome.element.options.find((option) => option.id === answer.option_id)?.text ?? null
  }
  return null
})

const chips = computed(() => ({
  points: props.outcome.points > 0,
  flask: props.outcome.flaskLost,
  oasis: props.outcome.eventApplied === 'oasis',
}))
const hasChips = computed((): boolean => chips.value.points || chips.value.flask || chips.value.oasis)

function next(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="game-reveal">
    <!-- What the answer did to the run: the points, a flask lost, the oasis that gave it back. -->
    <div v-if="hasChips" class="flex flex-wrap justify-center gap-2 px-5 pt-3 short:pt-1" data-testid="reveal-chips">
      <span v-if="chips.points" class="chip chip-success font-mono" data-testid="chip-points">{{ t('gameRun.reveal.points', { points: outcome.points }) }}</span>
      <span v-if="chips.flask" class="chip chip-danger" data-testid="chip-flask">{{ t('gameRun.reveal.flask_lost') }}</span>
      <span v-if="chips.oasis" class="chip chip-accent" data-testid="chip-oasis">{{ t('gameRun.event.applied_oasis') }}</span>
    </div>

    <AnswerReveal v-if="practiceOutcome" :outcome="practiceOutcome" :last="last" @continue="emit('continue')" />

    <!-- The answer of a task made of a word (docs/design Feedback): the question and the options above, the verdict in words below. -->
    <template v-else>
      <div class="flex flex-col gap-6 px-5 pt-6 pb-4 short:gap-3 short:pt-2" data-testid="word-reveal">
        <div v-if="prompt" class="flex flex-col items-center gap-1.5 text-center">
          <p class="text-[13px] leading-[18px] text-muted short:hidden">{{ t(`gameRun.task.${outcome.element.kind}` as StringKey) }}</p>
          <p class="max-w-full text-[28px] leading-[34px] font-semibold tracking-[-0.3px] break-words short:text-[20px] short:leading-[26px]" :lang="prompt.lang" data-testid="reveal-prompt">{{ prompt.text }}</p>
        </div>

        <ul v-if="options.length > 0" class="flex flex-col gap-2.5 short:gap-2" data-testid="reveal-options">
          <li
            v-for="item in options"
            :key="item.option.id"
            class="option justify-between active:scale-100 short:min-h-11"
            :data-state="item.state"
            :data-testid="`reveal-option-${item.state}`"
          >
            <span class="min-w-0 break-words" :lang="answerLang">{{ item.option.text }}</span>
            <span v-if="item.tag" class="shrink-0 text-[13px] leading-[18px] font-normal">{{ item.tag }}</span>
          </li>
        </ul>
      </div>

      <StickyActions class="!gap-3 border-t border-hairline !pt-[18px]">
        <div class="flex flex-col gap-1">
          <div class="flex items-baseline justify-between gap-3">
            <p v-if="word" class="text-[13px] leading-[18px] text-muted">{{ t('gameRun.reveal.right') }}</p>
            <p class="ml-auto shrink-0 text-[13px] leading-[18px] font-semibold" :class="ink" :data-verdict="outcome.verdict" data-testid="verdict-label">{{ gameVerdictLabel(outcome.verdict) }}</p>
          </div>
          <p v-if="word" class="text-[18px] leading-6 font-semibold break-words" :lang="answerLang" data-testid="right-answer">{{ word.correct_text }}</p>
        </div>

        <p v-if="outcome.earlier" class="text-[15px] leading-5 text-muted" data-testid="earlier-note">{{ t('practice.reveal.earlier') }}</p>

        <div v-if="word?.full_text" class="flex flex-col gap-0.5" data-testid="full-text-block">
          <p class="text-[13px] leading-[18px] text-muted short:hidden">{{ t('gameRun.reveal.example') }}</p>
          <p class="text-[16px] leading-[22px] break-words italic" lang="en" data-testid="full-text">{{ word.full_text }}</p>
        </div>

        <div v-if="word && ((word.word && word.word !== word.correct_text) || word.translation)" class="flex flex-col gap-0.5 text-[14px] leading-5">
          <p v-if="word.word && word.word !== word.correct_text" class="break-words" data-testid="word-block">
            <span class="text-muted">{{ t('gameRun.reveal.word') }}:</span> <span lang="en" data-testid="word">{{ word.word }}</span>
          </p>
          <p v-if="word.translation" class="break-words" data-testid="translation-block">
            <span class="text-muted">{{ t('gameRun.reveal.translation') }}:</span> <span data-testid="translation">{{ word.translation }}</span>
          </p>
        </div>

        <p v-if="yours" class="text-[13px] leading-[18px] break-words text-muted" data-testid="your-answer">{{ t('practice.reveal.your_answer') }}: <span class="text-danger-ink">{{ yours }}</span></p>

        <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="next-btn" @click="next">
          {{ last ? t('gameRun.reveal.to_summary') : t('gameRun.reveal.next') }}
        </button>
      </StickyActions>
    </template>
  </div>
</template>
