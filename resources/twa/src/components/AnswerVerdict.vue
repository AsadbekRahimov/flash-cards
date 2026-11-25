<script setup lang="ts">
import { computed } from 'vue'
import type { Graded } from '@/api/types'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * What the server said about a typed answer (spelling / gap-fill): right, close (within the
 * tolerance — the exact spelling is shown) or wrong (the right answer is shown). "Next" goes on
 * to the card the review answered with. The bottom is the answer panel of the four-choice cards
 * (docs/design/2026-11-25-redesign Feedback): a grey sheet with a rounded top; after a miss it says
 * whether the word comes back (`missNote`).
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

/** The icon in the coloured circle and the colours of the circle (soft background, "ink" glyph) per kind. */
const look = computed((): { icon: IconName; circle: string } => {
  switch (kind.value) {
    case 'correct':
      return { icon: 'check', circle: 'bg-success-soft text-success-ink' }
    case 'close':
      return { icon: 'exclamation-circle', circle: 'bg-warning-soft text-warning-ink' }
    default:
      return { icon: 'x-mark', circle: 'bg-danger-soft text-danger-ink' }
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
      The verdict of a typed answer in the language of the four-choice feedback (docs/design/2026-11-25-redesign Feedback): the
      learner's answer and the right one as two answer boxes, each named in words ("your answer" / "right answer"), so that the
      colour is never the only sign.
    -->
    <article class="mt-[30px] flex flex-col items-center gap-3 px-5 pb-4 text-center short:mt-4 short:gap-2">
      <span class="flex size-12 items-center justify-center rounded-full" :class="look.circle" data-testid="verdict-icon">
        <AppIcon :name="look.icon" solid :size="24" />
      </span>
      <h2 class="text-[22px] leading-7 font-extrabold" data-testid="verdict-text">{{ t(`exercise.verdict.${kind}`) }}</h2>

      <div v-if="kind !== 'correct'" class="mt-3 flex w-full flex-col gap-2.5 text-left short:mt-1 short:gap-2">
        <div
          v-if="answer"
          class="option min-h-[54px] cursor-default text-[18px] leading-6 active:scale-100 short:min-h-12"
          :data-state="kind === 'wrong' ? 'incorrect' : 'dimmed'"
          data-testid="own-answer-box"
        >
          <span class="min-w-0 flex-1 break-words line-through" lang="en" data-testid="own-answer">{{ answer }}</span>
          <span class="shrink-0 text-[13px] leading-[18px] font-normal">{{ t('exercise.option.yours') }}</span>
        </div>
        <div class="option min-h-[54px] cursor-default text-[18px] leading-6 active:scale-100 short:min-h-12" data-state="reveal" data-testid="expected-box">
          <span class="min-w-0 flex-1 text-[22px] leading-7 break-words" lang="en" data-testid="expected">{{ verdict.expected }}</span>
          <span class="shrink-0 text-[13px] leading-[18px] font-medium">{{ t('exercise.option.right') }}</span>
        </div>
      </div>
    </article>

    <StickyActions class="gap-3 rounded-t-hero border-t border-hairline">
      <p v-if="kind === 'wrong' && missNote" class="pt-1.5 text-[13px] leading-[18px] text-muted" data-testid="miss-note">{{ missNote }}</p>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="onContinue">
        {{ t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
