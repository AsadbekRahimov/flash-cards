<script setup lang="ts">
/**
 * The big tinted card of "Today" (docs/design/2026-11-25-redesign Main / MainNext): the step of the plan of the day to do now - a line
 * over the title (the level and the lesson, or "the lesson is done"), the title in display size with a hand-drawn underline, a line of
 * what it holds, and the actions in the default slot (one primary button). The picture is one for every lesson: there is no data on the
 * topics of the lessons. Its colours are the tokens of the palette, so the dark theme follows by itself. On a phone narrower than 360 px
 * the picture is not drawn (it would sit under the title) and the text takes the width; the text column otherwise stops short of the
 * picture, and a long name of a lesson wraps (at most three lines) instead of running over it.
 *
 * - `eyebrow` — the line over the title (14 px semibold).
 * - `title` — the name of the step: the lesson, "Training", "Timed pairs", "The plan is done".
 * - `text` — what it holds ("5 new words").
 * - `note` — a quieter second line (the Free quota of new words).
 * - `step` — goes to `data-step` of the card (`1`/`2`/`3`/`done`), for tests and the funnel.
 *
 * @example
 * <LessonHero step="1" eyebrow="A1–A2 · урок 3" title="Напитки" text="5 новых слов">
 *   <button class="btn btn-primary btn-lg btn-block">Начать урок</button>
 * </LessonHero>
 */
defineProps<{
  eyebrow?: string
  title: string
  text?: string
  note?: string
  step: string
}>()
</script>

<template>
  <section class="relative flex flex-col gap-4 overflow-hidden rounded-hero bg-highlight px-4 pt-[18px] pb-4" :data-step="step" data-testid="lesson-hero">
    <!-- The picture of the mock-up: a cup with steam, leaves and a lemon slice, drawn in the tokens of the palette. -->
    <svg
      aria-hidden="true"
      width="158"
      height="142"
      viewBox="0 0 200 180"
      fill="none"
      stroke-width="2.2"
      stroke-linecap="round"
      stroke-linejoin="round"
      class="pointer-events-none absolute top-2.5 right-2 stroke-accent max-[359px]:hidden"
      data-testid="hero-art"
    >
      <path d="M96 62c-10-10 8-18 0-30s6-18 2-24M116 58c-8-8 6-14 0-24" />
      <path d="M150 46c10-14 26-16 36-12-2 14-14 24-30 22z" class="fill-nav-active" />
      <path d="M154 52c8-6 16-11 28-16" stroke-width="1.4" />
      <path d="M140 32c0-12 8-20 18-22 4 10 0 22-12 28z" class="fill-nav-active" />
      <ellipse cx="104" cy="152" rx="84" ry="14" class="fill-surface" />
      <path d="M46 86h116c0 40-20 62-58 62s-58-22-58-62z" class="fill-surface" />
      <ellipse cx="104" cy="86" rx="58" ry="12" class="fill-primary/60" />
      <path d="M160 96c26-6 30 28-6 34" stroke-width="5" />
      <path d="M58 106c4 14 12 24 24 30M66 102c4 12 10 20 20 26M140 128c6-6 10-14 12-22" stroke-width="1.4" />
      <circle cx="40" cy="148" r="24" class="fill-primary/60" />
      <circle cx="40" cy="148" r="17" class="fill-nav-active" />
      <path d="M40 131v34M23 148h34M28 136l24 24M52 136l-24 24" stroke-width="1.4" />
    </svg>

    <div class="relative flex max-w-[min(200px,calc(100%_-_140px))] flex-col gap-1.5 max-[359px]:max-w-none" data-testid="hero-copy">
      <p v-if="eyebrow" class="text-sm leading-[18px] font-semibold" data-testid="hero-eyebrow">{{ eyebrow }}</p>
      <h2 class="text-[32px] leading-[38px] font-extrabold tracking-[-0.6px] [overflow-wrap:anywhere] hyphens-auto line-clamp-3" data-testid="hero-title">{{ title }}</h2>
      <svg aria-hidden="true" width="150" height="10" viewBox="0 0 150 10" fill="none" class="max-w-full stroke-primary/60">
        <path d="M3 7c40-5 90-6 144-3" stroke-width="4" stroke-linecap="round" />
      </svg>
      <p v-if="text" class="mt-1 text-[15px] leading-[21px]" data-testid="hero-text">{{ text }}</p>
      <p v-if="note" class="text-[13px] leading-[18px] text-muted" data-testid="hero-note">{{ note }}</p>
    </div>

    <div v-if="$slots.default" class="relative flex flex-col gap-2">
      <slot />
    </div>
  </section>
</template>
