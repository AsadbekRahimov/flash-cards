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
 * to the card the review answered with.
 */
const props = defineProps<{
  verdict: Graded
  /** The learner's own text, shown crossed out above the right one after a miss. */
  answer?: string
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
    <div class="flex flex-1 flex-col justify-center px-4 py-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-8 text-center">
        <span class="flex size-16 items-center justify-center rounded-full" :class="look.circle" data-testid="verdict-icon">
          <AppIcon :name="look.icon" solid :size="32" />
        </span>
        <h2 class="text-[22px] leading-7 font-bold" data-testid="verdict-text">{{ t(`exercise.verdict.${kind}`) }}</h2>

        <template v-if="kind !== 'correct'">
          <div class="mt-1 w-full">
            <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">
              {{ t('exercise.verdict.expected') }}
            </p>
            <p class="mt-1 text-3xl leading-9 font-bold break-words" data-testid="expected">{{ verdict.expected }}</p>
          </div>
          <p v-if="answer" class="text-base leading-[22px] text-tg-hint line-through break-words" data-testid="own-answer">{{ answer }}</p>
        </template>
      </article>
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="onContinue">
        {{ t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
