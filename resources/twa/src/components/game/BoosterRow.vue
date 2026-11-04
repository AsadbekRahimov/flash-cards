<script setup lang="ts">
import { computed } from 'vue'
import GameItemImage from '@/components/game/GameItemImage.vue'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { GameBoosterEffect, GameRunBoosters } from '@/api/gameShopTypes'

/**
 * The row of boosters over the task of a run (docs/05 §5b.5, docs/18 §12.9): an icon with the rest for each booster of the learner's
 * stock (`boosters.available`). Not drawn while the stock is empty.
 *
 * Inactive (and said so for a screen reader): a booster that this run has used already (`extra_flask` / `second_chance` are for the whole
 * run, a `hint` for the element - `elementId`), a hint on an element that has one (`hinted`), a booster that cannot work here (a hint on a
 * task that has none: pairs, a sentence, a dictation; a flask or a second chance in a fight without flasks: `flasksMax` 0), an effect this
 * client does not know, and everything while an answer is on its way (`disabled`). A button is 44 px at least. The row has no padding of its
 * own: the screen puts it beside the hint of the bazaar (one line in a short window).
 */
const props = defineProps<{
  boosters: GameRunBoosters
  /** The element on screen: a used hint belongs to it. */
  elementId: string
  /** The kind of the element: some have no hint to give. */
  elementKind?: string
  /** The flasks of the run (`flasks_max`): a boss has none. */
  flasksMax?: number
  /** The element has a hint already (the bazaar's or a booster's). */
  hinted: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{ use: [key: string, effect: GameBoosterEffect] }>()

const { t } = useI18n()

/** The kinds whose element carries no hint (the server answers 409 `booster_not_applicable`). */
const NO_HINT: readonly string[] = ['pairs_round', 'sentence', 'dictation']
const KNOWN: readonly string[] = ['hint', 'extra_flask', 'second_chance']

function cannotWork(effect: string): boolean {
  if (!KNOWN.includes(effect)) return true
  if (effect === 'hint') return props.elementKind !== undefined && NO_HINT.includes(props.elementKind)
  return props.flasksMax === 0
}

const items = computed(() =>
  props.boosters.available.map((booster) => {
    const used = props.boosters.used.some((u) => (booster.effect === 'hint' ? u.effect === 'hint' && u.element_id === props.elementId : u.effect === booster.effect))
    const useless = cannotWork(booster.effect)
    const spent = used || useless || (booster.effect === 'hint' && props.hinted)
    // An effect that this client has no words for is "Booster", never the raw key of the dictionary.
    const name = KNOWN.includes(booster.effect) ? t(`gameCaravan.booster.effect.${booster.effect}` as StringKey) : t('gameCaravan.booster.effect.unknown')
    return {
      ...booster,
      spent,
      name,
      label: used ? t('gameCaravan.booster.aria_used', { name }) : useless ? t('gameCaravan.booster.aria_useless', { name }) : t('gameCaravan.booster.aria', { name, qty: booster.qty }),
    }
  }),
)
</script>

<template>
  <div v-if="items.length > 0" class="flex items-center justify-center gap-2" role="group" :aria-label="t('gameCaravan.booster.row_aria')" data-testid="boosters">
    <button
      v-for="item in items"
      :key="item.key"
      type="button"
      class="relative flex size-12 items-center justify-center rounded-xl bg-fill transition-opacity active:scale-95 short:size-11"
      :class="{ 'opacity-40': item.spent }"
      :disabled="disabled || item.spent"
      :aria-label="item.label"
      :data-testid="`booster-${item.effect}`"
      @click="emit('use', item.key, item.effect)"
    >
      <GameItemImage :name="item.key" :size="32" />
      <span class="absolute -right-1 -bottom-1 rounded-full bg-accent px-1.5 text-[11px] leading-4 font-semibold text-tg-button-text tabular-nums" data-testid="booster-qty">
        {{ t('gameCaravan.booster.qty', { qty: item.qty }) }}
      </span>
    </button>
  </div>
</template>
