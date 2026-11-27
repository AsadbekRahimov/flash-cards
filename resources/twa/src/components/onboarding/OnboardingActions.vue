<script setup lang="ts">
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { useI18n } from '@/i18n'

/**
 * The bottom of a step of the onboarding (docs/40 §2.1): the one Primary in ink with the arrow; `aria-disabled` until there is an
 * answer (a tap then does nothing); while the step is saved - the three dots; a failed save - `InlineAlert` under it with "Retry" (the
 * answer stays in the store, "Retry" sends it again).
 *
 * - `label` — the text of the button; `disabled` — no answer yet; `busy` — the save is on its way; `arrow` — the arrow on the right.
 * - `error` — the localized failure ('' - none).
 * - events `primary` (also "Retry"), `secondary` (the text button under it, when `secondary` names one).
 * - slot `note` — a line under the button (O1 "Login with Telegram").
 */
withDefaults(
  defineProps<{ label: string; disabled?: boolean; busy?: boolean; arrow?: boolean; error?: string; secondary?: string | null }>(),
  { disabled: false, busy: false, arrow: true, error: '', secondary: null },
)

const emit = defineEmits<{ primary: []; secondary: [] }>()
const { t } = useI18n()
</script>

<template>
  <!-- Full width of the screen: the column of the step has 20 px at the sides, the block its own 20 px. -->
  <StickyActions class="-mx-5">
    <InlineAlert v-if="error" tone="danger" class="mb-1.5" data-testid="onboarding-error">
      {{ error }}
      <button type="button" class="ml-1 font-semibold underline underline-offset-2" data-testid="onboarding-retry" @click="emit('primary')">
        {{ t('common.retry') }}
      </button>
    </InlineAlert>
    <button
      type="button"
      class="btn btn-primary btn-block"
      :aria-disabled="disabled ? 'true' : undefined"
      :aria-busy="busy ? 'true' : undefined"
      :aria-label="busy ? t('onboarding.saving') : undefined"
      data-testid="onboarding-continue"
      @click="!disabled && !busy && emit('primary')"
    >
      <span v-if="busy" class="btn-dots" aria-hidden="true"><span /><span /><span /></span>
      <template v-else>
        <span>{{ label }}</span>
        <AppIcon v-if="arrow" name="arrow-right" :size="20" />
      </template>
    </button>
    <button
      v-if="secondary"
      type="button"
      class="btn btn-ghost btn-block"
      :aria-disabled="busy ? 'true' : undefined"
      data-testid="onboarding-secondary"
      @click="!busy && emit('secondary')"
    >
      {{ secondary }}
    </button>
    <slot name="note" />
  </StickyActions>
</template>
