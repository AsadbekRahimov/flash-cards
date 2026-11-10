<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Card } from '@/api/types'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * The pretest of a new word (docs/25 §3.12, docs/06 §12.7): "Do you know this word?" — the word with its sound and four
 * meanings, plus "I don't know". The pick (or the empty answer of "I don't know") goes to the server as `answer`; the
 * server grades it and the verdict screen shows the meaning. One pick per card.
 */
const props = defineProps<{
  card: Card
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'answer', text: string): void
}>()

const { haptic, selectionHaptic } = useTelegram()
const { speak } = useTts()
const { t } = useI18n()

const options = computed((): string[] => props.card.prompt?.options ?? [])
const picked = ref<number | null>(null)

function onSpeak(): void {
  selectionHaptic()
  void speak(props.card.word, 'en-US', props.card.audio_url)
}

function onPick(index: number): void {
  if (props.disabled || picked.value !== null) return
  const label = options.value[index]
  if (label === undefined) return
  picked.value = index
  haptic('medium')
  emit('answer', label)
}

function onSkip(): void {
  if (props.disabled || picked.value !== null) return
  picked.value = -1
  haptic('light')
  emit('answer', '')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="pretest-card">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ t('exercise.pretest.title') }}</p>
        <div class="flex items-center gap-3">
          <h2 class="text-3xl leading-9 font-bold break-words" data-testid="word">{{ card.word }}</h2>
          <button
            type="button"
            class="btn btn-tinted size-11 min-h-0 shrink-0 rounded-full p-0"
            :aria-label="t('card.speak')"
            data-testid="speak-btn"
            @click="onSpeak"
          >
            <AppIcon name="speaker-wave" :size="22" />
          </button>
        </div>
        <p v-if="card.transcription" class="text-[17px] leading-[22px] text-tg-hint" data-testid="transcription">
          {{ card.transcription }}
        </p>
      </article>

      <div class="flex flex-col gap-2.5" role="group" :aria-label="t('exercise.pretest.title')">
        <QuizOption
          v-for="(label, index) in options"
          :key="index"
          :label="label"
          :index="index"
          :state="picked === index ? 'selected' : 'idle'"
          :disabled="disabled || picked !== null"
          @pick="onPick"
        />
      </div>
    </div>

    <StickyActions>
      <button
        type="button"
        class="btn btn-tinted btn-lg btn-block"
        :disabled="disabled || picked !== null"
        data-testid="skip-btn"
        @click="onSkip"
      >
        {{ t('exercise.pretest.skip') }}
      </button>
    </StickyActions>
  </div>
</template>
