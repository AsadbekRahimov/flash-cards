<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import ActiveSessionSheet from '@/components/practice/ActiveSessionSheet.vue'
import FriendPickerSheet from '@/components/practice/FriendPickerSheet.vue'
import ModeCard from '@/components/practice/ModeCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { progressPercent } from '@/composables/courseLabels'
import { showErrorToast } from '@/composables/errorToast'
import { MODE_LOOK, lessonHeader, modeTitle } from '@/composables/practiceLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useBattleStore } from '@/stores/battle'
import { useFriendsStore } from '@/stores/friends'
import { usePracticeStore } from '@/stores/practice'
import { useTrainingStore } from '@/stores/training'
import { useI18n } from '@/i18n'
import type { BattleIncoming, FriendItem, PracticeActiveSession, PracticeMode, PracticeModeInfo } from '@/api/types'

/**
 * The practice hub of a course or of one lesson (docs/16 §11.2, docs/14): the heading with the course and the lesson,
 * the "Continue" card of the unfinished session, the four cards of the modes, and the battle with a friend. There is
 * no primary button here: pressing a card is the main action.
 *
 * States: loading, error (Retry), "practice is not available yet" (`practice_unavailable`), the hub. A card is playable
 * (the whole card is the button), short of material ("Learn the lesson"), switched off, or behind Pro (the paywall).
 * The one unfinished session of a learner: a mode pressed while there is one asks what to do (`ActiveSessionSheet`).
 * "Back" leads to the course.
 */
const props = defineProps<{
  slug: string
  /** The lesson of the hub (`?lesson=`); null: the whole course. */
  lessonId: number | null
}>()

const router = useRouter()
const auth = useAuthStore()
const practice = usePracticeStore()
const battle = useBattleStore()
const friends = useFriendsStore()
const training = useTrainingStore()
const { haptic } = useTelegram()
const { t } = useI18n()

const { hub, hubStatus, hubError, opening, openError, conflict, paywall } = storeToRefs(practice)

useBackButton({ name: 'course', params: { slug: props.slug } })

/** Set when the learner leaves: an answer that comes back after it must not carry them anywhere. */
let left = false

/** The mode the learner pressed, for "Start a new one" after the unfinished session has been offered. */
const pressed = ref<PracticeMode | null>(null)
/** The paywall opened from a card that is behind Pro (the server's 403 sets `practice.paywall` for the same sheet). */
const proSheet = ref(false)
const pickerOpen = ref(false)

const showPaywall = computed(() => proSheet.value || paywall.value)

function closePaywall(): void {
  proSheet.value = false
  practice.dismissPaywall()
}

const { reviewFromPaywall } = useReviewFromPaywall(closePaywall, { toastErrors: true })

/** The heading names the place: the course, or the lesson as everywhere ("Module 2 · Lesson 4 · Food"); until the hub is there, the screen. */
const title = computed((): string => {
  const current = hub.value
  if (current === null) return t('practice.hub.title')
  return current.lesson ? lessonHeader(current.lesson) : current.course.title
})

/** Under it, what the screen is, and the course of a lesson. */
const subtitle = computed((): string => {
  const current = hub.value
  if (current === null) return ''
  return current.lesson ? `${t('practice.hub.title')} · ${current.course.title}` : t('practice.hub.title')
})

/** The unfinished session of the mode, when it is this very course / lesson: the card leads straight back into it. */
function resumeFor(info: PracticeModeInfo): PracticeActiveSession | null {
  const current = hub.value
  const active = current?.active_session ?? null
  if (current === null || active === null || active.mode !== info.mode) return null
  return active.course === current.course.slug && active.lesson_id === (current.lesson?.id ?? null) ? active : null
}

const learnKind = computed(() => (hub.value?.lesson ? 'lesson' : 'course'))

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    practice.failHub(err)
    return
  }
  await practice.loadHub(props.slug, props.lessonId)
  // The invitations and the battle that is on live in the list; it is asked for only when the hub says there is any.
  const state = hub.value?.battle
  if (!left && state?.enabled && (state.incoming_count > 0 || state.active_battle_id !== null)) void battle.loadList()
}

function openSession(id: number): Promise<unknown> {
  return router.push({ name: 'practice-session', params: { id } })
}

async function start(mode: PracticeMode, replaceActive: boolean): Promise<void> {
  pressed.value = mode
  const id = await practice.open({ mode, course: props.slug, lesson_id: props.lessonId, source: 'hub', replace_active: replaceActive })
  if (id !== null && !left) await openSession(id)
}

function select(mode: PracticeMode): void {
  const info = hub.value?.modes.find((card) => card.mode === mode)
  if (!info) return
  haptic('light')
  if (info.state === 'pro_required') {
    proSheet.value = true
    return
  }
  const resume = resumeFor(info)
  if (resume) void openSession(resume.id)
  else void start(mode, false)
}

/** "Continue" of the unfinished-session sheet. */
function continueActive(): void {
  const active = conflict.value
  practice.clearConflict()
  if (active !== null) void openSession(active.id)
}

/** "Start a new one": the old session is closed by the server (`replace_active`). */
function startNew(): void {
  const mode = pressed.value
  practice.clearConflict()
  if (mode !== null) void start(mode, true)
}

async function learn(): Promise<void> {
  const lesson = hub.value?.lesson
  if (!lesson) {
    await router.push({ name: 'course', params: { slug: props.slug } })
    return
  }
  const sessionId = await training.open({ mode: 'lesson', lesson_id: lesson.id, source: 'picker' })
  if (left) return
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
  else if (training.openError !== '') {
    showErrorToast(training.openError)
    training.clearOpenError()
  }
}

// ---- the battle with a friend ----

const battleOn = computed(() => hub.value?.battle.enabled === true)
const activeBattleId = computed(() => hub.value?.battle.active_battle_id ?? null)

function playWithFriend(): void {
  haptic('light')
  if (activeBattleId.value !== null) {
    void router.push({ name: 'battle', params: { id: activeBattleId.value } })
    return
  }
  pickerOpen.value = true
  void friends.load()
}

const pickerStatus = computed((): 'loading' | 'ready' | 'error' => {
  if (friends.friends !== null) return 'ready'
  return friends.status === 'error' ? 'error' : 'loading'
})

function pickFriend(friend: FriendItem): void {
  pickerOpen.value = false
  void router.push({
    name: 'battle-new',
    query: { friendship: String(friend.id), course: props.slug, ...(props.lessonId !== null ? { lesson: String(props.lessonId) } : {}) },
  })
}

const invitations = computed(() => (battleOn.value ? battle.incoming : []))

function invitationMeta(invitation: BattleIncoming): string {
  const topic = invitation.lesson ? lessonHeader(invitation.lesson) : invitation.course.title
  return `${modeTitle(invitation.mode)} · ${topic}`
}

// A refused open is told in a toast: the cards are all over a long screen.
watch(openError, (message) => {
  if (message === '') return
  showErrorToast(message)
  practice.clearOpenError()
})

watch([() => props.slug, () => props.lessonId], () => void load())

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  left = true
  practice.clearOpenError()
  practice.clearConflict()
})
</script>

<template>
  <div class="page" data-testid="practice-hub-view">
    <PageHeader :title="title" :subtitle="subtitle" />

    <LoadingState v-if="hubStatus === 'idle' || hubStatus === 'loading'" variant="cards" />

    <EmptyState
      v-else-if="hubStatus === 'unavailable'"
      icon="lock-closed"
      :title="t('practice.hub.unavailable_title')"
      :text="t('practice.hub.unavailable_text')"
      data-testid="unavailable"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-course" @click="router.push({ name: 'course', params: { slug } })">
          {{ t('practice.hub.to_course') }}
        </button>
      </template>
    </EmptyState>

    <ErrorState v-else-if="hubStatus === 'error' || hub === null" :message="hubError" @retry="load" />

    <template v-else>
      <ListGroup v-if="invitations.length > 0" :header="t('practice.hub.battle.invites')" class="mb-4" data-testid="battle-invites">
        <ListRow
          v-for="invitation in invitations"
          :key="invitation.id"
          :to="{ name: 'battle', params: { id: invitation.id } }"
          icon="bolt"
          tile="orange"
          :title="t('practice.hub.battle.invited_by', { name: invitation.from.first_name || t('friends.list.anonymous') })"
          :subtitle="invitationMeta(invitation)"
          :data-testid="`invitation-${invitation.id}`"
        />
      </ListGroup>

      <!-- The unfinished session: one tap back into it. -->
      <RouterLink
        v-if="hub.active_session"
        :to="{ name: 'practice-session', params: { id: hub.active_session.id } }"
        class="card mb-4 flex items-center gap-3 transition-transform active:scale-[0.99]"
        :aria-label="
          t('practice.hub.card.aria_resume', {
            title: modeTitle(hub.active_session.mode),
            done: hub.active_session.progress.done,
            total: hub.active_session.progress.total,
          })
        "
        data-testid="continue-card"
      >
        <span class="icon-tile size-10 rounded-xl" :class="`tile-${MODE_LOOK[hub.active_session.mode].tile}`">
          <AppIcon :name="MODE_LOOK[hub.active_session.mode].icon" :size="22" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-[13px] leading-[18px] font-semibold tracking-wide text-accent-ink uppercase">{{ t('practice.hub.continue') }}</span>
          <span class="block text-[17px] leading-[22px] font-semibold" data-testid="continue-title">{{ modeTitle(hub.active_session.mode) }}</span>
          <span class="block text-[13px] leading-[18px] text-tg-hint" data-testid="continue-progress">
            {{ t('practice.session.progress', { done: hub.active_session.progress.done, total: hub.active_session.progress.total }) }}
          </span>
          <span class="progress mt-1.5 block h-1" aria-hidden="true">
            <span class="progress-fill block" :style="{ width: `${progressPercent(hub.active_session.progress)}%` }" />
          </span>
        </span>
        <AppIcon name="chevron-right" :size="16" class="text-tg-hint" />
      </RouterLink>

      <h2 class="section-label mt-0">{{ t('practice.hub.choose') }}</h2>
      <div class="flex flex-col gap-3" data-testid="mode-cards">
        <ModeCard
          v-for="info in hub.modes"
          :key="info.mode"
          :info="info"
          :resume="resumeFor(info)"
          :busy="opening === info.mode"
          :learn="learnKind"
          @select="select"
          @learn="learn"
        />
      </div>

      <section v-if="battleOn" class="card mt-4" data-testid="battle-card">
        <div class="flex items-start gap-3">
          <span class="icon-tile size-10 rounded-xl tile-pink"><AppIcon name="users" :size="22" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('practice.hub.battle.title') }}</h2>
            <p class="mt-0.5 text-[15px] leading-5 text-tg-subtitle">{{ t('practice.hub.battle.text') }}</p>
          </div>
        </div>
        <button type="button" class="btn btn-tinted btn-block mt-3" data-testid="battle-play-btn" @click="playWithFriend">
          {{ activeBattleId !== null ? t('practice.hub.battle.resume') : t('practice.hub.battle.play') }}
        </button>
      </section>
    </template>

    <ActiveSessionSheet v-if="conflict" :session="conflict" :busy="opening !== null" @continue="continueActive" @replace="startNew" @close="practice.clearConflict()" />

    <FriendPickerSheet
      v-if="pickerOpen"
      :friends="friends.friends?.friends ?? []"
      :status="pickerStatus"
      :message="friends.errorMessage"
      @pick="pickFriend"
      @invite="router.push({ name: 'friends' })"
      @retry="friends.load()"
      @close="pickerOpen = false"
    />

    <PaywallSheet v-if="showPaywall" reason="practice" @close="closePaywall" @review="reviewFromPaywall" />
  </div>
</template>
