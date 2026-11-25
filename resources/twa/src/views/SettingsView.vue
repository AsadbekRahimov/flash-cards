<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import DeleteAccountSheet from '@/components/DeleteAccountSheet.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import RegionSheet from '@/components/RegionSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedControl, { type SegmentOption } from '@/components/ui/SegmentedControl.vue'
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue'
import { showErrorToast } from '@/composables/errorToast'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { isSoundEnabled, setSoundEnabled } from '@/composables/useFeedbackSound'
import { isAutoSpeakEnabled, setAutoSpeakEnabled } from '@/composables/useAutoSpeak'
import { isSayAloudEnabled, setSayAloudEnabled } from '@/composables/useSayAloudHint'
import { saveSetting } from '@/composables/useLocaleSwitch'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { PRO_EXERCISES } from '@/composables/proReminders'
import { useOrganizationsStore } from '@/stores/organizations'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { ExerciseSettings, LearnerSettings, SettingsPatch } from '@/api/types'

/**
 * "Settings" (docs/14 §6.6): grouped lists in the style of Telegram settings. Language comes first (the
 * language must be easy to find; there is no time zone row - owner's decision of 16.11.2026, everybody is in Uzbekistan's zone), then learning (daily goal, my course, exercises), reminders, leagues and "My centers". Every change is saved at once ("Saved" is a
 * toast); a refused change leaves the control at the value the server still has and is told in a toast too — the screen
 * is long, an alert at its top would be out of sight when the learner changes a league further down.
 * A page of the Profile tab: the system "Back" button leads to the Profile. The last group is the danger zone with
 * "Delete account" (owner's decision of 03.11.2026): a sheet says what happens, "Yes, delete" deletes and leads to the
 * "Account deleted" screen.
 */
const auth = useAuthStore()
const settings = useSettingsStore()
const organizations = useOrganizationsStore()
const router = useRouter()
const { t, locale } = useI18n()

useBackButton({ name: 'profile' })

const { data, status, saving, deleting, errorMessage: message } = storeToRefs(settings)

/** Up to this many daily goals fit side by side as a segmented control; more go to a select. */
const MAX_SEGMENTS = 4

/** The exercise types of the settings screen, in the order of the ladder (docs/13 §8.2, docs/06 §12.1); flash is always on. */
const EXERCISE_KEYS: (keyof ExerciseSettings)[] = ['meaning', 'reverse', 'audio', 'spelling', 'gap_fill']
/** The rows with a line under the name: what the exercise asks (the names of the others say it themselves). */
const EXERCISE_HINTS: Partial<Record<keyof ExerciseSettings, StringKey>> = { meaning: 'settings.exercises.meaning.hint' }
/** Typed answers are a Pro feature (docs/13 §8.2): their rows carry the "Pro" chip (`PRO_EXERCISES`, the same list marks the cards of a round). */

// The native selects work on a copy of the server's values. When a change is refused the copy is set back from the
// data (through state: Vue then puts the select back), never by touching the DOM element.
const draft = reactive({ reminder: '', goal: 0 })

function syncDraft(): void {
  const current = data.value
  if (!current) return

  draft.reminder = current.reminder_time ?? '' // '' is the "no reminders" option
  draft.goal = current.daily_goal
}

watch(data, syncDraft, { immediate: true })

// A refusal of a change (or of a reload while the data is on screen) is a toast; a screen that has no data shows the
// message in its error state instead and keeps it for the retry.
watch(message, (text) => {
  if (text === '' || !data.value) return
  showErrorToast(text)
  settings.clearError()
})

/** The highest goal the plan allows; null = no limit. */
const goalMax = computed((): number | null => data.value?.options.daily_goal_max ?? null)
const goalLocked = (goal: number): boolean => goalMax.value !== null && goal > goalMax.value

const goalOptions = computed<SegmentOption[]>(() =>
  (data.value?.options.daily_goals ?? []).map((goal) => ({ value: goal, label: String(goal), testid: `goal-${goal}`, locked: goalLocked(goal) })),
)
/** The select has no room for an icon: a locked goal says "40 — in Pro" in its text. */
const goalSelectLabel = (option: SegmentOption): string =>
  option.locked ? t('settings.daily_goal.locked', { value: option.label }) : option.label

/** The paywall of a goal behind Pro (docs/05 §8c-1). */
const goalPaywallOpen = ref(false)
const { reviewFromPaywall: onGoalPaywallReview } = useReviewFromPaywall(() => {
  goalPaywallOpen.value = false
}, { toastErrors: true })

const goalAsSegments = computed(() => goalOptions.value.length <= MAX_SEGMENTS)

/** The FSRS retention dial (docs/06 §12.8): calm / normal / intense — for learners scheduled by FSRS only. */
const RETENTION_MODES = ['calm', 'normal', 'intense'] as const
const retentionOptions = computed<SegmentOption[]>(() =>
  (data.value?.options.retention_modes ?? RETENTION_MODES)
    .filter((mode): mode is (typeof RETENTION_MODES)[number] => (RETENTION_MODES as readonly string[]).includes(mode))
    .map((mode) => ({ value: mode, label: t(`settings.retention.${mode}`), testid: `retention-${mode}` })),
)
const showsRetention = computed(() => data.value?.scheduler === 'fsrs')

/** "Say the word aloud" hint (docs/25 §3.11): kept on the device, nothing is sent to the server. */
const sayAloud = ref(isSayAloudEnabled())
function onSayAloud(on: boolean): void {
  setSayAloudEnabled(on)
  sayAloud.value = on
}

/** Auto-speak of the words (the card reads the word aloud by itself): kept on the device, on by default. */
const autoSpeak = ref(isAutoSpeakEnabled())
function onAutoSpeak(on: boolean): void {
  setAutoSpeakEnabled(on)
  autoSpeak.value = on
}

/** The short sounds of a lesson (right / wrong / done): kept on the device, on by default. */
const sounds = ref(isSoundEnabled())
function onSounds(on: boolean): void {
  setSoundEnabled(on)
  sounds.value = on
}

const localeOptions = computed<SegmentOption[]>(() =>
  (data.value?.options.locales ?? []).map((option) => ({ value: option.id, label: option.label, testid: `locale-${option.id}` })),
)

// "My centers": the membership whose "Leave" waits for the confirmation tap (null = none).
const confirmingId = ref<number | null>(null)

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
  await saveSetting(patch)
  syncDraft()
}

// "Delete account": the sheet with the question; its refusal is shown in the sheet, not as a toast under it.
const deleteOpen = ref(false)
const deleteError = ref('')

function askDelete(): void {
  deleteError.value = ''
  deleteOpen.value = true
}

/** Deletes the account and goes to the "Account deleted" screen, which closes the Mini App. A refusal stays in the sheet. */
async function deleteAccount(): Promise<void> {
  const result = await settings.deleteAccount()
  if ('error' in result) {
    deleteError.value = result.error
    return
  }

  deleteOpen.value = false
  await router.replace({ name: 'account-deleted', query: { until: result.until } })
}

/** The region (docs/35 §2.3): its name, the date from which another one can be chosen, the undo of the last change (10 minutes). */
const regionOpen = ref(false)
const regionName = computed((): string => {
  const code = data.value?.region_code ?? null
  if (code === null) return t('settings.region.none')
  return data.value?.options.regions?.find((region) => region.code === code)?.name ?? code
})
const regionNextChange = computed((): string => {
  const at = data.value?.region_change_available_at
  return at ? t('settings.region.next_change', { date: formatDate(at, 'dayMonthShortYear', locale.value) }) : ''
})
const regionUndoable = computed((): boolean => {
  const until = data.value?.region_undo_available_until
  return typeof until === 'string' && Date.parse(until) > Date.now()
})
async function undoRegion(): Promise<void> {
  await settings.revertRegion()
}

/** The emoji of Pro (docs/31 §5а): a locked picker opens the paywall and sends nothing; a 403 of a free-looking one does the same. */
const emojiOptions = computed(() => data.value?.options.pro_emojis ?? [])
const emojiLocked = computed(() => data.value?.options.pro_emoji_locked === true)
const emojiChoice = computed(() => data.value?.pro_emoji_choice ?? null)
const emojiPaywallOpen = ref(false)
const { reviewFromPaywall: onEmojiPaywallReview } = useReviewFromPaywall(() => {
  emojiPaywallOpen.value = false
}, { toastErrors: true })
async function onEmoji(code: string | null): Promise<void> {
  if (emojiLocked.value && code !== null) {
    emojiPaywallOpen.value = true
    return
  }
  if (code === emojiChoice.value) return
  await save({ pro_emoji: code })
  if (settings.proEmojiRequired) emojiPaywallOpen.value = true
}

const onLocale = (value: string | number) => save({ interface_locale: String(value) })
/** A locked goal opens the paywall and sends nothing; a 403 `daily_goal_pro_required` of a free-looking one does the same. */
async function onGoal(value: string | number): Promise<void> {
  const goal = Number(value)
  if (goalLocked(goal)) {
    syncDraft()
    goalPaywallOpen.value = true
    return
  }
  await save({ daily_goal: goal })
  if (settings.goalProRequired) goalPaywallOpen.value = true
}
const onReminder = () => save({ reminder_time: draft.reminder || null })
const onLeagues = (participates: boolean) => save({ leagues_opt_out: !participates })
const onRetention = (value: string | number) => save({ retention_mode: String(value) as LearnerSettings['retention_mode'] })
const onExercise = (key: keyof ExerciseSettings, enabled: boolean) => save({ exercises: { [key]: enabled } })

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="settings-view">
    <PageHeader :title="t('settings.title')" />

    <LoadingState v-if="status === 'idle' || status === 'loading'" variant="list" />

    <ErrorState v-else-if="!data" :message="message || t('error.generic')" @retry="load" />

    <div v-else class="space-y-4">
      <ListGroup :header="t('settings.group.region')">
        <div class="list-row flex-col items-stretch gap-2" data-testid="language-row">
          <span class="text-base leading-[22px] font-bold">{{ t('settings.language') }}</span>
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
      </ListGroup>

      <ListGroup :footer="regionNextChange || t('settings.region.hint')" data-testid="region-card">
        <button type="button" class="list-row" :disabled="saving" data-testid="region-row" @click="regionOpen = true">
          <span class="shrink-0 text-base leading-[22px] font-bold">{{ t('settings.region') }}</span>
          <span class="min-w-0 flex-1 truncate text-right text-[15px] leading-[22px] text-muted" data-testid="region-name">{{ regionName }}</span>
          <AppIcon name="chevron-right" :size="20" class="shrink-0 text-body" />
        </button>
        <button v-if="regionUndoable" type="button" class="list-row text-accent" :disabled="saving" data-testid="region-undo" @click="undoRegion">
          <span class="min-w-0 flex-1 text-left text-base leading-[22px] font-semibold">{{ t('settings.region.undo') }}</span>
        </button>
      </ListGroup>

      <!-- The emoji next to the name (docs/31 §5а): one of the platform's set; for Free a plus of Pro, the choice is kept. -->
      <ListGroup
        v-if="emojiOptions.length > 0"
        :header="t('settings.pro_emoji')"
        :footer="emojiLocked ? t('settings.pro_emoji.locked') : t('settings.pro_emoji.hint')"
        data-testid="pro-emoji-card"
      >
        <div class="list-row flex-wrap gap-1.5" role="radiogroup" :aria-label="t('settings.pro_emoji')" data-testid="pro-emoji-picker">
          <button
            type="button"
            class="chip"
            role="radio"
            :aria-checked="emojiChoice === null"
            :class="{ 'chip-accent': emojiChoice === null }"
            :disabled="saving"
            data-testid="pro-emoji-none"
            @click="onEmoji(null)"
          >
            {{ t('settings.pro_emoji.none') }}
          </button>
          <button
            v-for="option in emojiOptions"
            :key="option.code"
            type="button"
            class="flex size-11 items-center justify-center rounded-control text-[22px]"
            role="radio"
            :aria-checked="emojiChoice === option.code"
            :aria-label="option.emoji"
            :class="emojiChoice === option.code ? 'border-2 border-primary bg-accent-soft' : 'bg-fill'"
            :disabled="saving"
            :data-locked="emojiLocked ? 'true' : undefined"
            :data-testid="`pro-emoji-${option.code}`"
            @click="onEmoji(option.code)"
          >
            {{ option.emoji }}
          </button>
          <span v-if="emojiLocked" class="chip chip-accent shrink-0" data-testid="pro-emoji-pro">{{ t('profile.chip.pro') }}</span>
        </div>
      </ListGroup>

      <ListGroup :header="t('settings.group.learning')" :footer="t('settings.daily_goal.unit')">
        <div class="list-row flex-col items-stretch gap-2" data-testid="goal-row">
          <span class="text-base leading-[22px] font-bold">{{ t('settings.daily_goal') }}</span>
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
              <option v-for="option in goalOptions" :key="option.value" :value="option.value" :data-locked="option.locked ? 'true' : undefined">{{ goalSelectLabel(option) }}</option>
            </select>
          </div>
        </div>

        <!-- The FSRS retention dial (docs/06 §12.8): only for a learner scheduled by FSRS; SM-2 has no such setting. -->
        <div v-if="showsRetention" class="list-row flex-col items-stretch gap-2" data-testid="retention-row">
          <span class="text-base leading-[22px] font-bold">{{ t('settings.retention') }}</span>
          <SegmentedControl
            :model-value="data.retention_mode ?? 'normal'"
            :options="retentionOptions"
            :disabled="saving"
            :ariaLabel="t('settings.retention')"
            @update:model-value="onRetention"
          />
          <p class="text-[13px] leading-[18px] text-muted">{{ t('settings.retention.hint') }}</p>
        </div>

        <div class="list-row" data-testid="say-aloud-row">
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('settings.say_aloud') }}</span>
          <ToggleSwitch :model-value="sayAloud" :label="t('settings.say_aloud')" data-testid="say-aloud-toggle" @update:model-value="onSayAloud" />
        </div>

        <div class="list-row" data-testid="autospeak-row">
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('settings.autospeak') }}</span>
          <ToggleSwitch :model-value="autoSpeak" :label="t('settings.autospeak')" data-testid="autospeak-toggle" @update:model-value="onAutoSpeak" />
        </div>

        <div class="list-row" data-testid="sounds-row">
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('settings.sounds') }}</span>
          <ToggleSwitch :model-value="sounds" :label="t('settings.sounds')" data-testid="sounds-toggle" @update:model-value="onSounds" />
        </div>
      </ListGroup>

      <ListGroup>
        <!--
          A link row like `ListRow`, built by hand: the value of a `ListRow` never shrinks and its title wraps next to
          a long course name. Here the title keeps its line and a long name takes up to two lines.
        -->
        <RouterLink :to="{ name: 'courses' }" class="list-row" data-testid="course-card">
          <span class="shrink-0 text-base leading-[22px] font-bold">{{ t('settings.course') }}</span>
          <span class="line-clamp-2 min-w-0 flex-1 text-right text-[15px] leading-[22px] break-words text-muted" data-testid="course-title">
            {{ data.active_course ? data.active_course.title : t('settings.course.none') }}
          </span>
          <AppIcon name="chevron-right" :size="20" class="shrink-0 text-body" />
        </RouterLink>
      </ListGroup>

      <ListGroup :header="t('settings.exercises')" :footer="t('settings.exercises.always')" data-testid="exercises-card">
        <div v-for="key in EXERCISE_KEYS" :key="key" class="list-row" :data-testid="`exercise-row-${key}`">
          <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="flex min-w-0 items-center gap-2 text-base leading-[22px] font-bold">
              <span class="min-w-0">{{ t(`settings.exercises.${key}`) }}</span>
              <span v-if="PRO_EXERCISES.includes(key)" class="chip chip-accent shrink-0" data-testid="exercise-pro">{{ t('profile.chip.pro') }}</span>
            </span>
            <span v-if="EXERCISE_HINTS[key]" class="text-[13px] leading-[18px] text-muted" :data-testid="`exercise-hint-${key}`">
              {{ t(EXERCISE_HINTS[key]) }}
            </span>
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
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('settings.reminder.time') }}</span>
          <select v-model="draft.reminder" class="field field-inline shrink-0" :disabled="saving" data-testid="reminder-select" @change="onReminder">
            <option v-for="time in data.options.reminder_times" :key="time" :value="time">{{ time }}</option>
            <option value="">{{ t('settings.reminder.off') }}</option>
          </select>
        </label>
      </ListGroup>

      <ListGroup :header="t('settings.group.leagues')" :footer="t('settings.leagues.hint')">
        <div class="list-row">
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('settings.leagues.toggle') }}</span>
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
            <span class="min-w-0 flex-1 text-base leading-[22px] font-bold break-words" data-testid="org-name">{{ org.name }}</span>
            <span class="chip shrink-0" :class="org.seat_state === 'seated' ? 'chip-success' : 'chip-warning'" data-testid="org-state">
              {{ org.seat_state === 'seated' ? t('orgs.seated') : t('orgs.waitlisted') }}
            </span>
          </div>

          <!-- A queue says nothing about when it moves: tell what the chip means and what happens next. -->
          <p v-if="org.seat_state === 'waitlisted'" class="text-[13px] leading-[18px] text-muted" data-testid="org-waitlisted-hint">
            {{ t('orgs.waitlisted_hint') }}
          </p>

          <p v-if="!org.can_leave" class="text-[13px] leading-[18px] text-muted" data-testid="org-via-group">{{ t('orgs.via_group') }}</p>

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

      <!-- The danger zone is the last group: the question follows in a sheet, nothing is deleted by this tap. -->
      <ListGroup :header="t('settings.group.danger')" data-testid="danger-zone">
        <button type="button" class="list-row text-danger-ink" data-testid="delete-account" @click="askDelete">
          <AppIcon name="trash" :size="20" class="shrink-0" />
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('settings.delete') }}</span>
        </button>
      </ListGroup>
    </div>

    <PaywallSheet v-if="goalPaywallOpen" reason="daily_goal" @close="goalPaywallOpen = false" @review="onGoalPaywallReview" />
    <PaywallSheet v-if="emojiPaywallOpen" reason="info" @close="emojiPaywallOpen = false" @review="onEmojiPaywallReview" />
    <RegionSheet v-if="regionOpen" via="settings" @close="regionOpen = false" />

    <DeleteAccountSheet
      v-if="deleteOpen"
      :busy="deleting"
      :message="deleteError"
      @confirm="deleteAccount"
      @cancel="deleteOpen = false"
    />
  </div>
</template>
