<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import InitialAvatar from '@/components/InitialAvatar.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import BattleFormatCard from '@/components/battle/BattleFormatCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { lessonHeader } from '@/composables/practiceLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useBattleSetupStore } from '@/stores/battleSetup'
import { useCoursesStore } from '@/stores/courses'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { PracticeMode } from '@/api/types'

/**
 * Setup of a battle (docs/16 §11.2): who (the friend of the address, `?friendship=`), where (the course and the lesson;
 * `?course=` and `?lesson=` preselect them), what (one of the four formats), then "Invite". The one primary button
 * of the screen is "Invite".
 *
 * - The courses are the catalog without the decks and without courses that have no words; the main course is chosen if
 *   the address names none. The lessons are the lessons of the chosen course ("Module 2 · Lesson 4 · Food"), or the
 *   whole course.
 * - The formats come from `GET /practice/battles/options` for this friend and this material: each is playable, or
 *   short of material with the reason in words, or switched off. A friend without access to practice is explained
 *   and the button stays off.
 * - A refusal is told in words: a battle of the learner's own in the way (with a link to it), the friend no longer a
 *   friend, battles switched off, the material gone since the numbers were read.
 * "Back" leads to the Friends.
 */
const props = defineProps<{
  friendshipId: number | null
  course: string | null
  lessonId: number | null
}>()

const router = useRouter()
const auth = useAuthStore()
const setup = useBattleSetupStore()
const catalog = useCoursesStore()
const { haptic } = useTelegram()
const { t } = useI18n()

const { options, optionsStatus, optionsError, blocked, inviting, inviteError, busyBattleId, paywall, mode, course, lessonId } = storeToRefs(setup)
const { listStatus, detailStatus } = storeToRefs(catalog)

useBackButton({ name: 'friends' })

/** Set when the learner leaves: an answer that comes back after it must not carry them anywhere. */
let left = false
const loginError = ref('')

async function load(): Promise<void> {
  loginError.value = ''
  try {
    await auth.ensure()
  } catch (err) {
    loginError.value = errorMessage(err, 'battle')
    return
  }
  await setup.begin(props.friendshipId, props.course, props.lessonId)
}

onMounted(() => {
  void load()
})

watch([() => props.friendshipId, () => props.course, () => props.lessonId], () => void load())

onBeforeUnmount(() => {
  left = true
})

const { reviewFromPaywall } = useReviewFromPaywall(() => setup.dismissPaywall(), { toastErrors: true })

// ---------------------------------------------------------------- the view

const friendName = computed(() => setup.friendName)
const title = computed(() => (friendName.value ? t('battle.setup.title_with', { name: friendName.value }) : t('battle.setup.title')))
const shownName = computed(() => friendName.value ?? t('friends.list.anonymous'))

const catalogLoading = computed(() => props.friendshipId !== null && (listStatus.value === 'idle' || listStatus.value === 'loading'))
const catalogFailed = computed(() => listStatus.value === 'error' || loginError.value !== '')
const catalogMessage = computed(() => loginError.value || catalog.errorMessage)

const noAccess = computed(() => options.value?.friend_access === 'no_access')
const lessonsLoading = computed(() => detailStatus.value === 'loading' || (detailStatus.value === 'idle' && course.value !== null))
const lessonsFailed = computed(() => detailStatus.value === 'error')

const lessonValue = computed(() => (lessonId.value === null ? '' : String(lessonId.value)))

function onLesson(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  void setup.selectLesson(value === '' ? null : Number(value))
}

function pickCourse(slug: string): void {
  haptic('light')
  void setup.selectCourse(slug)
}

function pickMode(next: PracticeMode): void {
  haptic('light')
  setup.selectMode(next)
}

async function invite(): Promise<void> {
  haptic('medium')
  const id = await setup.invite()
  if (id !== null && !left) await router.replace({ name: 'battle', params: { id } })
}

function openBusy(): void {
  const id = busyBattleId.value
  if (id !== null) void router.push({ name: 'battle', params: { id } })
}

function toFriends(): void {
  void router.replace({ name: 'friends' })
}

const blockedKey = computed(() => {
  if (blocked.value === 'friend') return 'friend'
  return blocked.value === 'off' ? 'off' : 'unavailable'
})
</script>

<template>
  <div class="page" data-testid="battle-setup-view">
    <PageHeader :title="title" :subtitle="t('battle.setup.subtitle')" />

    <!-- No friend in the address: a battle is played by two. -->
    <EmptyState
      v-if="friendshipId === null"
      icon="users"
      :title="t('battle.setup.no_friend_title')"
      :text="t('battle.setup.no_friend_text')"
      data-testid="no-friend"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-friends" @click="toFriends">{{ t('battle.to_friends') }}</button>
      </template>
    </EmptyState>

    <LoadingState v-else-if="catalogLoading" variant="list" />

    <ErrorState v-else-if="catalogFailed" :message="catalogMessage" @retry="load" />

    <EmptyState
      v-else-if="blocked"
      :icon="blocked === 'friend' ? 'users' : 'lock-closed'"
      :title="t(`battle.setup.blocked.${blockedKey}.title`)"
      :text="t(`battle.setup.blocked.${blockedKey}.text`)"
      data-testid="blocked"
      :data-reason="blocked"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-friends" @click="toFriends">{{ t('battle.to_friends') }}</button>
      </template>
    </EmptyState>

    <EmptyState v-else-if="setup.courses.length === 0" icon="academic-cap" :text="t('battle.setup.no_courses')" data-testid="no-courses" />

    <div v-else class="space-y-5">
      <section class="card flex items-center gap-3" data-testid="friend-card">
        <InitialAvatar :name="shownName" />
        <span class="min-w-0 flex-1">
          <span class="block truncate text-base leading-[22px] font-medium" data-testid="friend-name">{{ shownName }}</span>
          <span class="block text-[13px] leading-[18px] text-tg-hint">{{ t('battle.setup.friend') }}</span>
        </span>
        <AppIcon v-if="options && !noAccess" name="check-circle" :size="22" class="text-success" />
      </section>
      <InlineAlert v-if="noAccess" tone="warning" :message="t('battle.setup.friend_no_access', { name: shownName })" data-testid="friend-no-access" />

      <section data-testid="course-section">
        <h2 class="section-label mt-0">{{ t('battle.setup.course') }}</h2>
        <div class="list" role="radiogroup" :aria-label="t('battle.setup.course')">
          <button
            v-for="item in setup.courses"
            :key="item.slug"
            type="button"
            role="radio"
            class="list-row"
            :aria-checked="item.slug === course"
            :data-testid="`course-${item.slug}`"
            :data-selected="item.slug === course"
            @click="pickCourse(item.slug)"
          >
            <span class="min-w-0 flex-1">
              <span class="block text-base leading-[22px]">{{ item.title }}</span>
              <span v-if="item.level" class="block text-[13px] leading-[18px] text-tg-hint">{{ item.level }}</span>
            </span>
            <AppIcon v-if="item.slug === course" name="check" :size="20" class="text-accent" />
          </button>
        </div>
      </section>

      <section data-testid="lesson-section">
        <h2 class="section-label mt-0">{{ t('battle.setup.lesson') }}</h2>
        <div class="card">
          <select
            class="field"
            :value="lessonValue"
            :disabled="lessonsLoading || lessonsFailed"
            :aria-label="t('battle.setup.lesson')"
            data-testid="lesson-select"
            @change="onLesson"
          >
            <option value="">{{ t('battle.setup.lesson_all') }}</option>
            <option v-for="lesson in setup.lessons" :key="lesson.id" :value="String(lesson.id)">{{ lessonHeader(lesson) }}</option>
          </select>
          <div v-if="lessonsFailed" class="mt-3 space-y-2" data-testid="lessons-error">
            <InlineAlert tone="danger" :message="t('battle.setup.lessons_error')" />
            <button type="button" class="btn btn-tinted btn-sm" data-testid="lessons-retry" @click="setup.reloadLessons()">{{ t('common.retry') }}</button>
          </div>
        </div>
      </section>

      <section data-testid="format-section">
        <h2 class="section-label mt-0">{{ t('battle.setup.format') }}</h2>

        <LoadingState v-if="optionsStatus === 'loading' || optionsStatus === 'idle'" variant="cards" />

        <div v-else-if="optionsStatus === 'error'" class="space-y-2" data-testid="options-error">
          <InlineAlert tone="danger" :message="optionsError" />
          <button type="button" class="btn btn-tinted btn-sm" data-testid="options-retry" @click="setup.loadOptions()">{{ t('common.retry') }}</button>
        </div>

        <div v-else-if="options" class="flex flex-col gap-3" role="radiogroup" :aria-label="t('battle.setup.format')" data-testid="formats">
          <BattleFormatCard v-for="option in options.modes" :key="option.mode" :option="option" :selected="option.mode === mode" @select="pickMode" />
        </div>
      </section>

      <section class="space-y-3" data-testid="invite-section">
        <InlineAlert v-if="inviteError" tone="danger" :message="inviteError" data-testid="invite-error" />
        <button v-if="busyBattleId !== null" type="button" class="btn btn-tinted btn-block" data-testid="open-busy" @click="openBusy">
          {{ t('battle.open_current') }}
        </button>
        <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="!setup.canInvite" data-testid="invite-btn" @click="invite">
          {{ inviting ? t('battle.setup.inviting') : t('battle.setup.invite') }}
        </button>
        <p class="px-4 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="invite-hint">{{ t('battle.setup.invite_hint') }}</p>
      </section>
    </div>

    <PaywallSheet v-if="paywall" reason="practice" @close="setup.dismissPaywall()" @review="reviewFromPaywall" />
  </div>
</template>
