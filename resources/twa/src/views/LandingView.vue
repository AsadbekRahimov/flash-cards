<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

const { isInTelegram } = useTelegram()
const { t } = useI18n()
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
        {{ t('landing.in_telegram') }}
      </p>
      <p v-else class="text-tg-hint">
        {{ t('landing.outside_telegram') }}
      </p>
    </div>
  </section>
</template>
