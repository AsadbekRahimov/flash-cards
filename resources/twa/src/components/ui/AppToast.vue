<script setup lang="ts">
import { useToast } from '@/composables/useToast'

/**
 * Renders the message of `useToast()` above the tab bar (`.toast`). Mounted once, in App.vue; screens only
 * call `useToast().show(t('common.saved'))`.
 *
 * The `role="status"` live region (`data-testid="toast-region"`) is always in the page and only the message
 * (`data-testid="toast"`) comes and goes inside it: a screen reader announces text that is put into a live region it
 * already knows, not a region that appears together with its text.
 */
const { toast } = useToast()
</script>

<template>
  <div role="status" data-testid="toast-region">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="translate-y-2 opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="toast" :key="toast.id" class="toast" data-testid="toast">{{ toast.text }}</div>
    </Transition>
  </div>
</template>
