<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { useI18n } from '@/i18n'

/**
 * "Delete account?" - the question before the account is deleted (Settings, danger zone): what happens, in four lines
 * (the data is deleted for good in 30 days, `/start` in the bot brings the account back before that, the payment
 * records are kept as the law requires, Pro stops). `btn-danger` "Yes, delete", `btn-secondary` "Cancel".
 *
 * - `busy` - the answer of the server is awaited: the buttons wait and the sheet cannot be dismissed.
 * - `message` - why the deletion was refused (shown in the sheet, above the buttons).
 *
 * Events: `confirm` (delete) and `cancel` (the ✕, the dimmed page, the Escape key and "Cancel": nothing happens).
 */
defineProps<{
  busy?: boolean
  message?: string
}>()

const emit = defineEmits<{ confirm: []; cancel: [] }>()

const { t } = useI18n()
</script>

<template>
  <BottomSheet :label="t('settings.delete.title')" :dismissible="!busy" data-testid="delete-sheet" @close="emit('cancel')">
    <header class="flex flex-col items-center gap-2 pt-1 text-center">
      <span class="flex size-14 items-center justify-center rounded-full bg-danger-soft text-danger-ink">
        <AppIcon name="exclamation-triangle" solid :size="28" />
      </span>
      <h2 class="text-[22px] leading-7 font-bold" data-testid="delete-title">{{ t('settings.delete.title') }}</h2>
    </header>

    <ul class="list-disc space-y-1.5 pl-5 text-[15px] leading-5" data-testid="delete-consequences">
      <li>{{ t('settings.delete.data') }}</li>
      <li>{{ t('settings.delete.return') }}</li>
      <li>{{ t('settings.delete.payments') }}</li>
      <li>{{ t('settings.delete.pro') }}</li>
    </ul>

    <InlineAlert v-if="message" tone="danger" :message="message" data-testid="delete-error" />

    <div class="space-y-2 pb-1">
      <button type="button" class="btn btn-danger btn-block" :disabled="busy" data-testid="delete-yes" @click="emit('confirm')">
        {{ t('settings.delete.yes') }}
      </button>
      <button type="button" class="btn btn-secondary btn-block" :disabled="busy" data-testid="delete-no" @click="emit('cancel')">
        {{ t('settings.delete.no') }}
      </button>
    </div>
  </BottomSheet>
</template>
