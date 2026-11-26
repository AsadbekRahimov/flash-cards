<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { Card, Quality } from '@/api/types'
import CardHooks from '@/components/CardHooks.vue'
import ExampleAudioButton from '@/components/ExampleAudioButton.vue'
import PhraseLabels from '@/components/PhraseLabels.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAutoSpeak } from '@/composables/useAutoSpeak'
import { phraseHeadingClass } from '@/composables/phraseText'
import { useSayAloudHint } from '@/composables/useSayAloudHint'
import { useTts } from '@/composables/useTts'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * The flash card (docs/14 §6.2, docs/design Question): the word large on the white screen of the lesson, no box around
 * it, the actions in the sticky block below. Before the reveal there is one button, "Show translation"; after it the learner grades how well he
 * remembered the word. The grades are 2 / 4 / 5 (Again / Good / Easy, docs/06 §2) under the labels "I don't remember" /
 * "I remember" / "Easy", each with a hint of what happens next. The hint is small (12 px), so it is not faded with
 * `opacity` (that was 2.4-3.5:1 in the light themes): it is the colour of its button mixed half and half with the text
 * colour of the palette, 4.5:1 and better on the soft backgrounds of the light and the dark palette (docs/14 §4.1).
 *
 * A `new` word has never been seen, so there is nothing to recall: the translation and the example are open at once
 * ("Learn this word"), the same three grades are sent (2 / 4 / 5) under the labels "Not yet" / "Got it" / "Knew it", and the
 * hints are left out: for a new word the engine puts 2 and 4 back within minutes and 5 after days (docs/06 §3.2), which
 * the grown-up hints "today / on schedule / rarer" would not describe.
 *
 * The method (docs/06 §12): a hard word (`is_leech`) carries the "Hard word" chip and its hooks are open before the
 * reveal — a cued recall is what a word that does not stick needs (docs/25 §3.3); otherwise the hooks (the authored one,
 * "my hint", the confusable pair) come with the translation. "My hint" can be edited from the card (`edit-note`). On every
 * third card a quiet "say the word aloud" line sits under the speaker (docs/25 §3.11), unless switched off in the settings.
 *
 * The word is read aloud by itself (unless the learner switched "auto-speak" off): when a new word opens, and at the reveal of a
 * review card — never before it, the word is what is being recalled. The translation of the example comes with the example.
 *
 * A phrase card (`card.phrase`, docs/35 §3.3 d): the phrase takes a size by its length and wraps by words, its labels (register, UK / US,
 * kind) are under it, and «Буквально: …» comes with the translation.
 */
/**
 * The answer is acknowledged on the spot (docs/14 §6.2): the pressed grade is lit while the answer is on its way, and the
 * "got it" / "knew it" grades give a short pulse with a check. "Not yet" is an honest grade, not a failure: its button
 * turns calm (the accent, not red) and shows the "again" arrow. Nothing waits for it — the grade is emitted at once — and the
 * pulse is switched off by `prefers-reduced-motion` (the lit button stays).
 */
const props = defineProps<{
  card: Card
  showTranslation: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'reveal'): void
  (e: 'grade', quality: Quality): void
  (e: 'edit-note'): void
}>()

const { speak } = useTts()
const { haptic, selectionHaptic } = useTelegram()
const { t } = useI18n()
const { show: sayAloud } = useSayAloudHint()
const { speakWord } = useAutoSpeak()

const isNew = computed(() => props.card.card_kind === 'new')
/** A new word shows its translation at once. */
const revealed = computed(() => props.showTranslation || isNew.value)
const isLeech = computed(() => props.card.is_leech === true)
const phrase = computed(() => props.card.phrase ?? null)
/** The hooks: with the translation, or before it for a hard word. */
const hooksOpen = computed(() => revealed.value || isLeech.value)

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

/**
 * "Easy" in the soft success colours, framed like the danger button: a thin line of its colour, inset, so the size does not change. The
 * frame steps aside once the grade is pressed: `grade-picked` draws its own.
 */
const EASY_TONE = 'bg-success-soft text-success-ink'
const EASY_FRAME = 'shadow-[inset_0_0_0_1px_var(--app-success-line)]'

/** The three grades, left to right; the colours are the soft tokens of the design system. */
const grades = computed(() => isNew.value
  ? [
      { quality: 2 as const, testid: 'grade-hard', label: t('card.grade_new.hard'), hint: '', tone: 'btn-danger' },
      { quality: 4 as const, testid: 'grade-medium', label: t('card.grade_new.medium'), hint: '', tone: 'btn-tinted' },
      { quality: 5 as const, testid: 'grade-easy', label: t('card.grade_new.easy'), hint: '', tone: EASY_TONE },
    ]
  : [
      { quality: 2 as const, testid: 'grade-hard', label: t('card.grade.hard'), hint: t('card.grade.hard_hint'), tone: 'btn-danger' },
      { quality: 4 as const, testid: 'grade-medium', label: t('card.grade.medium'), hint: t('card.grade.medium_hint'), tone: 'btn-tinted' },
      { quality: 5 as const, testid: 'grade-easy', label: t('card.grade.easy'), hint: t('card.grade.easy_hint'), tone: EASY_TONE },
    ])

function onSpeak(): void {
  selectionHaptic()
  void speak(props.card.word, 'en-US', props.card.audio_url)
}

// A new word is open at once: it is spoken as it appears (after the tone of the previous grade). A review card is silent until
// the reveal, then the word sounds.
onMounted(() => {
  if (isNew.value) speakWord(props.card.word, props.card.audio_url, true)
})
watch(
  () => props.showTranslation,
  (on) => {
    if (on && !isNew.value) speakWord(props.card.word, props.card.audio_url)
  },
)

function onReveal(): void {
  haptic('light')
  emit('reveal')
}

/** The grade pressed on this card (a new serve is a new component, so it starts empty). */
const picked = ref<Quality | null>(null)

function gradeClasses(grade: { quality: Quality; tone: string }): string[] {
  if (picked.value !== grade.quality) return grade.quality === 5 ? [grade.tone, EASY_FRAME] : [grade.tone]
  return grade.quality === 2 ? ['btn-tinted', 'grade-picked'] : [grade.tone, 'grade-picked', 'grade-pulse']
}

function onGrade(q: Quality): void {
  if (props.disabled) return
  picked.value = q
  haptic('medium')
  emit('grade', q)
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="flashcard">
    <!-- One family with the other exercises (docs/design Question): the word large on the white screen, no box around it. -->
    <article class="flex flex-col items-center px-5 pt-[30px] pb-4 text-center short:pt-4">
      <span class="flex flex-wrap items-center justify-center gap-1.5">
        <span class="chip" :class="kind.chip" data-testid="card-kind">{{ kind.label }}</span>
        <span v-if="isLeech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
      </span>

      <h2
        v-if="phrase"
        class="mt-4 max-w-full font-extrabold text-balance break-words short:mt-2"
        :class="phraseHeadingClass(card.word)"
        lang="en"
        data-testid="word"
      >
        {{ card.word }}
      </h2>
      <h2
        v-else
        class="mt-4 max-w-full text-[52px] leading-[58px] font-extrabold tracking-[-1px] break-words short:mt-2 short:text-[40px] short:leading-[46px]"
        lang="en"
        data-testid="word"
      >
        {{ card.word }}
      </h2>
      <PhraseLabels v-if="phrase" :phrase="phrase" class="mt-2" />
      <div class="mt-2 flex items-center gap-2.5">
        <span v-if="card.transcription" class="font-ipa text-base leading-[22px] text-muted" data-testid="transcription">{{ card.transcription }}</span>
        <button
          type="button"
          class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent active:bg-fill"
          :aria-label="t('card.speak')"
          data-testid="speak-btn"
          @click="onSpeak"
        >
          <AppIcon name="speaker-wave" :size="22" />
        </button>
      </div>
      <p v-if="sayAloud" class="text-[13px] leading-[18px] text-muted" data-testid="say-aloud">{{ t('card.say_aloud') }}</p>

      <template v-if="revealed">
        <hr class="mt-5 w-full border-divider short:mt-3" />
        <div class="w-full pt-5 short:pt-3">
          <p class="text-2xl leading-[30px] font-bold break-words" data-testid="translation">{{ card.translation }}</p>
          <p v-if="card.translation_fallback" class="mt-1 text-[13px] leading-[18px] text-muted" data-testid="translation-fallback">
            {{ t('card.translation_fallback') }}
          </p>
          <p v-if="phrase?.literal" class="mt-1.5 text-[15px] leading-5 break-words" data-testid="phrase-literal">
            <span class="text-muted">{{ t('phrase.literal') }}:</span>
            {{ phrase.literal }}
          </p>
          <!-- The speaker of the example (docs/26 §9.11) on its first line; the sentence wraps beside it. -->
          <div v-if="card.example" class="mt-3 flex items-start justify-center gap-2.5">
            <p class="min-w-0 text-[17px] leading-[23px] break-words italic" lang="en" data-testid="example">
              {{ card.example }}
            </p>
            <ExampleAudioButton v-if="card.example_audio_url" :url="card.example_audio_url" class="-mt-2.5" />
          </div>
          <p v-if="card.example && card.example_translation" class="mt-1 text-sm leading-5 text-muted" data-testid="example-translation">
            {{ card.example_translation }}
          </p>
        </div>
      </template>

      <template v-if="hooksOpen">
        <hr v-if="!revealed" class="mt-5 w-full border-divider short:mt-3" />
        <CardHooks :card="card" :editable="revealed" class="mt-4 w-full" @edit-note="emit('edit-note')" />
      </template>
    </article>

    <StickyActions surface>
      <button
        v-if="!revealed"
        type="button"
        class="btn btn-primary btn-lg btn-block"
        data-testid="reveal-btn"
        :disabled="disabled"
        @click="onReveal"
      >
        {{ t('card.reveal') }}
      </button>

      <template v-else>
        <p class="text-center text-[13px] leading-[18px] text-muted" data-testid="grade-prompt">{{ t(isNew ? 'card.grade_prompt_new' : 'card.grade_prompt') }}</p>
        <div class="grid grid-cols-3 gap-2" role="group" :aria-label="t('card.grade_group')">
          <button
            v-for="grade in grades"
            :key="grade.quality"
            type="button"
            class="btn min-h-[72px] flex-col gap-0.5 px-1 py-2.5"
            :class="gradeClasses(grade)"
            :data-testid="grade.testid"
            :data-picked="picked === grade.quality ? 'true' : undefined"
            :disabled="disabled"
            @click="onGrade(grade.quality)"
          >
            <span class="flex items-center gap-1 text-[15px] leading-5 font-semibold">
              <AppIcon v-if="picked === grade.quality" :name="grade.quality === 2 ? 'arrow-path' : 'check'" :size="16" data-testid="grade-mark" />
              {{ grade.label }}
            </span>
            <span v-if="grade.hint" class="text-xs leading-4 font-normal text-[color-mix(in_srgb,currentColor_50%,var(--app-text))]" data-testid="grade-hint">{{ grade.hint }}</span>
          </button>
        </div>
      </template>
    </StickyActions>
  </div>
</template>
