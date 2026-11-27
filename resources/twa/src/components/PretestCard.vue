<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Card } from '@/api/types'
import PhraseLabels from '@/components/PhraseLabels.vue'
import LessonWord from '@/components/LessonWord.vue'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import IconButton from '@/components/ui/IconButton.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * The pretest of a new word (docs/25 §3.12, docs/06 §12.7): "Do you know this word?" — the word with its sound and four
 * meanings, plus "I don't know". The pick (or the empty answer of "I don't know") goes to the server as `answer`; the
 * server grades it and the verdict screen shows the meaning. One pick per card. A phrase card (`card.phrase`, docs/35 §3.3 d): the
 * phrase takes a size by its length, its labels are under it, the options are long.
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
const phrase = computed(() => props.card.phrase ?? null)
const title = computed((): string => t(phrase.value ? 'exercise.pretest.title_phrase' : 'exercise.pretest.title'))
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
      <!--
        The question (kit Muhrla 04-urok-vopros): the mascot thinking (not on a short window), the task in words, the word in Word/XL,
        its transcription and the speaker beside it.
      -->
      <div class="mt-5 flex flex-col items-center gap-2 text-center short:mt-3 short:gap-1.5">
        <MascotArt pose="thinking" :height="88" class="short:hidden" />
        <p class="t-body-m text-muted" data-testid="exercise-title">{{ title }}</p>
        <LessonWord :text="card.word" :phrase="phrase !== null" lang="en" data-testid="word" />
        <PhraseLabels v-if="phrase" :phrase="phrase" />
        <div class="flex items-center gap-3">
          <span v-if="card.transcription" class="font-ipa text-[17px] leading-6 text-muted" data-testid="transcription">{{ card.transcription }}</span>
          <IconButton icon="speaker-wave" :label="t('card.speak')" data-testid="speak-btn" @click="onSpeak" />
        </div>
      </div>

      <div class="mt-6 flex flex-col gap-2.5 short:mt-4 short:gap-2" role="radiogroup" :aria-label="title">
        <QuizOption
          v-for="(label, index) in options"
          :key="index"
          :label="label"
          :index="index"
          :state="picked === index ? 'selected' : 'idle'"
          :disabled="disabled || picked !== null"
          :long="phrase !== null"
          @pick="onPick"
        />
      </div>
    </div>

    <!-- "I don't know" is the Ghost button of the kit: there is no main button, a pick is the answer. -->
    <StickyActions>
      <button
        type="button"
        class="btn btn-ghost btn-block"
        :disabled="disabled || picked !== null"
        data-testid="skip-btn"
        @click="onSkip"
      >
        {{ t('exercise.pretest.skip') }}
      </button>
    </StickyActions>
  </div>
</template>
