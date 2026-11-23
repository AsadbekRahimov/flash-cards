<script setup lang="ts">
import { computed, onScopeDispose } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * The speaker of the example sentence (docs/26 §9.11): the pre-generated mp3 of exactly the sentence shown
 * (`example_audio_url`), played by a tap — never by itself, and never by the phone's voice (the parent renders the button
 * only when there is an address). The word and the example share one player (`useTts`): a tap here stops the word, the
 * word stops the example. While it sounds the button is lit (the soft accent with its thin frame, as the speaker of a listening
 * task); a second tap stops it. A file that does not load leaves the button as it was, without a message.
 *
 * The look is the speaker of the word (44 px round, the accent icon); the card that leaves stops its example.
 */
const props = defineProps<{ url: string }>()

const { play, stop, playingUrl } = useTts()
const { selectionHaptic } = useTelegram()
const { t } = useI18n()

const playing = computed((): boolean => playingUrl.value === props.url)

function onTap(): void {
  selectionHaptic()
  if (playing.value) {
    stop()
    return
  }
  void play(props.url)
}

onScopeDispose(() => {
  if (playing.value) stop()
})
</script>

<template>
  <button
    type="button"
    class="flex size-11 shrink-0 items-center justify-center rounded-full"
    :class="playing ? 'bg-accent-soft text-accent-ink shadow-[inset_0_0_0_1px_var(--app-accent-line)]' : 'text-accent active:bg-fill'"
    :aria-label="t('card.speak_example')"
    :aria-pressed="playing ? 'true' : 'false'"
    :data-playing="playing ? 'true' : undefined"
    data-testid="example-speak-btn"
    @click="onTap"
  >
    <AppIcon name="speaker-wave" :size="20" />
  </button>
</template>
