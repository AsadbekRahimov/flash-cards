<script lang="ts">
/** Tone of an `InlineAlert`: how bad it is (`danger`, `warning`), good news (`success`) or just a note (`info`). */
export type AlertTone = 'danger' | 'warning' | 'success' | 'info'
</script>

<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'

/**
 * A message in the flow of a screen (docs/14 §4.4): a failed action or reload, a warning, a confirmation or a note, as
 * a soft box with an icon. One look for all of them, so a refused save on the Settings screen reads like a refused
 * course choice on the Courses one.
 *
 * - `tone` — `danger` (`role="alert"`: announced at once), or `warning` / `success` / `info` (`role="status"`: announced
 *   politely). The tone sets the colours (the soft token of the theme and its ink; `info` is the accent) and the icon
 *   (exclamation circle / exclamation triangle / check circle / information circle).
 * - `message` — the text, already localized (e.g. `errorMessage(err, 'catalog')`).
 * - default slot — the text when it needs markup; it replaces `message`.
 *
 * `data-testid`, `class` (the outer spacing, e.g. `mt-4`, or `w-full` in a centered column) and the other attributes go
 * to the root element; to scroll the message into view use `$el` of a template ref.
 *
 * @example <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" data-testid="select-error" />
 */
const props = defineProps<{
  tone: AlertTone
  message?: string
}>()

interface ToneLook {
  icon: IconName
  /** Colours of the box; the icon takes the text colour unless `iconClass` says otherwise. */
  box: string
  iconClass?: string
}

const LOOKS: Record<AlertTone, ToneLook> = {
  danger: { icon: 'exclamation-circle', box: 'bg-danger-soft text-danger-ink' },
  warning: { icon: 'exclamation-triangle', box: 'bg-warning-soft text-warning-ink' },
  success: { icon: 'check-circle', box: 'bg-success-soft text-success-ink' },
  // There is no "ink" for the accent: plain text on the soft accent, the icon carries the colour.
  info: { icon: 'information-circle', box: 'bg-accent-soft text-tg-text', iconClass: 'text-accent' },
}

const look = computed(() => LOOKS[props.tone])
</script>

<template>
  <div
    class="flex items-start gap-2 rounded-xl px-3.5 py-2.5 text-left text-[15px] leading-5"
    :class="look.box"
    :role="tone === 'danger' ? 'alert' : 'status'"
  >
    <AppIcon :name="look.icon" :size="20" class="mt-px" :class="look.iconClass" />
    <span class="min-w-0 flex-1 break-words"><slot>{{ message }}</slot></span>
  </div>
</template>
