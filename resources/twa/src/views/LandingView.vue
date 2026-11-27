<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import { BRAND } from '@/brand'
import MascotArt from '@/components/ui/MascotArt.vue'

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
  <!-- The start of the app (kit Muhrla): the mascot greeting, the brand in Display, on the centre axis. -->
  <section class="flex flex-1 items-center justify-center bg-page px-5 py-6">
    <div class="flex max-w-sm flex-col items-center gap-3 text-center">
      <MascotArt pose="hello" :height="140" />
      <h1 class="t-display-l" data-testid="landing-brand">{{ BRAND }}</h1>
      <p v-if="inTelegram === false" class="t-body-m text-muted" data-testid="landing-outside">
        {{ t('landing.outside_telegram') }}
      </p>
    </div>
  </section>
</template>
