<script setup lang="ts">
import { computed } from 'vue'
import { useTelegram } from '@/composables/useTelegram'

const props = defineProps<{
  reviewed: number
  nextInDays: number
}>()

const { close, notificationHaptic } = useTelegram()

const nextLabel = computed(() => {
  if (props.nextInDays <= 0) return 'Сегодня'
  if (props.nextInDays === 1) return 'Через 1 день'
  if (props.nextInDays < 5) return `Через ${props.nextInDays} дня`
  return `Через ${props.nextInDays} дней`
})

notificationHaptic('success')
</script>

<template>
  <section class="flex-1 flex items-center justify-center p-6 text-center">
    <div class="max-w-sm space-y-5">
      <div class="text-6xl">🎉</div>
      <h1 class="text-2xl font-bold">Отлично!</h1>
      <p class="text-tg-hint">
        Вы повторили <b class="text-tg-text">{{ reviewed }}</b>
        {{ reviewed === 1 ? 'слово' : 'слов' }}.
      </p>
      <div class="card">
        <div class="text-sm text-tg-hint">Следующее повторение</div>
        <div class="text-lg font-semibold mt-1" data-testid="next-label">{{ nextLabel }}</div>
      </div>
      <button class="btn btn-primary w-full" data-testid="close-btn" @click="close()">
        Закрыть
      </button>
    </div>
  </section>
</template>
