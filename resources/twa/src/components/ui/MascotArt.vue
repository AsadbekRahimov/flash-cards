<script setup lang="ts">
import { computed } from 'vue'
import { MASCOT_SIZES, mascotImage, type MascotPose } from '@/composables/illustrations'

/**
 * The mascot "Muhrcha" of the kit (ASSETS.md): a wooden stamp in one of six poses, a transparent WebP, decorative (`alt=""`).
 *
 * - `pose` — hello | thinking | celebrate | freeze | trophy | reading.
 * - `height` — the height on screen in px (default 120; the kit uses 84-220); the width follows the proportions of the pose.
 *
 * It enters once (8 px from below and a fade, 300 ms; ANIMATIONS.md §8) and then stands still: no endless bounce.
 *
 * @example <MascotArt pose="reading" :height="112" />
 */
const props = withDefaults(defineProps<{ pose: MascotPose; height?: number }>(), { height: 120 })

const width = computed(() => Math.round((MASCOT_SIZES[props.pose].width / MASCOT_SIZES[props.pose].height) * props.height))
</script>

<template>
  <img
    :src="mascotImage(pose)"
    :width="width"
    :height="height"
    alt=""
    loading="lazy"
    decoding="async"
    draggable="false"
    class="mascot-enter shrink-0 select-none object-contain"
    :data-pose="pose"
    data-testid="mascot-art"
  />
</template>
