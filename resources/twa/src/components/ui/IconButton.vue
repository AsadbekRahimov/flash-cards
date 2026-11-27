<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'

/**
 * IconButton of the kit Muhrla (COMPONENTS.md): a 44 x 44 circle of sunken paper with a 20 px icon (`.icon-btn`); pressed - a hairline
 * ring. `label` is required: it is the `aria-label` ("Прослушать слово"), the button has no text.
 *
 * Event: `click` (not while disabled). Other attributes go to the `<button>`.
 *
 * @example <IconButton icon="speaker-wave" :label="t('exercise.listen')" @click="play" />
 */
const props = defineProps<{ icon: IconName; label: string; disabled?: boolean }>()

const emit = defineEmits<{ click: [event: MouseEvent] }>()

function onClick(event: MouseEvent): void {
  if (!props.disabled) emit('click', event)
}
</script>

<template>
  <button type="button" class="icon-btn" :aria-label="label" :disabled="disabled" @click="onClick">
    <AppIcon :name="icon" :size="20" />
  </button>
</template>
