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
 * run, a `hint` for the element - `elementId`), a hint on an element that has one (`hinted`), and everything while an answer is on its way
 * (`disabled`). A button is 44 px at least.
 */
const props = defineProps<{
  boosters: GameRunBoosters
  /** The element on screen: a used hint belongs to it. */
  elementId: string
  /** The element has a hint already (the bazaar's or a booster's). */
  hinted: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{ use: [key: string, effect: GameBoosterEffect] }>()

const { t } = useI18n()

const items = computed(() =>
  props.boosters.available.map((booster) => {
    const used = props.boosters.used.some((u) => (booster.effect === 'hint' ? u.effect === 'hint' && u.element_id === props.elementId : u.effect === booster.effect))
    const spent = used || (booster.effect === 'hint' && props.hinted)
    const name = t(`gameCaravan.booster.effect.${booster.effect}` as StringKey)
    return {
      ...booster,
      spent,
      name,
      label: used ? t('gameCaravan.booster.aria_used', { name }) : t('gameCaravan.booster.aria', { name, qty: booster.qty }),
    }
  }),
)
</script>

<template>
  <div v-if="items.length > 0" class="flex items-center justify-center gap-2 px-4 pt-3" role="group" :aria-label="t('gameCaravan.booster.row_aria')" data-testid="boosters">
    <button
      v-for="item in items"
      :key="item.key"
      type="button"
      class="relative flex size-12 items-center justify-center rounded-xl bg-fill transition-opacity active:scale-95"
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
