<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import { formatDate } from '@/composables/format'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * "Account deleted" after Settings → Delete account: says that the account is gone, until when `/start` in the bot brings it
 * back (`until`, the date the server named), and closes the Mini App (the "Close" button, or by itself after
 * `CLOSE_AFTER_MS`). Drawn as the kit Muhrla draws its quiet screens: the mascot waving, the title and the line on the centre axis. A screen
 * with no tab bar and no request: the session is already dropped, and any call with the old
 * token would sign the learner in again and bring the account back.
 */
const props = defineProps<{ until: string | null }>()

/** Long enough to read two lines. */
const CLOSE_AFTER_MS = 8000

const { t, locale } = useI18n()
const { close } = useTelegram()

const text = computed(() => {
  const date = props.until ? formatDate(props.until, 'dayMonthYear', locale.value) : ''
  return date === '' ? t('settings.deleted.text_plain') : t('settings.deleted.text', { date })
})

let timer: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  timer = setTimeout(close, CLOSE_AFTER_MS)
})

onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <div class="page flex flex-1 flex-col" data-testid="account-deleted-view">
    <!-- The goodbye of the kit: the mascot on the centre axis, the title, the date `/start` brings the account back until, one Primary. -->
    <div class="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-10 text-center" data-testid="closed">
      <MascotArt pose="hello" :height="120" class="mb-2" />
      <h2 class="t-display-m">{{ t('settings.deleted.title') }}</h2>
      <p class="t-body-m max-w-80 text-muted" data-testid="account-deleted-text">{{ text }}</p>
      <div class="mt-4 flex w-full max-w-72 flex-col gap-2">
        <button type="button" class="btn btn-primary btn-block" data-testid="account-deleted-close" @click="close()">
          {{ t('common.exit_app') }}
        </button>
      </div>
    </div>
  </div>
</template>
