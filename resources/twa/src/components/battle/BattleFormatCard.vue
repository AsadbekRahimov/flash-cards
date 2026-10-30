<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { MODE_LOOK, modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { BattleModeOption, PracticeShortageReason } from '@/api/types'

/**
 * One format of a battle in the setup (docs/16 §7.3, §11.2): the icon tile of the mode, its name, a line of example, the
 * length of a round ("45 s per round") and what the learner can do with it. The server says for each format whether it
 * can be played with this friend on this material:
 * - `available` — the card is one radio button (`selected` marks the chosen one with a ring and a check);
 * - `not_enough` — a plain block with the chip "Not enough material" and WHY in words (`reason`, with the numbers);
 * - `disabled` — a plain block, "Switched off";
 * - `pro_required` — a plain block with the Pro chip.
 *
 * The state is never the colour alone: a symbol and a word go with it. Event: `select` (`mode`).
 */
const props = defineProps<{
  option: BattleModeOption
  selected: boolean
}>()

const emit = defineEmits<{ select: [mode: BattleModeOption['mode']] }>()

const { t } = useI18n()

const look = computed(() => MODE_LOOK[props.option.mode])
const title = computed(() => modeTitle(props.option.mode))
const example = computed(() => t(`practice.mode.${props.option.mode}.example`))
const seconds = computed(() => t('battle.setup.round_seconds', { seconds: props.option.round_seconds }))
const playable = computed(() => props.option.state === 'available')

const REASONS: Record<PracticeShortageReason, StringKey> = {
  no_words: 'battle.setup.reason.no_words',
  ambiguous: 'battle.setup.reason.ambiguous',
  no_items: 'battle.setup.reason.no_items',
  audio_pending: 'battle.setup.reason.audio_pending',
  no_audio_words: 'battle.setup.reason.no_audio_words',
}

const reason = computed((): string =>
  t(props.option.reason ? REASONS[props.option.reason] : 'battle.setup.reason.unknown', {
    available: props.option.available,
    required: props.option.required,
  }),
)

const label = computed(() => `${title.value}. ${seconds.value}`)
</script>

<template>
  <component
    :is="playable ? 'button' : 'div'"
    :type="playable ? 'button' : undefined"
    class="card block w-full text-left"
    :class="{
      'transition-transform active:scale-[0.99]': playable,
      'ring-2 ring-accent': selected,
      'opacity-60': option.state === 'disabled',
    }"
    :role="playable ? 'radio' : undefined"
    :aria-checked="playable ? selected : undefined"
    :aria-label="playable ? label : undefined"
    :data-testid="`format-${option.mode}`"
    :data-state="selected ? 'selected' : option.state"
    @click="playable ? emit('select', option.mode) : undefined"
  >
    <span class="flex items-center gap-3">
      <span class="icon-tile size-10 rounded-xl" :class="`tile-${look.tile}`"><AppIcon :name="look.icon" :size="22" /></span>

      <span class="min-w-0 flex-1">
        <span class="block text-[17px] leading-[22px] font-semibold" data-testid="format-title">{{ title }}</span>
        <span class="block truncate text-[15px] leading-5 text-tg-subtitle">{{ example }}</span>
        <span class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <span class="flex items-center gap-1 text-[13px] leading-[18px] text-tg-hint" data-testid="format-seconds">
            <AppIcon name="clock" :size="14" />
            {{ seconds }}
          </span>
          <span v-if="option.state === 'not_enough'" class="chip chip-warning" data-testid="format-chip">
            <AppIcon name="exclamation-triangle" :size="12" />
            {{ t('practice.hub.state.not_enough') }}
          </span>
          <span v-else-if="option.state === 'disabled'" class="chip chip-neutral" data-testid="format-chip">{{ t('practice.hub.state.disabled') }}</span>
          <span v-else-if="option.state === 'pro_required'" class="chip chip-accent" data-testid="format-chip">
            <AppIcon name="sparkles" solid :size="12" />
            {{ t('practice.hub.state.pro') }}
          </span>
        </span>
      </span>

      <span v-if="selected" class="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent text-tg-button-text" data-testid="format-check">
        <AppIcon name="check" :size="16" />
      </span>
      <span v-else-if="playable" class="size-6 shrink-0 rounded-full border-2 border-track" aria-hidden="true" />
    </span>

    <span v-if="option.state === 'not_enough'" class="mt-3 block text-[15px] leading-5 text-tg-subtitle" data-testid="format-reason">{{ reason }}</span>
  </component>
</template>
