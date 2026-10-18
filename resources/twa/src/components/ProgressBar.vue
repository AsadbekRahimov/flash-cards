<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  done: number
  total: number
}>()

const percent = computed((): number =>
  props.total > 0 ? Math.min(100, Math.round((props.done / props.total) * 100)) : 0,
)
</script>

<template>
  <div
    class="h-2 w-full overflow-hidden rounded-full bg-black/10"
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-valuenow="percent"
    data-testid="progress-bar"
  >
    <div class="h-full rounded-full bg-tg-button" :style="{ width: `${percent}%` }" data-testid="progress-fill" />
  </div>
</template>
