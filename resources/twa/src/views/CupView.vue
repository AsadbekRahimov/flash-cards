<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import CupRules from '@/components/cup/CupRules.vue'
import CupStandings from '@/components/cup/CupStandings.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { BRAND } from '@/brand'
import { cupFailure, cupStatusLine, nextDayStart } from '@/composables/cupInfo'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useTelegram } from '@/composables/useTelegram'
import { api } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupsStore } from '@/stores/cups'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { CupDayResult } from '@/api/types'

/**
 * The cup (docs/33 §7.1, docs/04 A.23 `GET /cups/{id}`, read on every opening): the state ("Waiting for the start", "Day N of 7", over,
 * canceled, on pause), my place and points, my round of the day - "Play the round of the day" / "Continue" (until HH:MM) / "Played - the
 * next one tomorrow at HH:MM" (the times are the server's, shown in the learner's zone) - the table (`CupStandings`: shared places, "left",
 * the emoji of Pro, my row), my days 1…7 with the ones that count, the name in the tournament (changed at most 3 times), the rules.
 * `can_manage` (a hint, the server asks Telegram on the action) shows "Cancel the cup"; a refusal says that only an administrator of the
 * group can. A member of the chat who has not joined sees "Join". A refusal of the read is a state: `unavailable` (the same for a
 * stranger and a missing cup), `off`, the rest with "Retry".
 */
const props = defineProps<{ id: number }>()

const NAME_MAX = 32

const router = useRouter()
const auth = useAuthStore()
const boards = useCupBoardStore()
const cups = useCupsStore()
const today = useTodayStore()
const { haptic } = useTelegram()
const { t, tn, locale } = useI18n()
const { board, status, failure } = storeToRefs(boards)

useBackButton({ name: 'cups' })

let left = false
const authError = ref('')

async function load(): Promise<void> {
  authError.value = ''
  try {
    await auth.ensure()
  } catch {
    authError.value = t('error.generic')
    return
  }
  if (!left) await boards.load(props.id)
}

const data = computed(() => (board.value !== null && board.value.cup.id === props.id ? board.value : null))
const cup = computed(() => data.value?.cup ?? null)
const me = computed(() => data.value?.me ?? null)
const myRow = computed(() => data.value?.standings.find((row) => row.me) ?? null)

type ViewState = 'loading' | 'ready' | 'unavailable' | 'off' | 'error'

const view = computed((): ViewState => {
  if (authError.value !== '') return 'error'
  if (data.value !== null) return 'ready'
  if (status.value === 'failed' && failure.value !== null) {
    if (failure.value.kind === 'unavailable' || failure.value.kind === 'off') return failure.value.kind
    return failure.value.retry ? 'error' : 'unavailable'
  }
  return 'loading'
})

const errorText = computed((): string => authError.value || failure.value?.message || t('error.generic'))

/** The learner's zone when `/today` is known, else the device's. */
const zone = computed((): string | undefined => today.data?.timezone || undefined)
const timeOf = (iso: string): string => formatDate(iso, 'time', locale.value, zone.value)

const statusLine = computed((): string => (cup.value ? cupStatusLine(cup.value, locale.value) : ''))
const open = computed((): boolean => cup.value !== null && (cup.value.status === 'scheduled' || cup.value.status === 'running'))

/** My round of the day, as the screen offers it. */
const roundState = computed((): 'play' | 'continue' | 'done' | 'left' | 'none' => {
  const mine = me.value
  const c = cup.value
  if (mine === null || c === null) return 'none'
  if (mine.status === 'left') return 'left'
  if (c.status !== 'running' || c.paused !== null) return 'none'
  switch (mine.today.state) {
    case 'not_started':
      return 'play'
    case 'active':
      return 'continue'
    case 'done':
      return 'done'
    default:
      return 'none'
  }
})

const activeUntil = computed((): string => {
  const at = me.value?.today.expires_at
  return at ? t('cup.today.active_until', { time: timeOf(at) }) : ''
})

/** "Played: 14 points · the next round tomorrow at 00:00", or "that was the last day". */
const doneLine = computed((): string => {
  const c = cup.value
  if (c === null) return ''
  const points = tn('cup.points', me.value?.today.points ?? 0)
  const next = nextDayStart(c)
  return next === null ? t('cup.today.done_last', { points }) : t('cup.today.done', { points, time: timeOf(next) })
})

function play(): void {
  haptic('light')
  void router.push({ name: 'cup-round', params: { id: props.id } })
}

// ---- joining (a member of the chat who has not joined) ----

const joining = ref(false)
const joinError = ref('')

async function join(): Promise<void> {
  if (joining.value) return
  joining.value = true
  joinError.value = ''
  try {
    await api.cups.join(props.id)
    cups.invalidate()
    if (!left) await boards.load(props.id)
  } catch (err) {
    if (!left) joinError.value = cupFailure(err).message
  } finally {
    if (!left) joining.value = false
  }
}

// ---- my days ----

function dayLabel(day: CupDayResult): string {
  if (!day.available) return t('cup.days.unavailable', { day: day.day })
  if (!day.played) return t('cup.days.not_played', { day: day.day })
  const points = tn('cup.points', day.points)
  return day.counted ? t('cup.days.counted', { day: day.day, points }) : t('cup.days.played', { day: day.day, points })
}

// ---- the name in the tournament ----

const renameOpen = ref(false)
const newName = ref('')
const renaming = ref(false)
const renameError = ref('')

function openRename(): void {
  newName.value = me.value?.display_name ?? ''
  renameError.value = ''
  renameOpen.value = true
}

const canRename = computed((): boolean => {
  const value = newName.value.trim()
  return !renaming.value && value !== '' && Array.from(value).length <= NAME_MAX && value !== me.value?.display_name
})

async function rename(): Promise<void> {
  if (!canRename.value) return
  renaming.value = true
  renameError.value = ''
  try {
    await api.cups.rename(props.id, newName.value.trim())
    if (left) return
    renameOpen.value = false
    await boards.load(props.id)
  } catch (err) {
    if (!left) renameError.value = cupFailure(err).message
  } finally {
    if (!left) renaming.value = false
  }
}

// ---- cancel (an administrator of the chat) ----

const cancelOpen = ref(false)
const canceling = ref(false)
const cancelError = ref('')

function openCancel(): void {
  cancelError.value = ''
  cancelOpen.value = true
}

async function cancelCup(): Promise<void> {
  if (canceling.value) return
  canceling.value = true
  cancelError.value = ''
  try {
    await api.cups.cancel(props.id)
    if (left) return
    cancelOpen.value = false
    cups.invalidate()
    await boards.load(props.id)
  } catch (err) {
    if (!left) cancelError.value = cupFailure(err).message
  } finally {
    if (!left) canceling.value = false
  }
}

watch(() => props.id, () => void load())

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  left = true
})
</script>

<template>
  <div class="page" :data-state="view" data-testid="cup-view">
    <PageHeader :eyebrow="t('cup.brand_title', { brand: BRAND })" :title="cup ? cup.group_title : t('cup.title')" />

    <LoadingState v-if="view === 'loading'" variant="cards" />

    <EmptyState v-else-if="view === 'unavailable'" icon="lock-closed" :title="t('cup.invite.unavailable_title')" :text="errorText" data-testid="unavailable" />

    <EmptyState v-else-if="view === 'off'" icon="lock-closed" :title="t('cup.off_title')" :text="t('cup.failure.off')" data-testid="off" />

    <ErrorState v-else-if="view === 'error'" :message="errorText" @retry="load" />

    <div v-else-if="data && cup" class="space-y-3">
      <!-- The big card: the state, my place and points, my round of the day. -->
      <section class="flex flex-col gap-3 rounded-hero bg-highlight p-4" :data-status="cup.status" data-testid="cup-card">
        <div class="flex flex-col gap-1">
          <p class="text-sm leading-[18px] font-semibold" data-testid="cup-level">{{ t('cup.level', { level: cup.level }) }}</p>
          <h2 class="text-[22px] leading-7 font-extrabold break-words" data-testid="cup-status">{{ statusLine }}</h2>
        </div>

        <div v-if="me && myRow" class="flex items-center gap-3" data-testid="my-result">
          <span class="flex h-[38px] shrink-0 items-center rounded-control bg-primary px-3.5 text-[15px] leading-5 font-bold text-on-primary tabular-nums" data-testid="my-points">
            {{ tn('cup.points', me.total) }}
          </span>
          <span class="min-w-0 text-[15px] leading-5" data-testid="my-place">
            {{ me.final_rank !== null ? t('cup.final_place', { rank: me.final_rank }) : t('cup.my_place', { rank: myRow.rank }) }}
          </span>
        </div>

        <template v-if="roundState === 'play'">
          <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="play-btn" @click="play">{{ t('cup.today.play') }}</button>
        </template>
        <template v-else-if="roundState === 'continue'">
          <p v-if="activeUntil" class="text-[15px] leading-5" data-testid="active-until">{{ activeUntil }}</p>
          <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="play">{{ t('cup.today.continue') }}</button>
        </template>
        <p v-else-if="roundState === 'done'" class="text-[15px] leading-5" role="status" data-testid="round-done">{{ doneLine }}</p>
        <p v-else-if="roundState === 'left'" class="text-[15px] leading-5 text-muted" data-testid="me-left">{{ t('cup.me.left') }}</p>

        <template v-if="me === null && open">
          <p class="text-[15px] leading-5" data-testid="not-joined">{{ t('cup.me.not_joined') }}</p>
          <InlineAlert v-if="joinError" tone="danger" :message="joinError" data-testid="join-error" />
          <button
            type="button"
            class="btn btn-primary btn-lg btn-block"
            :disabled="joining"
            :aria-busy="joining ? 'true' : undefined"
            data-testid="join-btn"
            @click="join"
          >
            {{ t('cup.invite.join') }}
          </button>
        </template>
      </section>

      <CupStandings :title="t('cup.table.title')" :rows="data.standings" />

      <!-- My days 1…7: the points of each, the ones that count in the total marked. -->
      <section v-if="me" class="card flex flex-col gap-2.5" data-testid="my-days">
        <h2 class="text-base leading-[22px] font-bold">{{ t('cup.days.title') }}</h2>
        <ol class="grid grid-cols-7 gap-1" :aria-label="t('cup.days.title')">
          <li
            v-for="day in me.days"
            :key="day.day"
            class="flex min-w-0 flex-col items-center rounded-control py-1.5"
            :class="day.counted ? 'bg-accent-soft shadow-[inset_0_0_0_1.5px_var(--app-primary)]' : 'bg-fill'"
            :aria-label="dayLabel(day)"
            :data-counted="day.counted ? 'true' : undefined"
            :data-played="day.played ? 'true' : undefined"
            data-testid="day"
          >
            <span class="text-[11px] leading-[14px] text-muted" aria-hidden="true">{{ day.day }}</span>
            <span class="text-[15px] leading-5 font-bold tabular-nums" :class="day.available && day.played ? '' : 'text-muted'" aria-hidden="true">
              {{ day.available && day.played ? day.points : '–' }}
            </span>
          </li>
        </ol>
        <p class="text-[13px] leading-[18px] text-muted" data-testid="days-hint">{{ tn('cup.days.hint', cup.counted_days) }}</p>
      </section>

      <!-- The name in the tournament: the chat sees it; at most 3 changes in a cup. -->
      <section v-if="me" class="list" data-testid="my-name">
        <div class="list-row min-h-11">
          <span class="flex min-w-0 flex-1 flex-col">
            <span class="text-[13px] leading-[18px] text-muted">{{ t('cup.invite.name_label') }}</span>
            <span class="truncate text-base leading-[22px] font-bold" data-testid="my-name-value">{{ me.display_name }}</span>
          </span>
          <button
            v-if="me.renames_left > 0 && open"
            type="button"
            class="btn btn-plain btn-sm shrink-0"
            data-testid="rename-btn"
            @click="openRename"
          >
            {{ t('cup.name.change') }}
          </button>
        </div>
      </section>

      <details class="list group" data-testid="rules">
        <summary class="list-row min-h-11 cursor-pointer list-none select-none active:bg-fill [&::-webkit-details-marker]:hidden">
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('cup.rules.title') }}</span>
        </summary>
        <div class="px-[18px] pt-1 pb-4">
          <CupRules :cup="cup" :rules="cup.rules" />
        </div>
      </details>

      <button v-if="data.can_manage && open" type="button" class="btn btn-plain btn-block text-danger-ink" data-testid="cancel-btn" @click="openCancel">
        {{ t('cup.cancel.open') }}
      </button>

      <RouterLink :to="{ name: 'cups' }" class="btn btn-plain btn-block" data-testid="my-cups-link">{{ t('cup.list.title') }}</RouterLink>
    </div>

    <BottomSheet v-if="renameOpen" :label="t('cup.name.title')" @close="renameOpen = false">
      <div class="flex flex-col gap-3 px-4 pt-2 pb-4" data-testid="rename-sheet">
        <h2 class="text-[22px] leading-7 font-bold">{{ t('cup.name.title') }}</h2>
        <input
          v-model="newName"
          type="text"
          class="field"
          :maxlength="NAME_MAX"
          :aria-label="t('cup.invite.name_label')"
          :disabled="renaming"
          data-testid="rename-input"
        />
        <p class="text-[13px] leading-[18px] text-muted" data-testid="rename-hint">{{ tn('cup.name.left', me?.renames_left ?? 0) }}</p>
        <InlineAlert v-if="renameError" tone="danger" :message="renameError" data-testid="rename-error" />
        <button
          type="button"
          class="btn btn-primary btn-block"
          :disabled="!canRename"
          :aria-busy="renaming ? 'true' : undefined"
          data-testid="rename-save"
          @click="rename"
        >
          {{ t('cup.name.save') }}
        </button>
      </div>
    </BottomSheet>

    <BottomSheet v-if="cancelOpen" :label="t('cup.cancel.title')" @close="cancelOpen = false">
      <div class="flex flex-col gap-3 px-4 pt-2 pb-4" data-testid="cancel-sheet">
        <h2 class="text-[22px] leading-7 font-bold">{{ t('cup.cancel.title') }}</h2>
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
