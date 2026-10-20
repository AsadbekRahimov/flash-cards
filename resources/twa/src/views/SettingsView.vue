<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useOrganizationsStore } from '@/stores/organizations'
import { useTelegram } from '@/composables/useTelegram'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { ExerciseSettings, SettingsPatch } from '@/api/types'

const router = useRouter()
const auth = useAuthStore()
const settings = useSettingsStore()
const organizations = useOrganizationsStore()
const { showBackButton } = useTelegram()
const { t, tn } = useI18n()

const { data, status, saving, saved, errorMessage: message, timezoneLockMessage } = storeToRefs(settings)

// "My centers": the membership whose "Leave" waits for the confirmation tap (null = none).
const confirmingId = ref<number | null>(null)

// The "undo" of a time zone change: counts down to `timezone_undo_available_until`.
const nowMs = ref(Date.now())
let tick: ReturnType<typeof setInterval> | null = null

const undoLeft = computed((): string | null => {
  const iso = data.value?.timezone_undo_available_until
  const left = iso ? new Date(iso).getTime() - nowMs.value : Number.NaN
  if (!(left > 0)) return null

  const seconds = Math.ceil(left / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
})

let hideBack: (() => void) | null = null

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    settings.status = 'error'
    settings.errorMessage = errorMessage(err, 'settings')
    return
  }
  await settings.load()
  // Independent of the settings: a failure here only leaves the block out.
  await organizations.load()
}

async function leave(id: number): Promise<void> {
  await organizations.leave(id)
  confirmingId.value = null
}

/** Saves a change; when it is refused the select goes back to the value the server still has. */
async function change(event: Event, patch: SettingsPatch, current: () => string): Promise<void> {
  const select = event.target as HTMLSelectElement
  if (!(await settings.update(patch))) select.value = current()
}

const onLocale = (e: Event) =>
  change(e, { interface_locale: (e.target as HTMLSelectElement).value }, () => data.value?.interface_locale ?? '')

const onTimezone = (e: Event) =>
  change(e, { timezone: (e.target as HTMLSelectElement).value }, () => data.value?.timezone ?? '')

// '' is the "no reminders" option.
const onReminder = (e: Event) =>
  change(e, { reminder_time: (e.target as HTMLSelectElement).value || null }, () => data.value?.reminder_time ?? '')

const onGoal = (e: Event) =>
  change(e, { daily_goal: Number((e.target as HTMLSelectElement).value) }, () => String(data.value?.daily_goal ?? ''))

/** The exercise types of the settings screen, in display order (docs/13 §8.2); flash is always on. */
const EXERCISE_KEYS: (keyof ExerciseSettings)[] = ['reverse', 'audio', 'spelling', 'gap_fill']

/** A toggle saves at once; when the save is refused the box goes back to the value the server has. */
async function onExercise(key: keyof ExerciseSettings, event: Event): Promise<void> {
  const box = event.target as HTMLInputElement
  if (!(await settings.update({ exercises: { [key]: box.checked } }))) box.checked = data.value?.exercises[key] ?? true
}

onMounted(() => {
  hideBack = showBackButton(() => void router.push({ name: 'today' }))
  tick = setInterval(() => (nowMs.value = Date.now()), 1000)
  void load()
})

onBeforeUnmount(() => {
  if (tick !== null) clearInterval(tick)
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <h1 class="text-xl font-bold mb-4">{{ t('settings.title') }}</h1>

    <div v-if="status === 'idle' || status === 'loading'" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="!data" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ message }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <div v-else class="space-y-3">
      <p v-if="message" class="text-sm text-red-600" role="alert" data-testid="settings-error">{{ message }}</p>
      <p v-else-if="saved" class="text-sm text-tg-hint" data-testid="saved">✓ {{ t('settings.saved') }}</p>

      <label class="card block space-y-2">
        <span class="font-semibold">{{ t('settings.language') }}</span>
        <select class="w-full rounded-xl px-3 py-3" :value="data.interface_locale" :disabled="saving" data-testid="locale-select" @change="onLocale">
          <option v-for="option in data.options.locales" :key="option.id" :value="option.id">{{ option.label }}</option>
        </select>
      </label>

      <label class="card block space-y-2">
        <span class="font-semibold">{{ t('settings.timezone') }}</span>
        <select class="w-full rounded-xl px-3 py-3" :value="data.timezone" :disabled="saving || timezoneLockMessage !== null" data-testid="timezone-select" @change="onTimezone">
          <option v-for="option in data.options.timezones" :key="option.id" :value="option.id">{{ option.label }}</option>
        </select>
        <span v-if="timezoneLockMessage" class="block text-xs text-tg-hint" data-testid="timezone-locked">{{ timezoneLockMessage }}</span>
        <button
          v-if="undoLeft !== null"
          type="button"
          class="btn btn-secondary w-full"
          :disabled="saving"
          data-testid="timezone-undo"
          @click.prevent="settings.revertTimezone()"
        >
          {{ t('settings.timezone.undo', { time: undoLeft }) }}
        </button>
      </label>

      <label class="card block space-y-2">
        <span class="font-semibold">{{ t('settings.reminder') }}</span>
        <select class="w-full rounded-xl px-3 py-3" :value="data.reminder_time ?? ''" :disabled="saving" data-testid="reminder-select" @change="onReminder">
          <option v-for="time in data.options.reminder_times" :key="time" :value="time">{{ time }}</option>
          <option value="">{{ t('settings.reminder.off') }}</option>
        </select>
        <span class="block text-xs text-tg-hint">{{ t('settings.reminder.hint') }}</span>
      </label>

      <label class="card block space-y-2">
        <span class="font-semibold">{{ t('settings.daily_goal') }}</span>
        <select class="w-full rounded-xl px-3 py-3" :value="data.daily_goal" :disabled="saving" data-testid="goal-select" @change="onGoal">
          <option v-for="goal in data.options.daily_goals" :key="goal" :value="goal">{{ tn('settings.daily_goal.option', goal) }}</option>
        </select>
      </label>

      <div class="card space-y-3" data-testid="exercises-card">
        <span class="font-semibold">{{ t('settings.exercises') }}</span>
        <label v-for="key in EXERCISE_KEYS" :key="key" class="flex items-center gap-3">
          <input
            type="checkbox"
            class="h-5 w-5"
            :checked="data.exercises[key]"
            :disabled="saving"
            :data-testid="`exercise-${key}`"
            @change="onExercise(key, $event)"
          />
          <span>{{ t(`settings.exercises.${key}`) }}</span>
        </label>
        <span class="block text-xs text-tg-hint">{{ t('settings.exercises.hint') }}</span>
      </div>

      <div class="card space-y-3" data-testid="course-card">
        <div>
          <div class="font-semibold">{{ t('settings.course') }}</div>
          <div class="text-sm" :class="data.active_course ? '' : 'text-tg-hint'" data-testid="course-title">
            {{ data.active_course ? data.active_course.title : t('settings.course.none') }}
          </div>
        </div>
        <RouterLink :to="{ name: 'courses' }" class="btn btn-secondary w-full" data-testid="catalog-link">
          {{ t('catalog.open') }}
        </RouterLink>
      </div>

      <div v-if="organizations.items.length > 0" class="card space-y-3" data-testid="orgs-card">
        <div class="font-semibold">{{ t('orgs.title') }}</div>
        <p v-if="organizations.errorMessage" class="text-sm text-red-600" role="alert" data-testid="orgs-error">{{ organizations.errorMessage }}</p>

        <div v-for="org in organizations.items" :key="org.id" class="space-y-2" :data-testid="`org-${org.id}`">
          <div>
            <div class="font-medium" data-testid="org-name">{{ org.name }}</div>
            <div class="text-sm text-tg-hint" data-testid="org-state">
              {{ org.seat_state === 'seated' ? t('orgs.seated') : t('orgs.waitlisted') }}
            </div>
          </div>

          <p v-if="!org.can_leave" class="text-xs text-tg-hint" data-testid="org-via-group">{{ t('orgs.via_group') }}</p>

          <button v-else-if="confirmingId !== org.id" type="button" class="btn btn-secondary w-full" data-testid="org-leave" @click="confirmingId = org.id">
            {{ t('orgs.leave') }}
          </button>
          <div v-else class="space-y-2" data-testid="org-confirm">
            <p class="text-sm">{{ t('orgs.leave.confirm', { name: org.name }) }}</p>
            <div class="flex gap-2">
              <button type="button" class="btn btn-primary flex-1" :disabled="organizations.leaving" data-testid="org-leave-yes" @click="leave(org.id)">
                {{ t('orgs.leave.yes') }}
              </button>
              <button type="button" class="btn btn-secondary flex-1" data-testid="org-leave-no" @click="confirmingId = null">
                {{ t('orgs.leave.no') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <RouterLink :to="{ name: 'billing' }" class="btn btn-secondary w-full" data-testid="billing-link">
        {{ t('billing.open') }}
      </RouterLink>
    </div>
  </section>
</template>
