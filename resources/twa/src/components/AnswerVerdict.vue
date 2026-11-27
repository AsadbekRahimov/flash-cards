<script setup lang="ts">
import { computed } from 'vue'
import type { Graded } from '@/api/types'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import type { IconName } from '@/components/ui/icons'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * What the server said about a typed answer (spelling / gap-fill): right, close (within the
 * tolerance — the exact spelling is shown) or wrong (the right answer is shown). "Next" goes on
 * to the card the review answered with. The bottom is the feedback sheet of the four-choice cards
 * (kit Muhrla 05-urok-oshibka): the soft success / error colour, a round mark and the verdict in Title;
 * after a miss it says whether the word comes back (`missNote`).
 */
const props = defineProps<{
  verdict: Graded
  /** The learner's own text, shown crossed out above the right one after a miss. */
  answer?: string
  /** The line above "Next" after a miss: the word comes back in this lesson / round, or a neutral one (the screen decides). */
  missNote?: string | null
}>()

const emit = defineEmits<{
  (e: 'continue'): void
}>()

const { haptic } = useTelegram()
const { t } = useI18n()

const kind = computed((): 'correct' | 'close' | 'wrong' =>
  props.verdict.quality >= 5 ? 'correct' : props.verdict.correct ? 'close' : 'wrong',
)

/**
 * The look per kind: the tone of the feedback sheet, the icon in the round mark and its fill, the colour of the title. "Close" is a right
 * answer with a slip: the success sheet with the warm mark of a warning.
 */
const look = computed((): { tone: 'success' | 'danger'; icon: IconName; circle: string; ink: string } => {
  switch (kind.value) {
    case 'correct':
      return { tone: 'success', icon: 'check', circle: 'bg-success text-on-primary', ink: 'text-success-ink' }
    case 'close':
      return { tone: 'success', icon: 'exclamation-circle', circle: 'bg-warning text-on-primary', ink: 'text-warning-ink' }
    default:
      return { tone: 'danger', icon: 'x-mark', circle: 'bg-danger text-on-primary', ink: 'text-danger-ink' }
  }
})

function onContinue(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="answer-verdict" :data-kind="kind">
    <!--
      The answers as the options of the question show them (kit 05): the learner's answer and the right one as two answer boxes, each
      named in words ("your answer" / "right answer"), so that the colour is never the only sign. A right answer has the mascot instead.
    -->
    <article class="mt-6 flex flex-col gap-2.5 px-5 pb-4 short:mt-4 short:gap-2">
      <MascotArt v-if="kind === 'correct'" pose="celebrate" :height="112" class="self-center short:hidden" />
      <p v-else class="t-body-m text-center text-muted">{{ t('exercise.verdict.expected') }}</p>
      <div
        v-if="answer && kind !== 'correct'"
        class="option min-h-[60px] cursor-default active:scale-100 short:min-h-12"
        :data-state="kind === 'wrong' ? 'incorrect' : 'dimmed'"
        data-testid="own-answer-box"
      >
        <span class="min-w-0 flex-1 break-words line-through" lang="en" data-testid="own-answer">{{ answer }}</span>
        <span class="t-label-s shrink-0 font-normal">{{ t('exercise.option.yours') }}</span>
        <AppIcon v-if="kind === 'wrong'" name="x-mark" :size="22" class="option-mark shrink-0 text-danger-ink" />
      </div>
      <div v-if="kind !== 'correct'" class="option min-h-[60px] cursor-default active:scale-100 short:min-h-12" data-state="reveal" data-testid="expected-box">
        <span class="min-w-0 flex-1 font-display text-[22px] leading-7 font-semibold break-words" lang="en" data-testid="expected">{{ verdict.expected }}</span>
        <span class="t-label-s shrink-0">{{ t('exercise.option.right') }}</span>
        <AppIcon name="check" :size="22" class="option-mark shrink-0 text-success-ink" />
      </div>
    </article>

    <StickyActions :tone="look.tone">
      <div class="flex items-center gap-3">
        <span class="option-mark flex size-7 shrink-0 items-center justify-center rounded-full" :class="look.circle" data-testid="verdict-icon">
          <AppIcon :name="look.icon" solid :size="18" />
        </span>
        <h2 class="t-title-m min-w-0 break-words" :class="look.ink" data-testid="verdict-text">{{ t(`exercise.verdict.${kind}`) }}</h2>
      </div>
      <p v-if="kind === 'wrong' && missNote" class="t-label-s mt-1.5 text-muted" data-testid="miss-note">{{ missNote }}</p>
      <button type="button" class="btn btn-primary btn-block mt-3" data-testid="continue-btn" @click="onContinue">
        {{ t('exercise.continue') }}
        <AppIcon name="arrow-right" :size="20" />
      </button>
    </StickyActions>
  </div>
</template>
