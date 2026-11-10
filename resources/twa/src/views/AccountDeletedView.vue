<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import ClosedState from '@/components/ClosedState.vue'
import { formatDate } from '@/composables/format'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * "Account deleted" after Settings → Delete account: says that the account is gone, until when `/start` in the bot brings it
 * back (`until`, the date the server named), and closes the Mini App (the "Close" button, or by itself after
 * `CLOSE_AFTER_MS`). A screen with no tab bar and no request: the session is already dropped, and any call with the old
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
    <ClosedState icon="check-circle" :title="t('settings.deleted.title')" :text="text">
      <button type="button" class="btn btn-primary btn-block" data-testid="account-deleted-close" @click="close()">
        {{ t('common.exit_app') }}
      </button>
    </ClosedState>
  </div>
</template>
