<script setup lang="ts">
import { ref } from 'vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { useI18n } from '@/i18n'

/**
 * "My hint" (docs/06 §12.3): the learner's own memory hook of the word on the card, in a bottom sheet. Plain text, up to
 * `max` characters (the server cuts to the same length); "Clear" removes it. `save` hands the text to the parent (the
 * training store sends it); the sheet closes when the parent says so.
 */
const props = defineProps<{
  word: string
  note: string | null
  saving?: boolean
  max?: number
}>()

const emit = defineEmits<{
  (e: 'save', note: string): void
  (e: 'close'): void
}>()

const { t } = useI18n()
const text = ref(props.note ?? '')
const max = props.max ?? 200

function onSave(): void {
  if (props.saving) return
  emit('save', text.value.slice(0, max))
}

function onClear(): void {
  if (props.saving) return
  text.value = ''
  emit('save', '')
}
</script>

<template>
  <BottomSheet :label="t('card.note.title')" data-testid="note-sheet" @close="emit('close')">
    <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ t('card.note.title') }}</h2>
    <p class="text-base leading-[22px] font-semibold break-words" data-testid="note-word">{{ word }}</p>
    <textarea
      v-model="text"
      class="field min-h-[96px] w-full resize-none"
      :maxlength="max"
      :placeholder="t('card.note.placeholder')"
      :disabled="saving"
      rows="3"
      data-testid="note-input"
    />
    <p class="text-[13px] leading-[18px] text-tg-hint">{{ t('card.note.hint', { max }) }}</p>
    <button type="button" class="btn btn-primary btn-block" :disabled="saving" data-testid="note-save" @click="onSave">
      {{ t('card.note.save') }}
    </button>
    <button
      v-if="note"
      type="button"
      class="btn btn-danger btn-block"
      :disabled="saving"
      data-testid="note-clear"
      @click="onClear"
    >
      {{ t('card.note.clear') }}
    </button>
  </BottomSheet>
</template>
