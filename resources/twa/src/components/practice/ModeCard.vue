<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import { estimateMinutes, modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { PracticeActiveSession, PracticeHubReason, PracticeMode, PracticeModeInfo } from '@/api/types'

/**
 * One row of "All games" on the practice hub (docs/16 §11.2, docs/design/2026-11-25-redesign Practice): a quiet tile with the icon of
 * the mode (`bg-accent-soft`, the icon in the accent), the name of the mode, a line of example under it, and one value on the right - the
 * state of the row (a chip: "Continue", "Pro", "Few materials", "Off"), else the personal best (timed pairs), else "≈ 2 min". The rows
 * have no lines of their own: the list draws the lines between them (`divide-y`).
 *
 * - `info` — the card as the server states it (`state`, `available` / `required`, `reason`, `estimate_seconds`).
 * - `resume` — the unfinished session of this mode in this very place: the card says "Continue" and `select` leads back
 *   into it.
 * - `busy` — the session is being opened (a spinner instead of the chevron, the card does not react).
 * - `learn` — what to offer when material is short: `lesson` ("Learn the lesson" - the hub is for one lesson), `course`
 *   ("To the lessons"), or nothing. Hidden when more learning would not help: the recordings are still being made, or there are no
 *   tasks for the topic at all (`no_items`; with a few tasks already there it says "few: 2 of 3", and the button stays hidden too). With `items_locked`
 *   the tasks are there and wait for the words of the lesson (of the lessons, on the hub of a course): it is shown.
 *
 * The whole row is one button (at least 52 px high) when there is something to play (`available`, `pro_required` and a
 * session to continue); a row that cannot be played now (`not_enough`, `disabled`) is a plain block, and its only
 * button is "Learn the lesson" (a button inside a button would not be valid). Events: `select` (`mode`) and `learn`.
 */
const props = defineProps<{
  info: PracticeModeInfo
  resume?: PracticeActiveSession | null
  busy?: boolean
  learn?: 'lesson' | 'course' | null
}>()

const emit = defineEmits<{ select: [mode: PracticeMode]; learn: [] }>()

const { t } = useI18n()

const mode = computed((): PracticeMode => props.info.mode)
const title = computed(() => modeTitle(mode.value))
const example = computed(() => t(`practice.mode.${mode.value}.example`))
const estimate = computed(() => t('practice.hub.estimate', { minutes: estimateMinutes(props.info.estimate_seconds) }))

/** The picture of the mode in its tile: decorative (the name says the same). */
const ICONS: Record<PracticeMode, IconName> = {
  pairs: 'arrows-right-left',
  collocation: 'puzzle-piece',
  sentence: 'rectangle-stack',
  dictation: 'speaker-wave',
  pairs_timed: 'clock',
  word_form: 'pencil-square',
  error_hunt: 'light-bulb',
}
const icon = computed((): IconName => ICONS[mode.value] ?? 'puzzle-piece')

const playable = computed(() => props.info.state === 'available' || props.info.state === 'pro_required' || Boolean(props.resume))

const label = computed((): string => {
  if (props.resume) {
    return t('practice.hub.card.aria_resume', { title: title.value, done: props.resume.progress.done, total: props.resume.progress.total })
  }
  if (props.info.state === 'pro_required') return t('practice.hub.card.aria_pro', { title: title.value })
  return t('practice.hub.card.aria', { title: title.value, example: example.value, estimate: estimate.value })
})

const REASONS: Record<PracticeHubReason, StringKey> = {
  no_words: 'practice.hub.reason.no_words',
  ambiguous: 'practice.hub.reason.ambiguous',
  no_items: 'practice.hub.reason.no_items',
  items_locked: 'practice.hub.reason.items_locked',
  audio_pending: 'practice.hub.reason.audio_pending',
  no_audio_words: 'practice.hub.reason.no_audio_words',
}

/**
 * The sentence under a card that cannot be played. Two reasons say more than their key: `items_locked` on the hub of a COURSE (the button
 * is "To the lessons") names the words of the lessons, not of one lesson; and `no_items` with some tasks already there (0 < available < required)
 * says "few" with the numbers, not "none" - a topic with 2 tasks of 3 has tasks.
 */
const reason = computed((): string => {
  const { reason: why, available, required } = props.info
  const params = { available, required }
  if (why === 'items_locked' && props.learn === 'course') return t('practice.hub.reason.items_locked_course', params)
  if (why === 'no_items' && available > 0 && available < required) return t('practice.hub.reason.no_items_few', params)
  return t(why ? REASONS[why] : 'practice.hub.reason.unknown', params)
})

/**
 * More learning helps when the words are missing, or when the tasks are there and wait for the words of the lesson
 * (`items_locked`). It does not when there are no tasks for the topic at all (`no_items`), nor while the recordings are still
 * being made.
 */
const learnHelps = computed(
  () => props.info.reason !== 'audio_pending' && props.info.reason !== 'no_audio_words' && props.info.reason !== 'no_items',
)
const learnLabel = computed(() => (props.learn === 'lesson' ? t('practice.hub.learn_lesson') : t('practice.hub.to_lessons')))

function select(): void {
  if (!props.busy) emit('select', mode.value)
}
</script>

<template>
  <component
    :is="playable ? 'button' : 'div'"
    :type="playable ? 'button' : undefined"
    class="block w-full px-3.5 py-2.5 text-left"
    :class="{ 'transition-colors active:bg-fill': playable, 'opacity-60': info.state === 'disabled' }"
    :aria-label="playable ? label : undefined"
    :aria-busy="busy ? 'true' : undefined"
    :disabled="playable && busy ? true : undefined"
    :data-testid="`mode-card-${mode}`"
    :data-state="resume ? 'resume' : info.state"
    @click="playable ? select() : undefined"
  >
    <span class="flex min-h-10 items-center gap-3">
      <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-accent-soft text-accent" aria-hidden="true" data-testid="mode-icon">
        <AppIcon :name="icon" :size="22" />
      </span>
      <span class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="text-base leading-5 font-bold" data-testid="mode-title">{{ title }}</span>
        <span class="line-clamp-2 text-[13px] leading-[18px] break-words text-muted" data-testid="mode-example">{{ example }}</span>
      </span>

      <!-- One value on the right: the state of the row, else the personal best, else the length of a round. -->
      <span
        v-if="busy"
        class="size-5 shrink-0 animate-spin rounded-full border-2 border-track border-t-accent motion-reduce:animate-none"
        aria-hidden="true"
        data-testid="mode-busy"
      />
      <span v-else-if="resume" class="chip chip-accent shrink-0" data-testid="mode-chip">{{ t('practice.hub.continue') }}</span>
      <span v-else-if="info.state === 'pro_required'" class="chip chip-accent shrink-0" data-testid="mode-chip">{{ t('practice.hub.state.pro') }}</span>
      <span v-else-if="info.state === 'not_enough'" class="chip chip-warning shrink-0" data-testid="mode-chip">{{ t('practice.hub.state.not_enough') }}</span>
      <span v-else-if="info.state === 'disabled'" class="chip chip-neutral shrink-0" data-testid="mode-chip">{{ t('practice.hub.state.disabled') }}</span>
      <!-- Timed pairs only: the personal best, once there is one. -->
      <span v-else-if="info.record" class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="mode-record">
        {{ t('practice.hub.record') }} <span class="font-mono">{{ info.record.score }}</span>
      </span>
      <span v-else class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="mode-estimate">{{ estimate }}</span>
    </span>

    <template v-if="!playable">
      <span v-if="info.state === 'not_enough'" class="mt-2 ml-[52px] block text-[13px] leading-[18px] text-muted" data-testid="mode-reason">{{ reason }}</span>
      <!-- Not a button inside a button: a row that cannot be played now is a plain block, so this one is allowed. -->
      <button
        v-if="info.state === 'not_enough' && learn && learnHelps"
        type="button"
        class="btn btn-tinted btn-sm mt-2 ml-[52px]"
        :data-testid="`mode-learn-${mode}`"
        @click="emit('learn')"
      >
        {{ learnLabel }}
      </button>
    </template>
  </component>
</template>
