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
    <div class="flex flex-col px-5 pb-4">
      <!-- The question (docs/design Question): the word large on the white screen, its transcription and the speaker under it. -->
      <div class="mt-14 flex flex-col items-center gap-2.5 text-center short:mt-5 short:gap-1.5">
        <p class="text-[13px] leading-[18px] text-muted">{{ t('exercise.pretest.title') }}</p>
        <h2
          class="max-w-full text-[46px] leading-[52px] font-semibold tracking-[-0.6px] break-words short:text-[36px] short:leading-[42px]"
          lang="en"
          data-testid="word"
        >
          {{ card.word }}
        </h2>
        <div class="flex items-center gap-1">
          <span v-if="card.transcription" class="font-ipa text-[15px] leading-5 text-muted" data-testid="transcription">{{ card.transcription }}</span>
          <button
            type="button"
            class="flex size-11 shrink-0 items-center justify-center rounded-full text-accent active:bg-fill"
            :aria-label="t('card.speak')"
            data-testid="speak-btn"
            @click="onSpeak"
          >
            <AppIcon name="speaker-wave" :size="20" />
          </button>
        </div>
      </div>

      <div class="mt-11 flex flex-col gap-2.5 short:mt-4 short:gap-2" role="group" :aria-label="t('exercise.pretest.title')">
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

    <!-- "I don't know" is a quiet text button (docs/design Question): there is no main button, a pick is the answer. -->
    <StickyActions surface>
      <button
        type="button"
        class="btn btn-plain btn-lg btn-block text-[15px] text-muted"
        :disabled="disabled || picked !== null"
        data-testid="skip-btn"
        @click="onSkip"
      >
        {{ t('exercise.pretest.skip') }}
      </button>
    </StickyActions>
  </div>
</template>
