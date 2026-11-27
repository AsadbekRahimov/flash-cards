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
import AppIcon from '@/components/ui/AppIcon.vue'
import { isLongGameText } from '@/composables/phraseText'
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
  typo: 'text-accent-ink',
  wrong: 'text-danger-ink',
  skipped: 'text-muted',
}

const ink = computed((): string => INKS[props.outcome.verdict])

/** The colour of the feedback sheet: the soft success / error of the kit for a right / wrong answer; the surface for the rest. */
const sheetTone = computed((): string => {
  if (props.outcome.verdict === 'correct') return '!border-transparent !bg-success-soft'
  return props.outcome.verdict === 'wrong' ? '!border-transparent !bg-danger-soft' : ''
})

/** The key of an option after the check, as QuizOption draws it: the success / error ink of the verdict, a quiet ring for the rest. */
function keyTone(state: 'correct' | 'reveal' | 'incorrect' | 'dimmed'): string {
  if (state === 'incorrect') return 'bg-danger-ink text-on-inverse'
  if (state === 'dimmed') return 'bg-transparent text-muted shadow-[inset_0_0_0_1px_var(--app-divider)]'
  return 'bg-success-ink text-on-inverse'
}

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

/** A phrase (docs/35 §3.3 e) as the question again: a size smaller, wrapping by words. */
const longPrompt = computed((): boolean => prompt.value !== null && isLongGameText(prompt.value.text))

/** The word and its translation under the right answer, unless they only repeat it or the question (`apple` → `яблоко`, right answer `яблоко`). */
const same = (a: string | null | undefined, b: string | null | undefined): boolean => (a ?? '').trim() === (b ?? '').trim()
const showWord = computed((): boolean => !!word.value?.word && !same(word.value.word, word.value.correct_text) && !same(word.value.word, prompt.value?.text))
const showTranslation = computed((): boolean => !!word.value?.translation && !same(word.value.translation, word.value.correct_text))

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
          <p class="t-body-m text-muted short:hidden">{{ t(`gameRun.task.${outcome.element.kind}` as StringKey) }}</p>
          <p
            class="max-w-full font-display font-semibold tracking-[-0.02em] break-words"
            :class="longPrompt ? 'text-[20px] leading-[26px] text-balance short:text-[18px] short:leading-6' : 'text-[30px] leading-9 short:text-[22px] short:leading-7'"
            :lang="prompt.lang"
            :data-long="longPrompt ? 'true' : undefined"
            data-testid="reveal-prompt"
          >{{ prompt.text }}</p>
        </div>

        <ul v-if="options.length > 0" class="flex flex-col gap-2.5 short:gap-2" data-testid="reveal-options">
          <!-- The answer options of the kit after the check: the key, the label, the word of the verdict and its mark (a check / a cross) - never the colour alone. -->
          <li
            v-for="(item, index) in options"
            :key="item.option.id"
            class="option min-h-[60px] active:scale-100 short:min-h-12"
            :data-state="item.state"
            :data-testid="`reveal-option-${item.state}`"
          >
            <span class="flex size-7 shrink-0 items-center justify-center self-center rounded-xs text-[15px] leading-5 font-medium" :class="keyTone(item.state)" aria-hidden="true">{{ index + 1 }}</span>
            <span class="min-w-0 flex-1 break-words" :lang="answerLang">{{ item.option.text }}</span>
            <span v-if="item.tag" class="shrink-0 text-[13px] leading-[18px]" :class="item.state === 'incorrect' ? 'font-normal text-danger-ink' : 'font-medium text-success-ink'">{{ item.tag }}</span>
            <AppIcon
              v-if="item.state !== 'dimmed'"
              :name="item.state === 'incorrect' ? 'x-mark' : 'check'"
              :size="22"
              class="option-mark shrink-0"
              :class="item.state === 'incorrect' ? 'text-danger-ink' : 'text-success-ink'"
              aria-hidden="true"
              data-testid="reveal-mark"
            />
          </li>
        </ul>
      </div>

      <!-- The feedback sheet of the kit (05-urok-oshibka): the top corners 28, the soft colour of the verdict (a right answer - success, a miss - error). -->
      <StickyActions class="!gap-3 rounded-t-hero border-t border-hairline !px-5 !pt-5" :class="sheetTone" data-testid="actions" :data-tone="outcome.verdict">
        <div class="flex flex-col gap-1">
          <div class="flex items-baseline justify-between gap-3">
            <p v-if="word" class="t-label-s text-muted">{{ t('gameRun.reveal.right') }}</p>
            <p class="t-label-s ml-auto flex shrink-0 items-center gap-1 font-semibold" :class="ink" :data-verdict="outcome.verdict" data-testid="verdict-label">
              <AppIcon v-if="outcome.verdict === 'correct' || outcome.verdict === 'wrong'" :name="outcome.verdict === 'correct' ? 'check' : 'x-mark'" :size="16" class="shrink-0" />
              {{ gameVerdictLabel(outcome.verdict) }}
            </p>
          </div>
          <p v-if="word" class="t-title-m break-words" :lang="answerLang" data-testid="right-answer">{{ word.correct_text }}</p>
        </div>

        <p v-if="outcome.earlier" class="text-[15px] leading-5 text-muted" data-testid="earlier-note">{{ t('practice.reveal.earlier') }}</p>

        <div v-if="word?.full_text" class="flex flex-col gap-0.5" data-testid="full-text-block">
          <p class="text-[13px] leading-[18px] text-muted short:hidden">{{ t('gameRun.reveal.example') }}</p>
          <p class="text-[16px] leading-[22px] break-words italic" lang="en" data-testid="full-text">{{ word.full_text }}</p>
        </div>

        <div v-if="showWord || showTranslation" class="flex flex-col gap-0.5 text-[14px] leading-5">
          <p v-if="showWord" class="break-words" data-testid="word-block">
            <span class="text-muted">{{ t('gameRun.reveal.word') }}:</span> <span lang="en" data-testid="word">{{ word?.word }}</span>
          </p>
          <p v-if="showTranslation" class="break-words" data-testid="translation-block">
            <span class="text-muted">{{ t('gameRun.reveal.translation') }}:</span> <span data-testid="translation">{{ word?.translation }}</span>
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
