<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/i18n'

/**
 * The "memory strength" of a word as five dots of the kit Muhrla (F1-povtorenie, F2-moy-slovar; docs/40 §2.2): `strength` of them are
 * filled - in the danger colour while the word is weak (≤ 2), in the muhr colour otherwise - the rest are the sunken paper. The number is
 * said in words too (`aria-label` «Память: 3 из 5»), never by the colour alone.
 *
 * - `strength` — 0..5 (a value outside is clamped; a fraction is rounded down).
 * - `size` — `md` (14 px dots: under the card of a lesson) or `sm` (8 px: a row of the dictionary).
 *
 * @example <MemoryDots :strength="card.memory.strength" />
 */
const props = withDefaults(defineProps<{ strength: number; size?: 'md' | 'sm' }>(), { size: 'md' })

const { t } = useI18n()

/** The dots there are: the scale of the server (docs/06 §13.3a). */
const DOTS = 5
/** Up to this strength a word is weak (the filter "Weak" of the dictionary, docs/40 §2.3). */
const WEAK_MAX = 2

const filled = computed(() => Math.min(DOTS, Math.max(0, Math.floor(Number.isFinite(props.strength) ? props.strength : 0))))
const tone = computed(() => (filled.value <= WEAK_MAX ? 'bg-danger' : 'bg-muhr'))
</script>

<template>
  <span
    role="img"
    class="inline-flex shrink-0 items-center"
    :class="size === 'md' ? 'gap-1.5' : 'gap-[3px]'"
    :aria-label="t('card.memory.label', { strength: filled })"
    :data-strength="filled"
    data-testid="memory-dots"
  >
    <span
      v-for="index in DOTS"
      :key="index"
      class="block rounded-full"
      :class="[
        size === 'md' ? 'size-3.5' : 'size-2',
        index <= filled ? tone : ['bg-sunken', size === 'md' ? 'border border-hairline' : ''],
      ]"
      :data-filled="index <= filled ? 'true' : 'false'"
      aria-hidden="true"
    />
  </span>
</template>
