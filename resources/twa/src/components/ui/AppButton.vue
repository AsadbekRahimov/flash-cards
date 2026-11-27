<script setup lang="ts">
import { useI18n } from '@/i18n'
import AppIcon from './AppIcon.vue'

/**
 * Button of the kit Muhrla (COMPONENTS.md "Button"): the `.btn` of the design system as a component, for the screens drawn anew.
 *
 * - `variant` — `primary` (the ink: the one main action of a screen), `secondary` (the surface in a hairline), `ghost` (no box:
 *   "Не сейчас", "Пропустить"). Default `primary`.
 * - `icon` — the `arrow-right` of the kit after the label (20 px).
 * - `block` — the width of its container (the kit's default for the main action).
 * - `loading` — three pulsing dots instead of the label; the button stays its size, is `aria-busy` and does not take a press.
 * - `disabled` — the sunken paper and the tertiary text; `aria-disabled` too.
 * - `type` — `button` by default.
 * Event: `click` (not while disabled or loading). Other attributes (`data-testid`, `class`) go to the `<button>`.
 *
 * @example <AppButton block icon @click="start">{{ t('today.start') }}</AppButton>
 */
const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost'
    icon?: boolean
    block?: boolean
    loading?: boolean
    disabled?: boolean
    type?: 'button' | 'submit'
  }>(),
  { variant: 'primary', type: 'button' },
)

const emit = defineEmits<{ click: [event: MouseEvent] }>()

const { t } = useI18n()

function onClick(event: MouseEvent): void {
  if (props.disabled || props.loading) return
  emit('click', event)
}
</script>

<template>
  <button
    :type="type"
    class="btn"
    :class="[`btn-${variant}`, { 'btn-block': block }]"
    :disabled="disabled"
    :aria-disabled="disabled ? 'true' : undefined"
    :aria-busy="loading ? 'true' : undefined"
    :data-variant="variant"
    @click="onClick"
  >
    <span v-if="loading" class="btn-dots" data-testid="btn-dots">
      <span /><span /><span />
      <span class="sr-only">{{ t('common.loading') }}</span>
    </span>
    <template v-else>
      <slot />
      <AppIcon v-if="icon" name="arrow-right" :size="20" />
    </template>
  </button>
</template>
