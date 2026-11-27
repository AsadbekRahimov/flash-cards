<script setup lang="ts">
import { computed } from 'vue'
import { topicImageSet } from '@/composables/illustrations'

/**
 * The picture of a module of a platform course (kit Muhrla, ASSETS.md "Mavzu rasmlari"): a transparent WebP, 120 / 240 px by `srcset`,
 * lazy, decorative (`alt=""`: the title of the module is written next to it).
 *
 * - `course` — the course slug; `stage` — the module (stage) number, 1..20.
 * - `size` — the size on screen in px (default 88; the kit uses 56 in a list, 64-76 in a tile, 88 on a card, 112 at most).
 * - slot `fallback` — what to show when the course has no pictures (a course of a centre, a deck, an unknown module); nothing by default.
 *
 * `class` and other attributes go to the `<img>` (or to the fallback's wrapper).
 *
 * @example <TopicArt :course="course.slug" :stage="stage.number" :size="56"><template #fallback>…</template></TopicArt>
 */
defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{ course: string | null | undefined; stage: number | null | undefined; size?: number }>(), { size: 88 })

const image = computed(() => topicImageSet(props.course, props.stage))
</script>

<template>
  <img
    v-if="image"
    v-bind="$attrs"
    :src="image.src"
    :srcset="image.srcset"
    :sizes="`${size}px`"
    :width="size"
    :height="size"
    alt=""
    loading="lazy"
    decoding="async"
    draggable="false"
    class="shrink-0 select-none object-contain"
    data-testid="topic-art"
  />
  <span v-else-if="$slots.fallback" v-bind="$attrs" class="inline-flex shrink-0" data-testid="topic-art-fallback">
    <slot name="fallback" />
  </span>
</template>
