<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useTelegram } from '@/composables/useTelegram'

const { isInTelegram } = useTelegram()
const inTelegram = ref(false)

onMounted(() => {
  inTelegram.value = isInTelegram()
})
</script>

<template>
  <section class="flex-1 flex items-center justify-center p-6">
    <div class="max-w-sm text-center space-y-4">
      <h1 class="text-2xl font-bold">LexiFlow</h1>
      <p v-if="inTelegram" class="text-tg-hint">
        Откройте тренировку через кнопку в группе Telegram.
      </p>
      <p v-else class="text-tg-hint">
        Эта страница работает только внутри Telegram Web App. Попросите учителя
        прислать ссылку-тренировку в группу.
      </p>
    </div>
  </section>
</template>
