<script setup lang="ts">
import type { Card } from '@/api/types'
import { useI18n } from '@/i18n'

/**
 * The memory hooks of a card (docs/25 §3.4, docs/06 §12.3, §12.6), under the translation: the authored hook of the
 * card's language ("How to remember"), the learner's own note ("My hint"), and for a hard word the pair it is confused
 * with. Shown only when the card carries them (the server sends the hook for a new, failed or hard card only), and the
 * parent decides when (after the reveal of a flash card, after the pick of a four-option card). `editable` adds the
 * "my hint" button, which asks the parent to open the note sheet.
 */
const props = defineProps<{
  card: Card
  editable?: boolean
}>()

const emit = defineEmits<{
  (e: 'edit-note'): void
}>()

const { t } = useI18n()
</script>

<template>
  <div
    v-if="card.hook || card.note || card.confused_with || editable"
    class="flex flex-col gap-2 text-left"
    data-testid="card-hooks"
  >
    <p v-if="card.hook" class="text-[15px] leading-5 text-tg-subtitle" data-testid="hook">
      <span class="font-medium text-tg-section-header">{{ t('card.hook.title') }}:</span>
      {{ card.hook }}
    </p>
    <p v-if="card.note" class="text-[15px] leading-5 text-tg-subtitle" data-testid="note">
      <span class="font-medium text-tg-section-header">{{ t('card.note.title') }}:</span>
      {{ card.note }}
    </p>
    <p v-if="card.confused_with" class="text-[15px] leading-5 text-tg-subtitle" data-testid="confused-with">
      <span class="font-medium text-tg-section-header">{{ t('card.confused_with') }}:</span>{{ ' ' }}
      <span class="font-semibold">{{ card.confused_with.word }}</span>
      <template v-if="card.confused_with.translation"> — {{ card.confused_with.translation }}</template>
    </p>
    <button
      v-if="props.editable"
      type="button"
      class="btn btn-tinted btn-sm self-start"
      data-testid="note-btn"
      @click="emit('edit-note')"
    >
      {{ card.note ? t('card.note.edit') : t('card.note.add') }}
    </button>
  </div>
</template>
