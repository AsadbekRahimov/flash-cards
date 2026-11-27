<script setup lang="ts">
/**
 * The card of a city that the learner has reached (docs/05 §5b.8): "I got to Samarkand", under it the name of the game and "Share". The
 * heading is the one the server wrote in the learner's language (`summary.boss.share.title`, the same as on the picture of the DM card).
 * "Share" is the business of the screen that owns the run (`share` event: the message through the bot or the dialog of Telegram, then the
 * mark); `busy` - that share is on its way: the button is disabled and spins.
 */
import AppIcon from '@/components/ui/AppIcon.vue'
import { useI18n } from '@/i18n'

withDefaults(defineProps<{
  /** "I got to Samarkand". */
  title: string
  busy?: boolean
}>(), { busy: false })

defineEmits<{ share: [] }>()

const { t } = useI18n()
</script>

<template>
  <section class="card flex flex-col gap-3" data-testid="city-card">
    <div class="min-w-0">
      <h3 class="t-title-m break-words" data-testid="city-card-title">{{ title }}</h3>
      <p class="t-label-s mt-0.5 text-muted">{{ t('gameRun.summary.card_caption') }}</p>
    </div>
    <button
      type="button"
      class="btn btn-secondary btn-block"
      :disabled="busy"
      :aria-busy="busy ? 'true' : undefined"
      data-testid="share-btn"
      @click="$emit('share')"
    >
      <span
        v-if="busy"
        class="size-[18px] shrink-0 animate-spin rounded-full border-2 border-track border-t-inverse motion-reduce:animate-none"
        aria-hidden="true"
        data-testid="share-busy"
      />
      <AppIcon v-else name="share" :size="18" />{{ t('gameRun.summary.share') }}
    </button>
  </section>
</template>
