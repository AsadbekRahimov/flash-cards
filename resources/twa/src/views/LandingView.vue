<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

const { isInTelegram } = useTelegram()
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
// null until mounted, so the "outside Telegram" hint does not flash before the redirect
const inTelegram = ref<boolean | null>(null)

// Opened without a session context (bot button, reminder link `?from=…`): the home screen is "Today".
onMounted(() => {
  inTelegram.value = isInTelegram()
  if (inTelegram.value) void router.replace({ name: 'today', query: route.query })
})

</script>

<template>
  <section class="flex-1 flex items-center justify-center p-6">
    <div class="max-w-sm text-center space-y-4">
      <h1 class="text-2xl font-bold">LexiFlow</h1>
      <p v-if="inTelegram === false" class="text-tg-hint">
        {{ t('landing.outside_telegram') }}
      </p>
    </div>
  </section>
</template>
