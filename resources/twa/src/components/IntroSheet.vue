<script setup lang="ts">
import { computed, ref } from 'vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'

/**
 * "How it works": four short steps in a bottom sheet (docs/14 §3, the product explains itself). Opened by Home on the
 * first visit of a newcomer and by the "How it works" row of the profile. `close` comes from "Start", the ✕ or the dimmed page.
 */
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()

const STEPS = [1, 2, 3, 4] as const
const step = ref(0)
const last = computed(() => step.value === STEPS.length - 1)

const title = computed(() => t(`intro.step${step.value + 1}.title` as StringKey))
const body = computed(() => t(`intro.step${step.value + 1}.text` as StringKey))

function next(): void {
  if (last.value) emit('close')
  else step.value += 1
}
</script>

<template>
  <BottomSheet :label="t('intro.title')" data-testid="intro-sheet" @close="emit('close')">
    <div class="space-y-3 pt-2">
      <p class="text-[13px] leading-[18px] font-semibold tracking-wide text-accent-ink uppercase">{{ t('intro.title') }}</p>
      <h2 class="text-[22px] leading-7 font-bold" data-testid="intro-title">{{ title }}</h2>
      <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="intro-text">{{ body }}</p>
      <div class="flex justify-center gap-1.5" aria-hidden="true" data-testid="intro-dots">
        <span
          v-for="(n, i) in STEPS"
          :key="n"
          class="size-2 rounded-full"
          :class="i === step ? 'bg-accent' : 'bg-fill'"
        />
      </div>
    </div>
    <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="intro-next" @click="next">
      {{ t(last ? 'intro.start' : 'intro.next') }}
    </button>
  </BottomSheet>
</template>
