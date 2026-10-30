<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from './AppIcon.vue'
import { useI18n } from '@/i18n'

/**
 * A bottom sheet over a dimmed page (docs/14 §4.3, `.sheet`): a grabber, a close button, the content in the default
 * slot, which scrolls when it is taller than the screen. The same frame as the paywall, for the short questions of the
 * practice screens ("Unfinished practice", "Who do we play with?", "Sound is not available").
 *
 * - `label` — the name of the dialog for a screen reader (`aria-label`); put the visible title in the slot as `<h2>`.
 * - `dismissible` — a tap on the dimmed page, the Escape key and the ✕ close it (`close`); default `true`. Switch it off
 *   for a question that must be answered with one of its buttons.
 *
 * a11y (minimal, like the paywall): the focus moves into the dialog on open and returns to the opener on close.
 * `data-testid`, `class` and other attributes go to the dialog (`<section role="dialog">`), not to the dimmed page.
 *
 * @example
 * <BottomSheet :label="t('practice.hub.active.title')" @close="dismiss">
 *   <h2 class="text-[22px] leading-7 font-bold">…</h2>
 *   <button class="btn btn-primary btn-block">…</button>
 * </BottomSheet>
 */
defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{ label: string; dismissible?: boolean }>(), { dismissible: true })
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const dialog = ref<HTMLElement | null>(null)
let opener: HTMLElement | null = null

function dismiss(): void {
  if (props.dismissible) emit('close')
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') dismiss()
}

onMounted(() => {
  opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
  document.addEventListener('keydown', onKeydown)
  void nextTick(() => dialog.value?.focus())
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  opener?.focus()
})
</script>

<template>
  <div class="scrim fixed inset-0 z-50 flex items-end justify-center" data-testid="sheet-backdrop" @click.self="dismiss">
    <section
      ref="dialog"
      v-bind="$attrs"
      class="sheet relative flex max-h-[90dvh] flex-col overflow-hidden outline-hidden"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      :aria-label="label"
    >
      <!-- A 44 px target around a 32 px circle; outside the scrolling part, so it stays in reach. -->
      <button
        v-if="dismissible"
        type="button"
        class="absolute top-1.5 right-1.5 z-10 flex size-11 items-center justify-center text-tg-hint"
        :aria-label="t('common.close')"
        data-testid="sheet-x"
        @click="emit('close')"
      >
        <span class="flex size-8 items-center justify-center rounded-full bg-fill"><AppIcon name="x-mark" :size="18" /></span>
      </button>

      <div class="-mx-4 min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-4">
        <slot />
      </div>
    </section>
  </div>
</template>
