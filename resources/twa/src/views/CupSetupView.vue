<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import CupRules from '@/components/cup/CupRules.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import ClosedState from '@/components/ClosedState.vue'
import AppChip from '@/components/ui/AppChip.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue'
import { BRAND } from '@/brand'
import { cupFailure, cupPeriod, cupStatusLine, type CupFailure } from '@/composables/cupInfo'
import { useBackButton } from '@/composables/useBackButton'
import { useTelegram } from '@/composables/useTelegram'
import { api } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useCupsStore } from '@/stores/cups'
import { useI18n } from '@/i18n'
import type { CupSetup } from '@/api/types'

/**
 * The setup of a cup by an administrator of the chat (docs/33 §7.1, docs/04 A.23 `/cups/setup/{code}`; `TwaScreen::CupSetup`, the button
 * of the proposal in the chat). The server asks Telegram whether the learner administers the chat on every request: a refusal (403
 * `cup_not_chat_admin`, also "no such code") is the state "Only an administrator of the group can set up the cup".
 *
 * - A draft: the level (`options.levels`) and the language of the messages in the chat (`options.locales`), the rules, "Publish"
 *   (`POST …/publish`). 409 `cup_bot_not_admin` - make the bot an administrator; 422 `cup_material_unavailable` - another level.
 * - Published and open: the summaries in the chat (on / off, the time - `PATCH /cups/{id}/settings`), "Cancel the cup" with a confirmation
 *   (`POST /cups/{id}/cancel`), the way to the cup.
 * - Over or canceled: what it is, and the way to the cup.
 */
const props = defineProps<{ code: string }>()

const auth = useAuthStore()
const cups = useCupsStore()
const { haptic } = useTelegram()
const { t, locale } = useI18n()

useBackButton({ name: 'today' })

const setup = ref<CupSetup | null>(null)
const failure = ref<CupFailure | null>(null)
const loading = ref(true)
let left = false

const level = ref('')
const messagesLocale = ref('')

function take(next: CupSetup): void {
  setup.value = next
  level.value = next.level
  messagesLocale.value = next.locale
  summaries.value = next.summaries_enabled
  summaryTime.value = next.summary_time
}

async function load(): Promise<void> {
  loading.value = true
  failure.value = null
  try {
    await auth.ensure()
    const res = await api.cups.setup(props.code)
    if (!left) take(res)
  } catch (err) {
    if (!left) {
      setup.value = null
      failure.value = cupFailure(err)
    }
  } finally {
    if (!left) loading.value = false
  }
}

const draft = computed((): boolean => setup.value?.status === 'draft')
const open = computed((): boolean => setup.value?.status === 'scheduled' || setup.value?.status === 'running')
const statusLine = computed((): string => (setup.value ? cupStatusLine(setup.value, locale.value) : ''))
const period = computed((): string => (setup.value ? cupPeriod(setup.value, locale.value) : ''))

/** The language of the messages of the cup by its code; a code this build does not know is shown as it is. */
function localeName(code: string): string {
  if (code === 'ru') return t('cup.setup.locale_ru')
  if (code === 'uz') return t('cup.setup.locale_uz')
  return code
}

// ---- publish ----

const publishing = ref(false)
const publishError = ref('')

async function publish(): Promise<void> {
  if (publishing.value || !draft.value) return
  haptic('light')
  publishing.value = true
  publishError.value = ''
  try {
    const res = await api.cups.publish(props.code, { level: level.value, locale: messagesLocale.value })
    if (left) return
    take(res)
    cups.invalidate()
  } catch (err) {
    if (!left) publishError.value = cupFailure(err).message
  } finally {
    if (!left) publishing.value = false
  }
}

// ---- the summaries in the chat ----

const summaries = ref(true)
const summaryTime = ref('20:00')
const saving = ref(false)
const settingsError = ref('')
const saved = ref(false)

/** "HH:MM" -> the minute of the day (0…1439); null for anything else. */
function minuteOf(text: string): number | null {
  const match = /^(\d{1,2}):(\d{2})$/.exec(text)
  if (!match) return null
  const minute = Number(match[1]) * 60 + Number(match[2])
  return minute >= 0 && minute <= 1439 ? minute : null
}

const settingsChanged = computed((): boolean => {
  const current = setup.value
  return current !== null && (summaries.value !== current.summaries_enabled || minuteOf(summaryTime.value) !== current.summary_minute)
})

async function saveSettings(): Promise<void> {
  const current = setup.value
  const minute = minuteOf(summaryTime.value)
  if (current === null || saving.value || minute === null) return
  saving.value = true
  settingsError.value = ''
  saved.value = false
  try {
    const res = await api.cups.settings(current.id, { summaries_enabled: summaries.value, summary_minute: minute })
    if (left) return
    take(res)
    saved.value = true
  } catch (err) {
    if (!left) settingsError.value = cupFailure(err).message
  } finally {
    if (!left) saving.value = false
  }
}

// ---- cancel ----

const cancelOpen = ref(false)
const canceling = ref(false)
const cancelError = ref('')

async function cancelCup(): Promise<void> {
  const current = setup.value
  if (current === null || canceling.value) return
  canceling.value = true
  cancelError.value = ''
  try {
    const res = await api.cups.cancel(current.id)
    if (left) return
    take(res)
    cups.invalidate()
    cancelOpen.value = false
  } catch (err) {
    if (!left) cancelError.value = cupFailure(err).message
  } finally {
    if (!left) canceling.value = false
  }
}

function openCancel(): void {
  cancelError.value = ''
  cancelOpen.value = true
}

watch(() => props.code, () => void load())

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  left = true
})
</script>

<template>
  <div class="page" :data-state="loading ? 'loading' : failure ? failure.kind : (setup?.status ?? 'loading')" data-testid="cup-setup-view">
    <PageHeader :eyebrow="t('cup.brand_title', { brand: BRAND })" :title="setup ? setup.group_title : t('cup.setup.title')" :subtitle="setup ? t('cup.setup.title') : undefined" />

    <LoadingState v-if="loading" variant="cards" />

    <ErrorState v-else-if="failure && failure.retry" :message="failure.message" data-testid="failure" @retry="load" />

    <ClosedState
      v-else-if="failure"
      mascot="thinking"
      :title="failure.kind === 'not_admin' ? t('cup.setup.not_admin_title') : t('cup.invite.unavailable_title')"
      :text="failure.message"
      data-testid="failure"
    />

    <div v-else-if="setup" class="space-y-3">
      <section class="flex flex-col items-start gap-2 rounded-hero bg-highlight p-5" data-testid="setup-card">
        <AppChip>{{ t('cup.level', { level: setup.level }) }}</AppChip>
        <p v-if="period" class="t-display-m" data-testid="setup-period">{{ period }}</p>
        <p class="t-body-m text-muted" data-testid="setup-status">{{ statusLine }}</p>
      </section>

      <!-- A draft: the level and the language of the chat, then "Publish". -->
      <section v-if="draft" class="card flex flex-col gap-3" data-testid="publish-card">
        <label class="flex flex-col gap-1.5">
          <span class="t-label-s text-muted">{{ t('cup.setup.level') }}</span>
          <select v-model="level" class="field" :disabled="publishing" data-testid="level-select">
            <option v-for="option in setup.options.levels" :key="option" :value="option">{{ option }}</option>
          </select>
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="t-label-s text-muted">{{ t('cup.setup.locale') }}</span>
          <select v-model="messagesLocale" class="field" :disabled="publishing" data-testid="locale-select">
            <option v-for="option in setup.options.locales" :key="option" :value="option">{{ localeName(option) }}</option>
          </select>
        </label>
        <p class="text-[13px] leading-[18px] text-muted" data-testid="publish-hint">{{ t('cup.setup.publish_hint') }}</p>
        <InlineAlert v-if="publishError" tone="danger" :message="publishError" data-testid="publish-error" />
        <button
          type="button"
          class="btn btn-primary btn-lg btn-block"
          :disabled="publishing"
          :aria-busy="publishing ? 'true' : undefined"
          data-testid="publish-btn"
          @click="publish"
        >
          {{ t('cup.setup.publish') }}
        </button>
      </section>

      <!-- Published and open: the summaries of the day in the chat. -->
      <section v-if="open" class="card flex flex-col gap-3" data-testid="settings-card">
        <div class="flex min-h-11 items-center justify-between gap-3">
          <span class="t-body-m-strong">{{ t('cup.setup.summaries') }}</span>
          <ToggleSwitch v-model="summaries" :label="t('cup.setup.summaries')" :disabled="saving" data-testid="summaries-toggle" />
        </div>
        <label v-if="summaries" class="flex flex-col gap-1.5">
          <span class="t-label-s text-muted">{{ t('cup.setup.summary_time') }}</span>
          <input v-model="summaryTime" type="time" class="field" :disabled="saving" data-testid="summary-time" />
        </label>
        <InlineAlert v-if="settingsError" tone="danger" :message="settingsError" data-testid="settings-error" />
        <InlineAlert v-else-if="saved && !settingsChanged" tone="success" :message="t('common.saved')" data-testid="settings-saved" />
        <button
          type="button"
          class="btn btn-secondary btn-block"
          :disabled="saving || !settingsChanged || minuteOf(summaryTime) === null"
          :aria-busy="saving ? 'true' : undefined"
          data-testid="settings-save"
          @click="saveSettings"
        >
          {{ t('cup.setup.save') }}
        </button>
      </section>

      <details class="list group" data-testid="rules">
        <summary class="list-row min-h-11 cursor-pointer list-none select-none active:bg-fill [&::-webkit-details-marker]:hidden">
          <span class="t-body-m-strong min-w-0 flex-1">{{ t('cup.rules.title') }}</span>
          <AppIcon name="chevron-down" :size="20" class="shrink-0 text-muted transition-transform group-open:rotate-180" />
        </summary>
        <div class="px-3 pt-1 pb-4">
          <CupRules :cup="setup" :rules="setup.rules" />
        </div>
      </details>

      <RouterLink v-if="!draft" :to="{ name: 'cup', params: { id: setup.id } }" class="btn btn-plain btn-block" data-testid="to-cup">{{ t('cup.setup.to_cup') }}</RouterLink>

      <button v-if="draft || open" type="button" class="btn btn-ghost btn-block text-danger-ink" data-testid="cancel-btn" @click="openCancel">
        {{ t('cup.cancel.open') }}
      </button>
    </div>

    <BottomSheet v-if="cancelOpen" :label="t('cup.cancel.title')" @close="cancelOpen = false">
      <div class="flex flex-col gap-3 px-4 pt-2 pb-4" data-testid="cancel-sheet">
        <h2 class="t-display-m">{{ t('cup.cancel.title') }}</h2>
        <p class="text-[15px] leading-5 text-muted">{{ t('cup.cancel.text') }}</p>
        <InlineAlert v-if="cancelError" tone="danger" :message="cancelError" data-testid="cancel-error" />
        <button type="button" class="btn btn-primary btn-block" data-testid="cancel-keep" @click="cancelOpen = false">{{ t('cup.cancel.keep') }}</button>
        <button
          type="button"
          class="btn btn-danger btn-block"
          :disabled="canceling"
          :aria-busy="canceling ? 'true' : undefined"
          data-testid="cancel-confirm"
          @click="cancelCup"
        >
          {{ t('cup.cancel.confirm') }}
        </button>
      </div>
    </BottomSheet>
  </div>
</template>
