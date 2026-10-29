<script setup lang="ts">
/**
 * "Pick the collocation" (task 18.3, docs/16 §11.3, docs/15 §3.3): a short English phrase with one blank and the options
 * to put into it. A tap on an option IS the answer, in a solo session and in a battle alike: there is no "Check", the choice
 * is saved at once and graded by the server.
 *
 * - `element` — the phrase (`text`, one `___`) and its options (opaque ids). The blank is a box, not underscores; the option
 *   that was tapped is written into it and marked (a symbol and a caption besides the colour) while the answer is on its way.
 * - `mode` — `solo` / `battle`. The tap is final in both. In a battle the component stays locked after the tap for good (one
 *   answer per round; a failed send is repeated by the battle screen with the same request, not by another tap). In a solo
 *   session a tap whose request failed (the screen lets go of `disabled` while the element is still this one) may be made again.
 * - `disabled` — an answer is on its way: nothing reacts.
 * Events: `answer` (`{ option_id }`).
 *
 * The verdict is not drawn here: a solo session shows it in `AnswerReveal` (the picked option right / wrong, the right one
 * marked, the whole phrase), a battle in its own reveal of the round. The props are fixed for every mode component
 * (`modeComponents.spec.ts`).
 */
import { computed, ref, watch } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { shownOptions, splitPhrase } from '@/composables/collocationPhrase'
import { useI18n } from '@/i18n'
import type { CollocationElement, PracticeAnswerPayload, PracticeChoice } from '@/api/types'

const props = defineProps<{
  element: CollocationElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload] }>()

const { t } = useI18n()

/** The id of the option tapped; the answer is final, so one is enough. */
const picked = ref<string | null>(null)

/** Nothing reacts: a request is on its way, or an option was tapped (a solo tap is released again if its request fails). */
const locked = computed((): boolean => props.disabled === true || picked.value !== null)

// The request ended and the element is still this one: it failed (or the screen was brought up to date). A solo tap may be made
// anew; a battle answer is not taken back by a tap.
watch(
  () => props.disabled,
  (now, before) => {
    if (before === true && now !== true && props.mode === 'solo') picked.value = null
  },
)

function pick(option: PracticeChoice): void {
  if (locked.value) return
  picked.value = option.id
  emit('answer', { option_id: option.id })
}

// ---- the phrase ----

const parts = computed(() => splitPhrase(props.element.text))
const pickedText = computed((): string | null => props.element.options.find((option) => option.id === picked.value)?.text ?? null)

/** A long phrase takes a smaller type, so that the card does not outgrow the screen at 320 px. */
const phraseClass = computed((): string => (props.element.text.length > 90 ? 'text-xl leading-7' : 'text-2xl leading-8'))

// ---- the options ----

const options = computed(() => shownOptions(props.element.options, picked.value, null, null))
</script>

<template>
  <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4" data-testid="collocation-choice" :data-mode="mode">
    <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
      <p class="text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="collocation-instruction">{{ t('practice.collocation.instruction') }}</p>

      <h2 class="font-bold break-words" :class="phraseClass" lang="en" data-testid="collocation-text">
        <template v-if="parts">
          <span>{{ parts.before }}</span>
          <span
            class="mx-0.5 inline-block min-w-[4.5rem] rounded-lg border-2 px-2.5 text-center align-baseline text-[0.9em] leading-6 font-semibold"
            :class="pickedText !== null ? 'border-accent bg-accent-soft text-accent-ink' : 'border-dashed border-accent bg-accent-soft'"
            :data-tone="pickedText !== null ? 'picked' : 'empty'"
            data-testid="collocation-blank"
          >
            <template v-if="pickedText !== null">{{ pickedText }}</template>
            <template v-else>
              <span class="sr-only">{{ t('practice.collocation.blank') }}</span>
              <span aria-hidden="true">&nbsp;</span>
            </template>
          </span>
          <span>{{ parts.after }}</span>
        </template>
        <template v-else>{{ element.text }}</template>
      </h2>
    </article>

    <div class="flex flex-col gap-2.5" role="group" :aria-label="t('practice.collocation.instruction')">
      <button
        v-for="(option, index) in options"
        :key="option.id"
        type="button"
        class="option"
        :class="{ 'opacity-60': locked && option.state === 'idle' }"
        :data-state="option.state"
        :data-testid="`option-${index}`"
        :disabled="locked"
        :aria-pressed="option.caption === 'your' ? 'true' : undefined"
        @click="pick(option)"
      >
        <span class="min-w-0 flex-1 break-words">
          <span class="block" lang="en">{{ option.text }}</span>
          <span
            v-if="option.caption"
            class="mt-0.5 block text-[13px] leading-[18px] font-normal first-letter:uppercase"
            :data-testid="`option-caption-${index}`"
          >
            {{ t('practice.reveal.your_option') }}
          </span>
        </span>
        <AppIcon v-if="option.state === 'selected'" name="check-circle" class="text-accent-ink" :size="22" />
      </button>
    </div>

    <p v-if="picked === null" class="px-2 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="collocation-footnote">
      {{ t(mode === 'battle' ? 'practice.collocation.footnote.battle' : 'practice.collocation.footnote.solo') }}
    </p>
  </div>
</template>
