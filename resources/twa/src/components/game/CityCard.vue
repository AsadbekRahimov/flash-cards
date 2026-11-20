<script setup lang="ts">
/**
 * The card of a city that the learner has reached (docs/05 §5b.8): "I got to Samarkand", under it the name of the game and "Share". The
 * heading is the one the server wrote in the learner's language (`summary.boss.share.title`, the same as on the picture of the DM card).
 * "Share" is the business of the screen that owns the run (`share` event: the dialog of Telegram, then the mark).
 */
import AppIcon from '@/components/ui/AppIcon.vue'
import { useI18n } from '@/i18n'

defineProps<{
  /** "I got to Samarkand". */
  title: string
}>()

defineEmits<{ share: [] }>()

const { t } = useI18n()
</script>

<template>
  <section class="card flex flex-col gap-3" data-testid="city-card">
    <div class="min-w-0">
      <h3 class="text-[17px] leading-[22px] font-semibold break-words" data-testid="city-card-title">{{ title }}</h3>
      <p class="mt-0.5 text-[13px] leading-[18px] text-muted">{{ t('gameRun.summary.card_caption') }}</p>
    </div>
    <button type="button" class="btn btn-tinted btn-block" data-testid="share-btn" @click="$emit('share')">
      <AppIcon name="share" :size="18" />{{ t('gameRun.summary.share') }}
    </button>
  </section>
</template>
