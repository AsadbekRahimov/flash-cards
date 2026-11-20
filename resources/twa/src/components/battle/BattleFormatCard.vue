<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { BattleModeOption, PracticeShortageReason } from '@/api/types'

/**
 * One format of a battle in the setup (docs/16 §7.3, §11.2): a row of the list of formats - the name of the mode, a line of example,
 * the length of a round ("45 s per round") and what the learner can do with it. The server says for each format whether it
 * can be played with this friend on this material:
 * - `available` — the row is one radio button (`selected` marks the chosen one with the frame of a selected row and a check);
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
    class="block w-full border-t border-divider px-[18px] py-3 text-left first:border-t-0"
    :class="{
      'transition-colors active:bg-fill': playable && !selected,
      'bg-accent-soft shadow-[inset_0_0_0_1.5px_var(--app-primary)]': selected,
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
      <span class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="text-[15px] leading-5 font-semibold" data-testid="format-title">{{ title }}</span>
        <span class="truncate text-[13px] leading-[18px] text-muted">{{ example }}</span>
        <span class="text-[13px] leading-[18px] text-muted" data-testid="format-seconds">{{ seconds }}</span>
      </span>

      <span v-if="option.state === 'not_enough'" class="chip chip-warning shrink-0" data-testid="format-chip">{{ t('practice.hub.state.not_enough') }}</span>
      <span v-else-if="option.state === 'disabled'" class="chip chip-neutral shrink-0" data-testid="format-chip">{{ t('practice.hub.state.disabled') }}</span>
      <span v-else-if="option.state === 'pro_required'" class="chip chip-accent shrink-0" data-testid="format-chip">{{ t('practice.hub.state.pro') }}</span>

      <!-- The radio: a check in the dark circle when chosen (not the colour alone), an empty circle otherwise. -->
      <span v-if="selected" class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary" data-testid="format-check">
        <AppIcon name="check" :size="14" />
      </span>
      <span v-else-if="playable" class="size-5 shrink-0 rounded-full border-[1.5px] border-muted" aria-hidden="true" />
    </span>

    <span v-if="option.state === 'not_enough'" class="mt-2 block text-[13px] leading-[18px] text-muted" data-testid="format-reason">{{ reason }}</span>
  </component>
</template>
