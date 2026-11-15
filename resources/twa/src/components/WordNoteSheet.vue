<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { useI18n } from '@/i18n'

/**
 * "My hint" (docs/06 §12.3): the learner's own memory hook of the word on the card, in a bottom sheet. Plain text, up to
 * `max` characters — Unicode code points, as the server counts them, not UTF-16 units (an emoji is one); "Clear" removes it. `save` hands the text to the parent (the
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

const count = computed((): number => Array.from(text.value).length)

/** The text cut to `max` code points: `String.slice` counts UTF-16 units and would cut an emoji in half. */
function clamp(value: string): string {
  const points = Array.from(value)
  return points.length > max ? points.slice(0, max).join('') : value
}

// Typing or pasting past the limit cuts the text (the native `maxlength` counts UTF-16 units, so it is not used).
watch(text, (value) => {
  const clamped = clamp(value)
  if (clamped !== value) text.value = clamped
})

function onSave(): void {
  if (props.saving) return
  emit('save', clamp(text.value))
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
      :aria-label="t('card.note.title')"
      aria-describedby="note-hint"
      :placeholder="t('card.note.placeholder')"
      :disabled="saving"
      rows="3"
      data-testid="note-input"
    />
    <div class="flex items-start justify-between gap-3 text-[13px] leading-[18px] text-tg-hint">
      <p id="note-hint">{{ t('card.note.hint', { max }) }}</p>
      <span class="shrink-0 tabular-nums" data-testid="note-counter" aria-hidden="true">{{ count }} / {{ max }}</span>
    </div>
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
