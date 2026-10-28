<script setup lang="ts">
import { computed } from 'vue'
import type { Card, Quality } from '@/api/types'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useTts } from '@/composables/useTts'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * The flash card (docs/14 §6.2): the card with the word in the middle of the screen, the actions in the sticky
 * block below. Before the reveal there is one button, "Show translation"; after it the learner grades how well he
 * remembered the word. The grades are 2 / 4 / 5 (Again / Good / Easy, docs/06 §2) under the labels "I don't remember" /
 * "I remember" / "Easy", each with a hint of what happens next. The hint is small (12 px), so it is not faded with
 * `opacity` (that was 2.4-3.5:1 in the light themes): it is the colour of its button mixed half and half with the text
 * colour of the theme, 4.8:1 and better on the soft backgrounds of the light and the dark Telegram themes (docs/14 §4.1).
 */
const props = defineProps<{
  card: Card
  showTranslation: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'reveal'): void
  (e: 'grade', quality: Quality): void
}>()

const { speak } = useTts()
const { haptic, selectionHaptic } = useTelegram()
const { t } = useI18n()

const kind = computed(() => {
  switch (props.card.card_kind) {
    case 'new':
      return { label: t('card.kind.new'), chip: 'chip-accent' }
    case 'learning':
      return { label: t('card.kind.learning'), chip: 'chip-warning' }
    default:
      return { label: t('card.kind.due'), chip: 'chip-neutral' }
  }
})

/** The three grades, left to right; the colours are the soft tokens of the design system. */
const grades = computed(() => [
  { quality: 2 as const, testid: 'grade-hard', label: t('card.grade.hard'), hint: t('card.grade.hard_hint'), tone: 'btn-danger' },
  { quality: 4 as const, testid: 'grade-medium', label: t('card.grade.medium'), hint: t('card.grade.medium_hint'), tone: 'btn-tinted' },
  { quality: 5 as const, testid: 'grade-easy', label: t('card.grade.easy'), hint: t('card.grade.easy_hint'), tone: 'bg-success-soft text-success-ink' },
])

function onSpeak(): void {
  selectionHaptic()
  void speak(props.card.word, 'en-US', props.card.audio_url)
}

function onReveal(): void {
  haptic('light')
  emit('reveal')
}

function onGrade(q: Quality): void {
  if (props.disabled) return
  haptic('medium')
  emit('grade', q)
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="flashcard">
    <div class="flex flex-1 flex-col justify-center px-4 py-4">
      <article class="card flex flex-col gap-4 px-5 py-5">
        <header class="flex items-center justify-between">
          <span class="chip" :class="kind.chip" data-testid="card-kind">{{ kind.label }}</span>
          <button
            type="button"
            class="btn btn-tinted size-11 min-h-0 shrink-0 rounded-full p-0"
            :aria-label="t('card.speak')"
            data-testid="speak-btn"
            @click="onSpeak"
          >
            <AppIcon name="speaker-wave" :size="22" />
          </button>
        </header>

        <div class="px-1 py-3 text-center">
          <h2 class="text-4xl leading-10 font-bold break-words" data-testid="word">{{ card.word }}</h2>
          <p v-if="card.transcription" class="mt-1.5 text-[17px] leading-[22px] text-tg-hint" data-testid="transcription">
            {{ card.transcription }}
          </p>
        </div>

        <template v-if="showTranslation">
          <hr class="border-hairline" />
          <div class="px-1 pb-1 text-center">
            <p class="text-2xl leading-8 font-semibold break-words" data-testid="translation">{{ card.translation }}</p>
            <p
              v-if="card.translation_fallback"
              class="mt-1 text-[13px] leading-[18px] text-tg-hint"
              data-testid="translation-fallback"
            >
              {{ t('card.translation_fallback') }}
            </p>
            <p v-if="card.example" class="mt-3 text-base leading-[22px] text-tg-subtitle italic" data-testid="example">
              {{ card.example }}
            </p>
          </div>
        </template>
      </article>
    </div>

    <StickyActions>
      <button
        v-if="!showTranslation"
        type="button"
        class="btn btn-primary btn-lg btn-block"
        data-testid="reveal-btn"
        :disabled="disabled"
        @click="onReveal"
      >
        {{ t('card.reveal') }}
      </button>

      <template v-else>
        <p class="text-center text-[15px] leading-5 text-tg-subtitle" data-testid="grade-prompt">{{ t('card.grade_prompt') }}</p>
        <div class="grid grid-cols-3 gap-2" role="group" :aria-label="t('card.grade_group')">
          <button
            v-for="grade in grades"
            :key="grade.quality"
            type="button"
            class="btn min-h-[72px] flex-col gap-0.5 px-1 py-2.5"
            :class="grade.tone"
            :data-testid="grade.testid"
            :disabled="disabled"
            @click="onGrade(grade.quality)"
          >
            <span class="text-[15px] leading-5 font-semibold">{{ grade.label }}</span>
            <span class="text-xs leading-4 font-normal text-[color-mix(in_srgb,currentColor_50%,var(--tg-text))]" data-testid="grade-hint">{{ grade.hint }}</span>
          </button>
        </div>
      </template>
    </StickyActions>
  </div>
</template>
