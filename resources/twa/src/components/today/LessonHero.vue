<script setup lang="ts">
import { computed } from 'vue'
import AppChip from '@/components/ui/AppChip.vue'
import TopicArt from '@/components/ui/TopicArt.vue'
import { topicImage } from '@/composables/illustrations'

/**
 * The "daftar" hero of "Today" (kit Muhrla, COMPONENTS.md "Daftar hero", screens 01 / 02): a page of an exercise book - the surface with
 * radius xl, ruled every 28 px and a red margin 30 px from the left - with the step of the plan of the day to do now: a chip over the title
 * (the level and the lesson, or "the lesson is done"), the picture of the topic on the right, the title in Display, a line of what it holds
 * and the actions in the default slot (one primary button). A lesson out of a course without pictures (a course of a centre, a deck) has no
 * picture: the chip and the text take the width, nothing else moves. The colours are the tokens, so the dark theme follows by itself.
 *
 * - `eyebrow` — the chip over the title; `eyebrowTone` — `neutral` (the lesson, default) or `success` ("the lesson is done").
 * - `title` — the name of the step: the lesson, "Training", "Timed pairs", "The plan is done".
 * - `text` — what it holds ("5 new words"); `note` — a quieter second line (the Free quota of new words).
 * - `course`, `stage` — the course slug and the module number of the picture (`TopicArt`, 112 px); none - no picture.
 * - `step` — goes to `data-step` of the card (`1`/`2`/`3`/`done`/`start`), for tests and the funnel.
 * - slot `art` — another picture instead of the topic (the mascot of the start card).
 *
 * @example
 * <LessonHero step="1" eyebrow="A1–A2 · урок 3" title="Напитки" text="5 новых слов" course="english-a1-a2" :stage="3">
 *   <AppButton block icon>Начать урок</AppButton>
 * </LessonHero>
 */
const props = withDefaults(
  defineProps<{
    eyebrow?: string
    eyebrowTone?: 'neutral' | 'success'
    title: string
    text?: string
    note?: string
    step: string
    course?: string | null
    stage?: number | null
  }>(),
  { eyebrow: '', eyebrowTone: 'neutral', text: '', note: '', course: null, stage: null },
)

const hasTopic = computed(() => topicImage(props.course, props.stage) !== null)

/** Enough lines for the tallest hero (a title of three lines, a note and two buttons); the section clips the rest. */
const RULED_LINES = 24
</script>

<template>
  <section class="relative overflow-hidden rounded-xl border border-hairline bg-surface py-5 pr-5 pl-11" :data-step="step" data-testid="lesson-hero">
    <!-- The ruling of the page: a line of border/default (80 %) every 28 px from the top (no gradients in the design system: lines). -->
    <span class="pointer-events-none absolute inset-0" aria-hidden="true" data-testid="hero-lines">
      <span v-for="line in RULED_LINES" :key="line" class="absolute inset-x-0 h-px bg-hairline/80" :style="{ top: `${line * 28 - 1}px` }" />
    </span>
    <!-- The red margin of the exercise book: 1.5 px, muhr at 55 %, 30 px from the left edge. -->
    <span class="pointer-events-none absolute inset-y-0 left-[30px] w-[1.5px] bg-muhr/55" aria-hidden="true" data-testid="hero-margin" />

    <div class="relative flex min-h-[26px] items-start justify-between gap-3">
      <AppChip v-if="eyebrow" :tone="eyebrowTone" class="mt-0.5 max-w-full min-w-0 truncate" data-testid="hero-eyebrow">{{ eyebrow }}</AppChip>
      <span v-else />
      <!-- The picture of the topic: 112 px, it hangs over the line of the title a little, as in the kit. -->
      <div v-if="hasTopic || $slots.art" class="-mt-2 -mr-2 -mb-6 shrink-0 max-[339px]:hidden" aria-hidden="true" data-testid="hero-art">
        <slot name="art">
          <TopicArt :course="course" :stage="stage" :size="112" />
        </slot>
      </div>
    </div>

    <div class="relative mt-3 flex flex-col gap-2" data-testid="hero-copy">
      <h2 class="t-display-l line-clamp-3 [overflow-wrap:anywhere] hyphens-auto" data-testid="hero-title">{{ title }}</h2>
      <p v-if="text" class="t-body-m text-muted" data-testid="hero-text">{{ text }}</p>
      <p v-if="note" class="t-label-s text-tertiary" data-testid="hero-note">{{ note }}</p>
    </div>

    <div v-if="$slots.default" class="relative mt-5 flex flex-col gap-2">
      <slot />
    </div>
  </section>
</template>

