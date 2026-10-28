<script setup lang="ts">
import { computed, onMounted } from 'vue'
import type { Card, Quality } from '@/api/types'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useChoice } from '@/composables/useChoice'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * Audio exercise (docs/13 §2.5): the word is only heard (`card.audio_url`, else the TTS fallback),
 * the learner picks its translation among four. The word itself is shown only after the pick.
 * Graded here — the right answer is `card.translation` — and sent as `quality`.
 * A right pick moves on by itself; a wrong one stays on screen (the right option, the word and the example are
 * shown) until "Next" (docs/14 N6).
 */
const props = defineProps<{
  card: Card
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'grade', quality: Quality): void
}>()

const { haptic, notificationHaptic, selectionHaptic } = useTelegram()
const { speak } = useTts()
const { t } = useI18n()

const options = computed((): string[] => props.card.prompt?.options ?? [])

const { picked, stateOf, isCorrect, awaitingConfirm, pick, confirm } = useChoice(
  options,
  computed(() => props.card.translation),
  (quality) => {
    if (props.disabled) return
    emit('grade', quality)
  },
)

function play(): void {
  void speak(props.card.word, 'en-US', props.card.audio_url)
}

function onPlay(): void {
  selectionHaptic()
  play()
}

function onPick(index: number): void {
  if (props.disabled || picked.value !== null) return
  pick(index)
  notificationHaptic(isCorrect.value ? 'success' : 'error')
  haptic('medium')
}

function onContinue(): void {
  haptic('light')
  confirm()
}

// Autoplay: a tap opened the training, so the browser allows the sound; a blocked play is silent.
onMounted(play)
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="audio-card">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ t('exercise.audio.title') }}</p>
        <button
          type="button"
          class="flex size-[72px] shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent transition-transform duration-150 active:scale-95"
          :aria-label="t('exercise.audio.play')"
          data-testid="play-btn"
          @click="onPlay"
        >
          <AppIcon name="speaker-wave" :size="36" />
        </button>
        <!-- The slot keeps its height: the word that replaces the hint after the pick must not push the options down. -->
        <div class="flex min-h-[64px] flex-col items-center justify-center">
          <template v-if="picked !== null">
            <h2 class="text-3xl leading-9 font-bold break-words" data-testid="word">{{ card.word }}</h2>
            <p v-if="card.transcription" class="mt-0.5 text-[17px] leading-[22px] text-tg-hint" data-testid="transcription">
              {{ card.transcription }}
            </p>
          </template>
          <p v-else class="text-[13px] leading-[18px] text-tg-hint" data-testid="replay-hint">{{ t('exercise.audio.replay_hint') }}</p>
        </div>
      </article>

      <div class="flex flex-col gap-2.5" role="group" :aria-label="t('exercise.audio.title')">
        <QuizOption
          v-for="(label, index) in options"
          :key="index"
          :label="label"
          :index="index"
          :state="stateOf(index)"
          :disabled="disabled || picked !== null"
          @pick="onPick"
        />
      </div>

      <p v-if="picked !== null && card.example" class="px-2 text-center text-base leading-[22px] text-tg-subtitle italic" data-testid="example">
        {{ card.example }}
      </p>
    </div>

    <StickyActions v-if="awaitingConfirm">
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" :disabled="disabled" @click="onContinue">
        {{ t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
