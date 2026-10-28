<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import InitialAvatar from '@/components/InitialAvatar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { showErrorToast } from '@/composables/errorToast'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useFriendsStore } from '@/stores/friends'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { ChallengeItem, ChallengeMetric, ChallengeStatus, FriendItem, FriendRequestItem } from '@/api/types'

/**
 * "Rating" tab, the Friends pane (docs/13 §7.5, docs/14 §6.7, docs/04 A.13): the invite link (`f_<code>`) with
 * Telegram's share dialog, incoming and outgoing requests, the friends with their streak and learned words,
 * challenges ("who repeats more") and their state. A tab root (the other pane is League): no system "Back" button.
 * A refused action (accept, decline, remove, challenge) is a toast: the buttons are all over a long screen.
 */
const auth = useAuthStore()
const store = useFriendsStore()
const { shareUrl, haptic } = useTelegram()
const { copy } = useCopyToClipboard()
const { t, tn, locale } = useI18n()

useBackButton(null)

const failed = ref('')
/** Friendship id whose challenge picker / removal confirmation is open. */
const picking = ref<number | null>(null)
const removing = ref<number | null>(null)

const friends = computed(() => store.friends)
const challenges = computed(() => store.challenges)
const message = computed(() => failed.value || store.errorMessage)
const openChallenges = computed(() => challenges.value?.open ?? [])
const finishedChallenges = computed(() => challenges.value?.finished ?? [])

// The buttons of the lists are all over a long screen: a refused action is told in a toast, where the learner looks.
watch(
  () => store.actionError,
  (text) => {
    if (text === '') return
    showErrorToast(text)
    store.clearActionError()
  },
)

const ratingTabs = computed(() => [
  { to: { name: 'league' }, label: t('league.title'), testid: 'seg-league' },
  { to: { name: 'friends' }, label: t('friends.title'), testid: 'seg-friends' },
])

const METRICS: ChallengeMetric[] = ['reviews', 'new_words']

const METRIC_LABELS: Record<ChallengeMetric, StringKey> = {
  reviews: 'friends.challenge.metric.reviews',
  new_words: 'friends.challenge.metric.new_words',
}

interface ChipView {
  label: StringKey
  chip: string
}

/** The status chip of an open challenge: a running one is accent, one that waits for my answer draws the eye. */
const STATUS_CHIPS: Record<Exclude<ChallengeStatus, 'pending'>, ChipView> = {
  active: { label: 'friends.challenge.status.active', chip: 'chip-accent' },
  finished: { label: 'friends.challenge.status.finished', chip: 'chip-neutral' },
  declined: { label: 'friends.challenge.status.declined', chip: 'chip-neutral' },
  expired: { label: 'friends.challenge.status.expired', chip: 'chip-neutral' },
  canceled: { label: 'friends.challenge.status.canceled', chip: 'chip-neutral' },
}

const RESULT_CHIPS: Record<NonNullable<ChallengeItem['result']>, ChipView> = {
  won: { label: 'friends.challenge.result.won', chip: 'chip-success' },
  lost: { label: 'friends.challenge.result.lost', chip: 'chip-danger' },
  draw: { label: 'friends.challenge.result.draw', chip: 'chip-neutral' },
}

/** Friendships that already have an open challenge: a second one would be refused. */
const withOpenChallenge = computed(
  () => new Set(openChallenges.value.map((c) => c.friendship_id).filter((id): id is number => id !== null)),
)

const personName = (person: FriendItem | FriendRequestItem): string => person.first_name || t('friends.list.anonymous')

const friendSubtitle = (friend: FriendItem): string =>
  t('friends.list.subtitle', { streak: friend.streak, words: tn('friends.list.words', friend.words_learned) })

const metricLabel = (metric: ChallengeMetric): string => t(METRIC_LABELS[metric])

function statusChip(c: ChallengeItem): ChipView {
  if (c.status === 'pending') {
    return c.role === 'challenger'
      ? { label: 'friends.challenge.status.pending_sent', chip: 'chip-neutral' }
      : { label: 'friends.challenge.status.pending_received', chip: 'chip-warning' }
  }
  return STATUS_CHIPS[c.status]
}

/** "26 Oct — 1 Nov" of a running challenge (a pending one has no dates yet). */
function period(c: ChallengeItem): string {
  if (!c.starts_on || !c.ends_on) return ''
  return t('friends.challenge.period', {
    from: formatDate(c.starts_on, 'dayMonthShort', locale.value),
    to: formatDate(c.ends_on, 'dayMonthShort', locale.value),
  })
}

const score = (c: ChallengeItem): string => t('friends.challenge.score', { mine: c.my_score ?? 0, theirs: c.their_score ?? 0 })

/** My share of the points of both, in percent; a level start (0 : 0) is half. Null while there is no score. */
function myShare(c: ChallengeItem): number | null {
  if (c.my_score === null) return null
  const total = c.my_score + (c.their_score ?? 0)
  return total <= 0 ? 50 : Math.round((c.my_score / total) * 100)
}

function share(): void {
  if (!friends.value?.invite_link) return
  haptic('light')
  shareUrl(friends.value.invite_link, t('friends.invite.share_text'))
}

async function copyLink(): Promise<void> {
  if (!friends.value?.invite_link) return
  await copy(friends.value.invite_link)
}

async function answerFriend(kind: 'accept' | 'decline' | 'remove', id: number): Promise<void> {
  haptic('light')
  if (await store.friendAction(kind, id)) removing.value = null
}

async function propose(friendshipId: number, metric: ChallengeMetric): Promise<void> {
  haptic('light')
  if (await store.propose(friendshipId, metric)) picking.value = null
}

async function load(): Promise<void> {
  failed.value = ''
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  await store.load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="friends-view">
    <PageHeader :title="t('league.rating_title')" large />
    <SegmentedNav :items="ratingTabs" class="mb-4" />

    <LoadingState v-if="!friends && !message" />

    <ErrorState v-else-if="!friends" :message="message" @retry="load" />

    <div v-else class="space-y-3">
      <section class="card" data-testid="invite">
        <div class="flex items-start gap-3">
          <span class="icon-tile tile-blue size-11 rounded-xl"><AppIcon name="user-plus" :size="22" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('friends.invite.title') }}</h2>
            <p class="mt-0.5 text-[15px] leading-5 text-tg-subtitle">{{ t('friends.invite.hint') }}</p>
          </div>
        </div>

        <template v-if="friends.invite_link">
          <!-- Read-only field: the link wraps by characters and is selected with one tap, so it can be copied by hand too. -->
          <div class="field mt-4 font-mono text-[13px] leading-[18px] break-all select-all" data-testid="link">{{ friends.invite_link }}</div>
          <div class="mt-3 space-y-2">
            <button type="button" class="btn btn-primary btn-block" data-testid="share-btn" @click="share">
              {{ t('friends.invite.share') }}
            </button>
            <button type="button" class="btn btn-tinted btn-block" data-testid="copy-btn" @click="copyLink">
              {{ t('friends.copy') }}
            </button>
          </div>
        </template>
        <p v-else class="mt-3 text-[15px] leading-5 text-tg-subtitle" data-testid="no-link">{{ t('friends.invite.no_link') }}</p>
      </section>

      <ListGroup v-if="friends.incoming.length > 0" :header="t('friends.incoming.title')" data-testid="incoming">
        <div v-for="request in friends.incoming" :key="request.id" class="list-row flex-wrap gap-y-3" data-testid="incoming-row">
          <div class="flex min-w-0 basis-full items-center gap-3">
            <InitialAvatar :name="personName(request)" />
            <span class="min-w-0 flex-1 truncate text-base font-medium">{{ personName(request) }}</span>
          </div>
          <div class="flex w-full gap-2">
            <button
              type="button"
              class="btn btn-tinted btn-sm flex-1"
              data-testid="accept-btn"
              :disabled="store.isBusy(`f${request.id}`)"
              @click="answerFriend('accept', request.id)"
            >
              {{ t('friends.incoming.accept') }}
            </button>
            <button
              type="button"
              class="btn btn-secondary btn-sm flex-1"
              data-testid="decline-btn"
              :disabled="store.isBusy(`f${request.id}`)"
              @click="answerFriend('decline', request.id)"
            >
              {{ t('friends.incoming.decline') }}
            </button>
          </div>
        </div>
      </ListGroup>

      <ListGroup v-if="friends.outgoing.length > 0" :header="t('friends.outgoing.title')" data-testid="outgoing">
        <div v-for="request in friends.outgoing" :key="request.id" class="list-row" data-testid="outgoing-row">
          <InitialAvatar :name="personName(request)" />
          <span class="min-w-0 flex-1 truncate text-base">{{ personName(request) }}</span>
          <button
            type="button"
            class="btn btn-plain btn-sm shrink-0"
            data-testid="cancel-request-btn"
            :disabled="store.isBusy(`f${request.id}`)"
            @click="answerFriend('remove', request.id)"
          >
            {{ t('friends.outgoing.cancel') }}
          </button>
        </div>
      </ListGroup>

      <section data-testid="friends">
        <div class="section-label flex items-baseline justify-between gap-3">
          <h2>{{ t('friends.list.title') }}</h2>
          <span class="tracking-normal normal-case" data-testid="friends-count">
            {{ t('friends.list.count', { count: friends.friends.length, limit: friends.limit }) }}
          </span>
        </div>
        <div class="list">
          <EmptyState v-if="friends.friends.length === 0" icon="users" :text="t('friends.list.empty')" data-testid="friends-empty" />

          <div v-for="friend in friends.friends" :key="friend.id" class="list-row flex-wrap gap-y-3" data-testid="friend-row">
            <InitialAvatar :name="personName(friend)" />
            <div class="min-w-0 flex-1">
              <div class="truncate text-base leading-[22px] font-medium">{{ personName(friend) }}</div>
              <div class="truncate text-[13px] leading-[18px] text-tg-hint" data-testid="friend-subtitle">{{ friendSubtitle(friend) }}</div>
            </div>

            <div v-if="picking === friend.id" class="w-full space-y-2" data-testid="metric-picker">
              <p class="text-[13px] leading-[18px] text-tg-hint">{{ t('friends.challenge.pick', { days: challenges?.days ?? 7 }) }}</p>
              <button
                v-for="metric in METRICS"
                :key="metric"
                type="button"
                class="btn btn-tinted btn-block"
                :data-testid="`metric-${metric}`"
                :disabled="store.isBusy(`f${friend.id}`)"
                @click="propose(friend.id, metric)"
              >
                {{ metricLabel(metric) }}
              </button>
              <button type="button" class="btn btn-plain btn-block" data-testid="picker-cancel" @click="picking = null">
                {{ t('friends.challenge.cancel') }}
              </button>
            </div>

            <div v-else-if="removing === friend.id" class="w-full space-y-3" data-testid="remove-confirm">
              <p class="text-[15px] leading-5">{{ t('friends.list.remove_confirm', { name: personName(friend) }) }}</p>
              <div class="flex gap-2">
                <button
                  type="button"
                  class="btn btn-danger flex-1"
                  data-testid="remove-yes"
                  :disabled="store.isBusy(`f${friend.id}`)"
                  @click="answerFriend('remove', friend.id)"
                >
                  {{ t('friends.list.remove_yes') }}
                </button>
                <button type="button" class="btn btn-secondary flex-1" data-testid="remove-no" @click="removing = null">
                  {{ t('friends.list.remove_no') }}
                </button>
              </div>
            </div>

            <div v-else class="flex shrink-0 items-center gap-1">
              <button
                v-if="!withOpenChallenge.has(friend.id)"
                type="button"
                class="btn btn-tinted size-11 min-h-11 rounded-full p-0"
                :aria-label="t('friends.challenge.new')"
                data-testid="challenge-btn"
                @click="picking = friend.id"
              >
                <AppIcon name="bolt" :size="20" />
              </button>
              <button
                type="button"
                class="btn btn-plain size-11 min-h-11 rounded-full p-0 text-tg-hint"
                :aria-label="t('friends.list.remove')"
                data-testid="remove-btn"
                @click="removing = friend.id"
              >
                <AppIcon name="trash" :size="20" />
              </button>
            </div>
          </div>
        </div>
      </section>

      <section data-testid="challenges">
        <template v-if="openChallenges.length > 0 || finishedChallenges.length === 0">
          <h2 class="section-label">{{ t('friends.challenge.title') }}</h2>
          <div v-if="openChallenges.length === 0" class="list">
            <EmptyState icon="bolt" :text="t('friends.challenge.empty')" data-testid="challenges-empty" />
          </div>
          <div v-else class="list" data-testid="open-challenges">
            <div v-for="c in openChallenges" :key="c.id" class="list-row flex-wrap gap-y-2" data-testid="challenge-row">
              <div class="min-w-0 flex-1">
                <div class="text-base leading-[22px] font-medium">{{ metricLabel(c.metric) }}</div>
                <div class="truncate text-[13px] leading-[18px] text-tg-hint">
                  {{ t('friends.challenge.with', { name: c.friend_name || t('friends.list.anonymous') }) }}
                </div>
              </div>
              <span v-if="c.my_score !== null" class="shrink-0 text-[17px] leading-[22px] font-semibold tabular-nums" data-testid="challenge-score">
                {{ score(c) }}
              </span>
              <!-- My share of the points of both; the score beside it says the same in words. -->
              <div v-if="myShare(c) !== null" class="progress h-1.5" aria-hidden="true">
                <div class="progress-fill" :style="{ width: `${myShare(c)}%` }" data-testid="challenge-bar" />
              </div>
              <div class="flex w-full flex-wrap items-center gap-x-2 gap-y-1">
                <span class="chip" :class="statusChip(c).chip" data-testid="challenge-status">{{ t(statusChip(c).label) }}</span>
                <span v-if="period(c)" class="text-[13px] leading-[18px] text-tg-hint" data-testid="challenge-period">{{ period(c) }}</span>
              </div>
              <div v-if="c.status === 'pending' && c.role === 'opponent'" class="flex w-full gap-2">
                <button
                  type="button"
                  class="btn btn-tinted btn-sm flex-1"
                  data-testid="challenge-accept"
                  :disabled="store.isBusy(`c${c.id}`)"
                  @click="store.answerChallenge('accept', c.id)"
                >
                  {{ t('friends.challenge.accept') }}
                </button>
                <button
                  type="button"
                  class="btn btn-secondary btn-sm flex-1"
                  data-testid="challenge-decline"
                  :disabled="store.isBusy(`c${c.id}`)"
                  @click="store.answerChallenge('decline', c.id)"
                >
                  {{ t('friends.challenge.decline') }}
                </button>
              </div>
            </div>
          </div>
        </template>

        <template v-if="finishedChallenges.length > 0">
          <h2 class="section-label">{{ t('friends.challenge.finished_title') }}</h2>
          <div class="list" data-testid="finished-challenges">
            <div v-for="c in finishedChallenges" :key="c.id" class="list-row" data-testid="finished-row">
              <div class="min-w-0 flex-1">
                <div class="text-base leading-[22px] font-medium">{{ metricLabel(c.metric) }}</div>
                <div class="mt-0.5 flex items-center gap-2">
                  <span class="min-w-0 truncate text-[13px] leading-[18px] text-tg-hint">
                    {{ t('friends.challenge.with', { name: c.friend_name || t('friends.list.anonymous') }) }}
                  </span>
                  <span v-if="c.result" class="chip shrink-0" :class="RESULT_CHIPS[c.result].chip" data-testid="finished-result">
                    {{ t(RESULT_CHIPS[c.result].label) }}
                  </span>
                </div>
              </div>
              <span class="shrink-0 text-[17px] leading-[22px] font-semibold tabular-nums" data-testid="finished-score">{{ score(c) }}</span>
            </div>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>
