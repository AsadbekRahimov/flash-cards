<script setup lang="ts">
import { useToast } from '@/composables/useToast'

/**
 * Renders the message of `useToast()` above the tab bar (`.toast`). Mounted once, in App.vue; screens only
 * call `useToast().show(t('common.saved'))`. `role="status"`, `data-testid="toast"`.
 */
const { toast } = useToast()
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="translate-y-2 opacity-0"
    leave-active-class="transition duration-150 ease-in"
    leave-to-class="opacity-0"
  >
    <div v-if="toast" :key="toast.id" class="toast" role="status" data-testid="toast">{{ toast.text }}</div>
  </Transition>
</template>
