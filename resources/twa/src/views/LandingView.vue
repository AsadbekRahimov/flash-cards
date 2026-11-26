<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import { BRAND } from '@/brand'

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
  <section class="flex flex-1 items-center justify-center bg-page px-5 py-6">
    <div class="max-w-sm space-y-3 text-center">
      <h1 class="text-[26px] leading-[30px] font-semibold tracking-[-0.2px]" data-testid="landing-brand">{{ BRAND }}</h1>
      <p v-if="inTelegram === false" class="text-base leading-[22px] text-muted" data-testid="landing-outside">
        {{ t('landing.outside_telegram') }}
      </p>
    </div>
  </section>
</template>
