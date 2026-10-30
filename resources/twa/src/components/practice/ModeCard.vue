<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { MODE_LOOK, estimateMinutes, modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { PracticeActiveSession, PracticeHubReason, PracticeMode, PracticeModeInfo } from '@/api/types'

/**
 * One of the four cards of the practice hub (docs/16 §11.2, docs/14 §6.5): the icon tile of the mode, its name, a line
 * of example, "≈ 2 min", and what the learner can do with it.
 *
 * - `info` — the card as the server states it (`state`, `available` / `required`, `reason`, `estimate_seconds`).
 * - `resume` — the unfinished session of this mode in this very place: the card says "Continue" and `select` leads back
 *   into it.
 * - `busy` — the session is being opened (a spinner instead of the chevron, the card does not react).
 * - `learn` — what to offer when material is short: `lesson` ("Learn the lesson" - the hub is for one lesson), `course`
 *   ("To the lessons"), or nothing. Hidden when more learning would not help: the recordings are still being made, or there are no
 *   tasks for the topic at all (`no_items`). With `items_locked` the tasks are there and wait for the words of the lesson: it is shown.
 *
 * The whole card is one button of 90 px or so when there is something to play (`available`, `pro_required` and a
 * session to continue); a card that cannot be played now (`not_enough`, `disabled`) is a plain block, and its only
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
const look = computed(() => MODE_LOOK[mode.value])
const title = computed(() => modeTitle(mode.value))
const example = computed(() => t(`practice.mode.${mode.value}.example`))
const estimate = computed(() => t('practice.hub.estimate', { minutes: estimateMinutes(props.info.estimate_seconds) }))

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

const reason = computed((): string =>
  t(props.info.reason ? REASONS[props.info.reason] : 'practice.hub.reason.unknown', {
    available: props.info.available,
    required: props.info.required,
  }),
)

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
    class="card block w-full text-left"
    :class="{
      'transition-transform active:scale-[0.99]': playable,
      'opacity-60': info.state === 'disabled',
    }"
    :aria-label="playable ? label : undefined"
    :aria-busy="busy ? 'true' : undefined"
    :disabled="playable && busy ? true : undefined"
    :data-testid="`mode-card-${mode}`"
    :data-state="resume ? 'resume' : info.state"
    @click="playable ? select() : undefined"
  >
    <span class="flex items-center gap-3">
      <span class="icon-tile size-10 rounded-xl" :class="`tile-${look.tile}`"><AppIcon :name="look.icon" :size="22" /></span>

      <span class="min-w-0 flex-1">
        <span class="block text-[17px] leading-[22px] font-semibold" data-testid="mode-title">{{ title }}</span>
        <span class="block truncate text-[15px] leading-5 text-tg-subtitle" data-testid="mode-example">{{ example }}</span>
        <!-- The length of a round and the state of the card: under the text, so that the title keeps the whole width. -->
        <span class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <span class="flex items-center gap-1 text-[13px] leading-[18px] text-tg-hint" data-testid="mode-estimate">
            <AppIcon name="clock" :size="14" />
            {{ estimate }}
          </span>
          <span v-if="resume" class="chip chip-accent" data-testid="mode-chip">
            <AppIcon name="play" solid :size="12" />
            {{ t('practice.hub.continue') }}
          </span>
          <span v-else-if="info.state === 'pro_required'" class="chip chip-accent" data-testid="mode-chip">
            <AppIcon name="sparkles" solid :size="12" />
            {{ t('practice.hub.state.pro') }}
          </span>
          <span v-else-if="info.state === 'not_enough'" class="chip chip-warning" data-testid="mode-chip">
            <AppIcon name="exclamation-triangle" :size="12" />
            {{ t('practice.hub.state.not_enough') }}
          </span>
          <span v-else-if="info.state === 'disabled'" class="chip chip-neutral" data-testid="mode-chip">
            {{ t('practice.hub.state.disabled') }}
          </span>
        </span>
      </span>

      <span
        v-if="busy"
        class="size-5 shrink-0 animate-spin rounded-full border-2 border-track border-t-accent motion-reduce:animate-none"
        aria-hidden="true"
        data-testid="mode-busy"
      />
      <AppIcon v-else-if="playable" name="chevron-right" :size="16" class="text-tg-hint" />
    </span>

    <template v-if="!playable">
      <span v-if="info.state === 'not_enough'" class="mt-3 block text-[15px] leading-5 text-tg-subtitle" data-testid="mode-reason">{{ reason }}</span>
      <!-- Not a button inside a button: a card that cannot be played now is a plain block, so this one is allowed. -->
      <button
        v-if="info.state === 'not_enough' && learn && learnHelps"
        type="button"
        class="btn btn-tinted btn-sm mt-3"
        :data-testid="`mode-learn-${mode}`"
        @click="emit('learn')"
      >
        {{ learnLabel }}
      </button>
    </template>
  </component>
</template>
