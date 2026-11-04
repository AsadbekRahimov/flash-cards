<script setup lang="ts">
import { computed } from 'vue'
import { gameAssetUrl } from '@/assets/game'

/**
 * The picture of an item of the game by the name of its asset (`assets/game`, an unknown name draws the spare picture). It is decoration:
 * the name of the item is always written next to it, so it has no `alt`. `size` is in px (the picture is square).
 */
const props = withDefaults(defineProps<{ name: string | null | undefined; size?: number }>(), { size: 56 })

const url = computed(() => gameAssetUrl(props.name))
</script>

<template>
  <img :src="url" :width="size" :height="size" alt="" class="shrink-0 object-contain select-none" draggable="false" :data-asset="name ?? ''" data-testid="item-image" />
</template>
