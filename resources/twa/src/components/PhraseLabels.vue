<script setup lang="ts">
import { computed } from 'vue'
import type { PhraseInfo } from '@/api/types'
import { phraseLabels } from '@/composables/phraseText'
import { useI18n } from '@/i18n'

/**
 * The labels of a phrase card (docs/35 §3.3 d): small pills under the phrase - the register («разговорное»), UK / US (none for `both`) and
 * the kind («идиома»). They say nothing of the answer, so they are on the question as well. No labels - nothing is drawn.
 */
const props = defineProps<{
  phrase: PhraseInfo
}>()

const { t } = useI18n()

const labels = computed(() => phraseLabels(props.phrase))
</script>

<template>
  <ul v-if="labels.length > 0" class="flex flex-wrap items-center justify-center gap-1.5" :aria-label="t('phrase.labels')" data-testid="phrase-labels">
    <li
      v-for="item in labels"
      :key="item.key"
      class="h-6 rounded-full bg-fill px-2.5 text-xs leading-6 font-semibold whitespace-nowrap"
      :data-key="item.key"
      data-testid="phrase-label"
    >
      {{ item.label }}
    </li>
  </ul>
</template>
