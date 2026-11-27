<script setup lang="ts">
import { computed, ref } from 'vue'
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue'
import TopicArt from '@/components/ui/TopicArt.vue'
import OnboardingActions from './OnboardingActions.vue'
import SelectableTile from './SelectableTile.vue'
import StepIntro from './StepIntro.vue'
import { useOnboardingStore } from '@/stores/onboarding'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * O7 "Reminder" (kit 02-onboarding/O7): the five times of the server and "Custom" (a time field, a quarter of an hour), the switch
 * "Messages from the bot" and "Do not remind".
 *
 * The switch is Telegram's permission for the bot to write (`requestWriteAccess`, Bot API 6.9): it is asked when the switch is turned on,
 * and on "Continue" while it is on and not asked yet. A refusal turns it off and says why - "Continue" then goes on with the refusal, the
 * reminder itself is saved anyway (the bot learns of a blocked chat by itself). A client that cannot ask (older than 6.9, outside
 * Telegram) has no switch. The server learns the answer only as a field of the event (`write_access`).
 */
const emit = defineEmits<{ next: []; exit: [] }>()
const store = useOnboardingStore()
const { canRequestWriteAccess, requestWriteAccess, selectionHaptic } = useTelegram()
const { t } = useI18n()

const canAsk = ref(canRequestWriteAccess())
/** The permission was refused on this screen: the line under the switch says what that means. */
const denied = ref(store.draft.writeAccess === 'denied')
const asking = ref(false)

const times = computed((): string[] => store.data?.options.reminder_times ?? [])
const stepMinutes = computed((): number => store.data?.options.reminder_step_minutes ?? 15)
const busy = computed(() => store.saving === 'reminder' || asking.value)
const draft = computed(() => store.draft)

function pickTime(time: string): void {
  if (!draft.value.reminderCustom && draft.value.reminderTime === time) return
  draft.value.reminderTime = time
  draft.value.reminderCustom = false
  store.clearError()
  selectionHaptic()
}

function pickCustom(): void {
  if (draft.value.reminderCustom) return
  draft.value.reminderCustom = true
  draft.value.reminderTime = null
  store.clearError()
  selectionHaptic()
}

/** The custom time, put on the server's step (a quarter of an hour): 07:50 -> 07:45. */
function onCustomTime(event: Event): void {
  const value = (event.target as HTMLInputElement).value
  const match = /^(\d{2}):(\d{2})/.exec(value)
  if (match === null) {
    draft.value.reminderTime = null
    return
  }
  const minutes = Number(match[2])
  const snapped = Math.floor(minutes / stepMinutes.value) * stepMinutes.value
  draft.value.reminderTime = `${match[1]}:${String(snapped).padStart(2, '0')}`
  store.clearError()
}

/** Asks Telegram; `unsupported` - the client could not ask after all: no switch any more. */
async function ask(): Promise<'granted' | 'denied' | 'unsupported'> {
  asking.value = true
  try {
    const answer = await requestWriteAccess()
    if (answer === 'unsupported') {
      canAsk.value = false
      draft.value.writeAccess = 'not_asked'
    } else {
      draft.value.writeAccess = answer
      draft.value.botMessages = answer === 'granted'
      denied.value = answer === 'denied'
    }
    return answer
  } finally {
    asking.value = false
  }
}

async function onSwitch(on: boolean): Promise<void> {
  draft.value.botMessages = on
  if (!on) {
    // Turned off by hand: no request, and nothing to explain.
    if (draft.value.writeAccess !== 'granted') draft.value.writeAccess = 'not_asked'
    denied.value = false
    return
  }
  denied.value = false
  await ask()
}

async function save(enabled: boolean): Promise<void> {
  const outcome = await store.saveReminder(enabled)
  if (outcome === 'ok') emit('next')
  else if (outcome === 'completed') emit('exit')
}

async function onContinue(): Promise<void> {
  if (canAsk.value && draft.value.botMessages && draft.value.writeAccess === 'not_asked') {
    // Asked from this tap; a refusal stays on screen once (the switch goes off, the line says why), the next "Continue" goes on.
    if ((await ask()) === 'denied') return
  }
  await save(true)
}

async function onSkip(): Promise<void> {
  await save(false)
}
</script>

<template>
  <div class="flex flex-1 flex-col">
    <StepIntro :title="t('onboarding.reminder.title')" :lead="t('onboarding.reminder.lead')">
      <TopicArt course="english-a1-a2" :stage="12" :size="88" />
    </StepIntro>
    <div class="mt-5 grid grid-cols-3 gap-2.5" role="radiogroup" :aria-label="t('onboarding.reminder.title')">
      <SelectableTile
        v-for="time in times"
        :key="time"
        :selected="!draft.reminderCustom && draft.reminderTime === time"
        look="ink"
        class="t-numeric-l h-[52px] rounded-md px-1"
        :data-testid="`reminder-${time.replace(':', '')}`"
        @click="pickTime(time)"
      >
        {{ time }}
      </SelectableTile>
      <SelectableTile
        :selected="draft.reminderCustom"
        look="ink"
        class="t-title-m h-[52px] rounded-md px-1"
        data-testid="reminder-custom"
        @click="pickCustom"
      >
        {{ t('onboarding.reminder.custom') }}
      </SelectableTile>
    </div>
    <label v-if="draft.reminderCustom" class="mt-3 flex flex-col gap-2" data-testid="reminder-custom-field">
      <span class="t-label-s text-muted">{{ t('onboarding.reminder.custom_label') }}</span>
      <input
        type="time"
        class="field t-body-l"
        :step="stepMinutes * 60"
        :value="draft.reminderTime ?? ''"
        data-testid="reminder-custom-input"
        @change="onCustomTime"
      />
      <span class="t-caption text-muted">{{ t('onboarding.reminder.custom_hint', { step: stepMinutes }) }}</span>
    </label>
    <div v-if="canAsk" class="card mt-4 flex items-center gap-3 py-3.5" data-testid="reminder-bot">
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="t-body-m-strong">{{ t('onboarding.reminder.bot_title') }}</span>
        <span class="t-label-s text-muted">{{ t('onboarding.reminder.bot_caption') }}</span>
      </span>
      <ToggleSwitch
        :model-value="draft.botMessages"
        :label="t('onboarding.reminder.bot_title')"
        :disabled="busy"
        data-testid="reminder-bot-switch"
        @update:model-value="onSwitch"
      />
    </div>
    <p v-if="canAsk && denied" class="t-label-s mt-2 px-1 text-muted" role="status" data-testid="reminder-denied">
      {{ t('onboarding.reminder.denied') }}
    </p>
    <OnboardingActions
      :label="t('onboarding.continue')"
      :disabled="draft.reminderTime === null"
      :busy="busy"
      :error="store.saveErrorStep === 'reminder' ? store.saveError : ''"
      :secondary="t('onboarding.reminder.skip')"
      @primary="onContinue"
      @secondary="onSkip"
    />
  </div>
</template>
