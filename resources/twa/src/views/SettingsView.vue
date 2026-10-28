<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedControl, { type SegmentOption } from '@/components/ui/SegmentedControl.vue'
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue'
import { useBackButton } from '@/composables/useBackButton'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useOrganizationsStore } from '@/stores/organizations'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { ExerciseSettings, SettingsPatch } from '@/api/types'

/**
 * "Settings" (docs/14 §6.6): grouped lists in the style of Telegram settings. Learning (daily goal, main course,
 * exercises), reminders, language and region, leagues and "My centers". Every change is saved at once ("Saved" is a
 * toast); a refused change leaves the control at the value the server still has. A page of the Profile tab: the system
 * "Back" button leads to the Profile.
 */
const auth = useAuthStore()
const settings = useSettingsStore()
const organizations = useOrganizationsStore()
const toast = useToast()
const { t } = useI18n()

useBackButton({ name: 'profile' })

const { data, status, saving, errorMessage: message, timezoneLockMessage } = storeToRefs(settings)

/** Up to this many daily goals fit side by side as a segmented control; more go to a select. */
const MAX_SEGMENTS = 4

/** The exercise types of the settings screen, in display order (docs/13 §8.2); flash is always on. */
const EXERCISE_KEYS: (keyof ExerciseSettings)[] = ['reverse', 'audio', 'spelling', 'gap_fill']
/** Typed answers are a Pro feature (docs/13 §8.2): their rows carry the "Pro" chip. */
const PRO_EXERCISES: readonly (keyof ExerciseSettings)[] = ['spelling', 'gap_fill']

// The native selects work on a copy of the server's values. When a change is refused the copy is set back from the
// data (through state: Vue then puts the select back), never by touching the DOM element.
const draft = reactive({ timezone: '', reminder: '', goal: 0 })

function syncDraft(): void {
  const current = data.value
  if (!current) return

  draft.timezone = current.timezone
  draft.reminder = current.reminder_time ?? '' // '' is the "no reminders" option
  draft.goal = current.daily_goal
}

watch(data, syncDraft, { immediate: true })

const goalOptions = computed<SegmentOption[]>(() =>
  (data.value?.options.daily_goals ?? []).map((goal) => ({ value: goal, label: String(goal), testid: `goal-${goal}` })),
)

const goalAsSegments = computed(() => goalOptions.value.length <= MAX_SEGMENTS)

const localeOptions = computed<SegmentOption[]>(() =>
  (data.value?.options.locales ?? []).map((option) => ({ value: option.id, label: option.label, testid: `locale-${option.id}` })),
)

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

/** Saves one change and says "Saved" (in the language the change has just switched to); a refusal puts the selects back. */
async function save(patch: SettingsPatch): Promise<void> {
  if (await settings.update(patch)) toast.show(t('common.saved'))
  syncDraft()
}

async function revertTimezone(): Promise<void> {
  if (await settings.revertTimezone()) toast.show(t('common.saved'))
  syncDraft()
}

const onLocale = (value: string | number) => save({ interface_locale: String(value) })
const onGoal = (value: string | number) => save({ daily_goal: Number(value) })
const onTimezone = () => save({ timezone: draft.timezone })
const onReminder = () => save({ reminder_time: draft.reminder || null })
const onLeagues = (participates: boolean) => save({ leagues_opt_out: !participates })
const onExercise = (key: keyof ExerciseSettings, enabled: boolean) => save({ exercises: { [key]: enabled } })

onMounted(() => {
  tick = setInterval(() => (nowMs.value = Date.now()), 1000)
  void load()
})

onBeforeUnmount(() => {
  if (tick !== null) clearInterval(tick)
})
</script>

<template>
  <div class="page" data-testid="settings-view">
    <PageHeader :title="t('settings.title')" />

    <LoadingState v-if="status === 'idle' || status === 'loading'" variant="list" />

    <ErrorState v-else-if="!data" :message="message || t('error.generic')" @retry="load" />

    <div v-else class="space-y-4">
      <InlineAlert v-if="message" tone="danger" :message="message" data-testid="settings-error" />

      <ListGroup :header="t('settings.group.learning')" :footer="t('settings.daily_goal.unit')">
        <div class="list-row flex-col items-stretch gap-2" data-testid="goal-row">
          <span class="text-base leading-[22px]">{{ t('settings.daily_goal') }}</span>
          <div data-testid="goal-select">
            <SegmentedControl
              v-if="goalAsSegments"
              :model-value="data.daily_goal"
              :options="goalOptions"
              :disabled="saving"
              :ariaLabel="t('settings.daily_goal')"
              @update:model-value="onGoal"
            />
            <select v-else v-model="draft.goal" class="field" :disabled="saving" :aria-label="t('settings.daily_goal')" @change="onGoal(draft.goal)">
              <option v-for="option in goalOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
        </div>
      </ListGroup>

      <ListGroup>
        <!--
          A link row like `ListRow`, built by hand: the value of a `ListRow` never shrinks and its title wraps next to
          a long course name. Here the title keeps its line and a long name takes up to two lines.
        -->
        <RouterLink :to="{ name: 'courses' }" class="list-row" data-testid="course-card">
          <span class="shrink-0 text-base leading-[22px]">{{ t('settings.course') }}</span>
          <span class="line-clamp-2 min-w-0 flex-1 text-right text-base leading-[22px] break-words text-tg-hint" data-testid="course-title">
            {{ data.active_course ? data.active_course.title : t('settings.course.none') }}
          </span>
          <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
        </RouterLink>
      </ListGroup>

      <ListGroup :header="t('settings.exercises')" :footer="t('settings.exercises.always')" data-testid="exercises-card">
        <div v-for="key in EXERCISE_KEYS" :key="key" class="list-row" :data-testid="`exercise-row-${key}`">
          <span class="flex min-w-0 flex-1 items-center gap-2 text-base leading-[22px]">
            <span class="min-w-0">{{ t(`settings.exercises.${key}`) }}</span>
            <span v-if="PRO_EXERCISES.includes(key)" class="chip chip-accent shrink-0" data-testid="exercise-pro">{{ t('profile.chip.pro') }}</span>
          </span>
          <ToggleSwitch
            :model-value="data.exercises[key]"
            :disabled="saving"
            :label="t(`settings.exercises.${key}`)"
            :data-testid="`exercise-${key}`"
            @update:model-value="onExercise(key, $event)"
          />
        </div>
      </ListGroup>

      <ListGroup :header="t('settings.group.reminders')" :footer="t('settings.reminder.hint')">
        <label class="list-row">
          <span class="min-w-0 flex-1 text-base leading-[22px]">{{ t('settings.reminder.time') }}</span>
          <select v-model="draft.reminder" class="field field-inline shrink-0" :disabled="saving" data-testid="reminder-select" @change="onReminder">
            <option v-for="time in data.options.reminder_times" :key="time" :value="time">{{ time }}</option>
            <option value="">{{ t('settings.reminder.off') }}</option>
          </select>
        </label>
      </ListGroup>

      <ListGroup :header="t('settings.group.region')">
        <div class="list-row flex-col items-stretch gap-2" data-testid="language-row">
          <span class="text-base leading-[22px]">{{ t('settings.language') }}</span>
          <div data-testid="locale-select">
            <SegmentedControl
              :model-value="data.interface_locale"
              :options="localeOptions"
              :disabled="saving"
              :ariaLabel="t('settings.language')"
              @update:model-value="onLocale"
            />
          </div>
        </div>

        <div class="list-row flex-col items-stretch gap-1.5" data-testid="timezone-row">
          <label class="flex items-center gap-3">
            <span class="min-w-0 flex-1 text-base leading-[22px]">{{ t('settings.timezone') }}</span>
            <select
              v-model="draft.timezone"
              class="field field-inline max-w-[62%] min-w-0 truncate"
              :disabled="saving || timezoneLockMessage !== null"
              data-testid="timezone-select"
              @change="onTimezone"
            >
              <option v-for="option in data.options.timezones" :key="option.id" :value="option.id">{{ option.label }}</option>
            </select>
          </label>
          <p v-if="timezoneLockMessage" class="text-[13px] leading-[18px] text-tg-hint" data-testid="timezone-locked">{{ timezoneLockMessage }}</p>
          <button
            v-if="undoLeft !== null"
            type="button"
            class="btn btn-tinted btn-sm self-start"
            :disabled="saving"
            data-testid="timezone-undo"
            @click="revertTimezone"
          >
            {{ t('settings.timezone.undo', { time: undoLeft }) }}
          </button>
        </div>
      </ListGroup>

      <ListGroup :header="t('settings.group.leagues')" :footer="t('settings.leagues.hint')">
        <div class="list-row">
          <span class="min-w-0 flex-1 text-base leading-[22px]">{{ t('settings.leagues.toggle') }}</span>
          <ToggleSwitch
            :model-value="!data.leagues_opt_out"
            :disabled="saving"
            :label="t('settings.leagues.toggle')"
            data-testid="leagues-toggle"
            @update:model-value="onLeagues"
          />
        </div>
      </ListGroup>

      <ListGroup v-if="organizations.items.length > 0" :header="t('orgs.title')" data-testid="orgs-card">
        <div v-for="org in organizations.items" :key="org.id" class="list-row flex-col items-stretch gap-2" :data-testid="`org-${org.id}`">
          <div class="flex items-center gap-3">
            <span class="min-w-0 flex-1 text-base leading-[22px] break-words" data-testid="org-name">{{ org.name }}</span>
            <span class="chip shrink-0" :class="org.seat_state === 'seated' ? 'chip-success' : 'chip-warning'" data-testid="org-state">
              {{ org.seat_state === 'seated' ? t('orgs.seated') : t('orgs.waitlisted') }}
            </span>
          </div>

          <p v-if="!org.can_leave" class="text-[13px] leading-[18px] text-tg-hint" data-testid="org-via-group">{{ t('orgs.via_group') }}</p>

          <button
            v-else-if="confirmingId !== org.id"
            type="button"
            class="btn btn-danger btn-sm self-start"
            data-testid="org-leave"
            @click="confirmingId = org.id"
          >
            {{ t('orgs.leave') }}
          </button>
          <div v-else class="space-y-3" data-testid="org-confirm">
            <p class="text-sm leading-5">{{ t('orgs.leave.confirm', { name: org.name }) }}</p>
            <div class="flex gap-2">
              <button type="button" class="btn btn-danger flex-1" :disabled="organizations.leaving" data-testid="org-leave-yes" @click="leave(org.id)">
                {{ t('orgs.leave.yes') }}
              </button>
              <button type="button" class="btn btn-secondary flex-1" data-testid="org-leave-no" @click="confirmingId = null">
                {{ t('orgs.leave.no') }}
              </button>
            </div>
          </div>
        </div>
      </ListGroup>
      <InlineAlert
        v-if="organizations.errorMessage && organizations.items.length > 0"
        tone="danger"
        :message="organizations.errorMessage"
        data-testid="orgs-error"
      />
    </div>
  </div>
</template>
