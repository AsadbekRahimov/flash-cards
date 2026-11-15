<script setup lang="ts">
import GameItemImage from '@/components/game/GameItemImage.vue'
import { useI18n } from '@/i18n'
import type { GameShopItem } from '@/api/gameShopTypes'

/**
 * A card of the shop (docs/05 §5b.5): the picture, the name, the words, and one action by the state of the item.
 * - a cosmetic that is owned: "Wear"; worn: the "Worn" chip and "Take off" (the slot goes empty); not owned: "Buy" with the price;
 * - a booster: "In stock: N" and "Buy" (boosters pile up);
 * - `locked_reason = pro`: the Pro chip and "Open with Pro" (the screen opens the paywall, no request); `achievement`: a prize, no button.
 * The buttons are 48 px high (`.btn`), `busy` switches them off while something is on its way.
 */
const props = defineProps<{ item: GameShopItem; busy?: boolean }>()
const emit = defineEmits<{ buy: [item: GameShopItem]; equip: [item: GameShopItem]; unequip: [item: GameShopItem]; pro: [item: GameShopItem] }>()

const { t } = useI18n()
</script>

<template>
  <li class="card flex items-center gap-3" :data-testid="`item-${item.key}`">
    <GameItemImage :name="item.asset" :size="56" />
    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="text-[15px] leading-5 font-semibold" data-testid="item-title">{{ item.title }}</h3>
        <span v-if="item.locked_reason === 'pro'" class="chip chip-accent" data-testid="item-pro">{{ t('gameCaravan.item.pro') }}</span>
      </div>
      <p v-if="item.description" class="text-[13px] leading-[18px] text-tg-subtitle">{{ item.description }}</p>
      <p v-if="item.kind === 'booster' && item.qty > 0" class="mt-0.5 text-[13px] leading-[18px] font-medium text-accent-ink tabular-nums" data-testid="item-stock">
        {{ t('gameCaravan.item.stock', { count: item.qty }) }}
      </p>
      <p v-if="item.locked_reason === 'achievement'" class="mt-0.5 text-[13px] leading-[18px] font-medium text-tg-subtitle" data-testid="item-achievement">
        {{ t('gameCaravan.item.achievement') }}
      </p>

      <div v-if="item.locked_reason !== 'achievement'" class="mt-2">
        <button v-if="item.locked_reason === 'pro'" type="button" class="btn btn-tinted btn-sm min-h-11" :disabled="busy" data-testid="item-pro-btn" @click="emit('pro', props.item)">
          {{ t('gameCaravan.item.pro_open') }}
        </button>
        <template v-else-if="item.kind === 'cosmetic' && item.owned">
          <div v-if="item.equipped" class="flex flex-wrap items-center gap-2">
            <span class="chip chip-success" data-testid="item-equipped">{{ t('gameCaravan.item.equipped') }}</span>
            <button type="button" class="btn btn-tinted btn-sm min-h-11" :disabled="busy" data-testid="item-unequip" @click="emit('unequip', props.item)">
              {{ t('gameShop.item.unequip') }}
            </button>
          </div>
          <button v-else type="button" class="btn btn-tinted btn-sm min-h-11" :disabled="busy" data-testid="item-equip" @click="emit('equip', props.item)">
            {{ t('gameCaravan.item.equip') }}
          </button>
        </template>
        <button v-else type="button" class="btn btn-primary btn-sm min-h-11" :disabled="busy" data-testid="item-buy" @click="emit('buy', props.item)">
          {{ t('gameCaravan.item.buy') }} · {{ item.price }}
        </button>
      </div>
    </div>
  </li>
</template>
