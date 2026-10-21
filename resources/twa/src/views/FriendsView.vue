<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useFriendsStore } from '@/stores/friends'
import { useTelegram } from '@/composables/useTelegram'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { ChallengeItem, ChallengeMetric } from '@/api/types'

/**
 * "Friends" (docs/13 §7.5, docs/04 A.13): the invite link (`f_<code>`) with Telegram's share dialog, incoming
 * requests, the friends with their streak and learned words, challenges ("who repeats more") and their state.
 */
const router = useRouter()
const auth = useAuthStore()
const store = useFriendsStore()
const { showBackButton, shareUrl, haptic } = useTelegram()
const { t, locale } = useI18n()

const failed = ref('')
const copied = ref(false)
/** Friendship id whose challenge picker / removal confirmation is open. */
const picking = ref<number | null>(null)
const removing = ref<number | null>(null)
let hideBack: (() => void) | null = null

const friends = computed(() => store.friends)
const challenges = computed(() => store.challenges)
const message = computed(() => failed.value || store.errorMessage)

const METRICS: ChallengeMetric[] = ['reviews', 'new_words']

/** Friendships that already have an open challenge: a second one would be refused. */
const withOpenChallenge = computed(
  () => new Set((challenges.value?.open ?? []).map((c) => c.friendship_id).filter((id): id is number => id !== null)),
)

function formatDate(iso: string | null): string {
  if (!iso) return ''
  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }).format(new Date(iso))
}

function metricLabel(metric: ChallengeMetric): string {
  return t(`friends.challenge.metric.${metric}` as StringKey)
}

function statusLabel(c: ChallengeItem): string {
  if (c.status === 'pending') {
    return t(c.role === 'challenger' ? 'friends.challenge.status.pending_sent' : 'friends.challenge.status.pending_received')
  }
  return t(`friends.challenge.status.${c.status}` as StringKey)
}

function share(): void {
  if (!friends.value?.invite_link) return
  haptic('light')
  shareUrl(friends.value.invite_link, t('friends.invite.share_text'))
}

async function copy(): Promise<void> {
  if (!friends.value?.invite_link) return
  try {
    await navigator.clipboard.writeText(friends.value.invite_link)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    /* clipboard unavailable: the link is visible on the screen anyway */
  }
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
  hideBack = showBackButton(() => void router.push({ name: 'referrals' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <h1 class="text-xl font-bold mb-4">👥 {{ t('friends.title') }}</h1>

    <div v-if="!friends && !message" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="!friends" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ message }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <div v-else class="space-y-3">
      <p v-if="store.actionError" class="card text-sm text-red-600" data-testid="action-error">{{ store.actionError }}</p>

      <div class="card space-y-3" data-testid="invite">
        <div class="font-semibold">{{ t('friends.invite.title') }}</div>
        <p class="text-sm text-tg-hint">{{ t('friends.invite.hint') }}</p>
        <template v-if="friends.invite_link">
          <div class="font-mono text-sm break-all" data-testid="link">{{ friends.invite_link }}</div>
          <button type="button" class="btn btn-primary w-full" data-testid="share-btn" @click="share">
            {{ t('friends.invite.share') }}
          </button>
          <button type="button" class="btn btn-secondary w-full" data-testid="copy-btn" @click="copy">
            {{ copied ? '✓ ' + t('friends.invite.copied') : t('friends.invite.copy') }}
          </button>
        </template>
        <p v-else class="text-sm text-tg-hint" data-testid="no-link">{{ t('friends.invite.no_link') }}</p>
      </div>

      <div v-if="friends.incoming.length > 0" class="card space-y-2" data-testid="incoming">
        <div class="font-semibold">{{ t('friends.incoming.title') }}</div>
        <ul class="divide-y">
          <li v-for="request in friends.incoming" :key="request.id" class="py-2 space-y-2" data-testid="incoming-row">
            <div class="text-sm">{{ request.first_name || t('friends.list.anonymous') }}</div>
            <div class="flex gap-2">
              <button type="button" class="btn btn-primary flex-1" data-testid="accept-btn" :disabled="store.isBusy(`f${request.id}`)" @click="answerFriend('accept', request.id)">
                {{ t('friends.incoming.accept') }}
              </button>
              <button type="button" class="btn btn-secondary flex-1" data-testid="decline-btn" :disabled="store.isBusy(`f${request.id}`)" @click="answerFriend('decline', request.id)">
                {{ t('friends.incoming.decline') }}
              </button>
            </div>
          </li>
        </ul>
      </div>

      <div v-if="friends.outgoing.length > 0" class="card space-y-2" data-testid="outgoing">
        <div class="font-semibold">{{ t('friends.outgoing.title') }}</div>
        <ul class="divide-y">
          <li v-for="request in friends.outgoing" :key="request.id" class="flex items-center justify-between py-2 text-sm" data-testid="outgoing-row">
            <span>{{ request.first_name || t('friends.list.anonymous') }}</span>
            <button type="button" class="text-tg-hint underline" data-testid="cancel-request-btn" :disabled="store.isBusy(`f${request.id}`)" @click="answerFriend('remove', request.id)">
              {{ t('friends.outgoing.cancel') }}
            </button>
          </li>
        </ul>
      </div>

      <div class="card space-y-2" data-testid="friends">
        <div class="flex items-baseline justify-between">
          <div class="font-semibold">{{ t('friends.list.title') }}</div>
          <div class="text-xs text-tg-hint" data-testid="friends-count">{{ t('friends.list.count', { count: friends.friends.length, limit: friends.limit }) }}</div>
        </div>
        <p v-if="friends.friends.length === 0" class="text-sm text-tg-hint" data-testid="friends-empty">{{ t('friends.list.empty') }}</p>
        <ul v-else class="divide-y">
          <li v-for="friend in friends.friends" :key="friend.id" class="py-2 space-y-2" data-testid="friend-row">
            <div class="flex items-center justify-between text-sm">
              <span class="font-medium">{{ friend.first_name || t('friends.list.anonymous') }}</span>
              <span class="text-tg-hint">
                🔥 {{ t('friends.list.streak', { n: friend.streak }) }} · {{ t('friends.list.words', { n: friend.words_learned }) }}
              </span>
            </div>

            <div v-if="picking === friend.id" class="space-y-2" data-testid="metric-picker">
              <div class="text-xs text-tg-hint">{{ t('friends.challenge.pick', { days: challenges?.days ?? 7 }) }}</div>
              <button v-for="metric in METRICS" :key="metric" type="button" class="btn btn-primary w-full" :data-testid="`metric-${metric}`" :disabled="store.isBusy(`f${friend.id}`)" @click="propose(friend.id, metric)">
                {{ metricLabel(metric) }}
              </button>
              <button type="button" class="btn btn-secondary w-full" data-testid="picker-cancel" @click="picking = null">{{ t('friends.challenge.cancel') }}</button>
            </div>

            <div v-else-if="removing === friend.id" class="space-y-2" data-testid="remove-confirm">
              <p class="text-sm">{{ t('friends.list.remove_confirm', { name: friend.first_name || t('friends.list.anonymous') }) }}</p>
              <div class="flex gap-2">
                <button type="button" class="btn btn-primary flex-1" data-testid="remove-yes" :disabled="store.isBusy(`f${friend.id}`)" @click="answerFriend('remove', friend.id)">
                  {{ t('friends.list.remove_yes') }}
                </button>
                <button type="button" class="btn btn-secondary flex-1" data-testid="remove-no" @click="removing = null">{{ t('friends.list.remove_no') }}</button>
              </div>
            </div>

            <div v-else class="flex gap-2">
              <button v-if="!withOpenChallenge.has(friend.id)" type="button" class="btn btn-secondary flex-1" data-testid="challenge-btn" @click="picking = friend.id">
                ⚔️ {{ t('friends.challenge.new') }}
              </button>
              <button type="button" class="btn btn-secondary flex-1" data-testid="remove-btn" @click="removing = friend.id">
                {{ t('friends.list.remove') }}
              </button>
            </div>
          </li>
        </ul>
      </div>

      <div class="card space-y-2" data-testid="challenges">
        <div class="font-semibold">⚔️ {{ t('friends.challenge.title') }}</div>
        <p v-if="!challenges || (challenges.open.length === 0 && challenges.finished.length === 0)" class="text-sm text-tg-hint" data-testid="challenges-empty">
          {{ t('friends.challenge.empty') }}
        </p>
        <ul v-if="challenges && challenges.open.length > 0" class="divide-y" data-testid="open-challenges">
          <li v-for="c in challenges.open" :key="c.id" class="py-2 space-y-1" data-testid="challenge-row">
            <div class="flex items-center justify-between text-sm">
              <span class="font-medium">{{ metricLabel(c.metric) }} · {{ t('friends.challenge.with', { name: c.friend_name || t('friends.list.anonymous') }) }}</span>
              <span v-if="c.my_score !== null" class="font-mono" data-testid="challenge-score">{{ t('friends.challenge.score', { mine: c.my_score, theirs: c.their_score ?? 0 }) }}</span>
            </div>
            <div class="text-xs text-tg-hint" data-testid="challenge-status">
              {{ statusLabel(c) }}<template v-if="c.starts_on && c.ends_on"> · {{ t('friends.challenge.period', { from: formatDate(c.starts_on), to: formatDate(c.ends_on) }) }}</template>
            </div>
            <div v-if="c.status === 'pending' && c.role === 'opponent'" class="flex gap-2">
              <button type="button" class="btn btn-primary flex-1" data-testid="challenge-accept" :disabled="store.isBusy(`c${c.id}`)" @click="store.answerChallenge('accept', c.id)">
                {{ t('friends.challenge.accept') }}
              </button>
              <button type="button" class="btn btn-secondary flex-1" data-testid="challenge-decline" :disabled="store.isBusy(`c${c.id}`)" @click="store.answerChallenge('decline', c.id)">
                {{ t('friends.challenge.decline') }}
              </button>
            </div>
          </li>
        </ul>
        <template v-if="challenges && challenges.finished.length > 0">
          <div class="text-xs text-tg-hint pt-1">{{ t('friends.challenge.finished_title') }}</div>
          <ul class="divide-y" data-testid="finished-challenges">
            <li v-for="c in challenges.finished" :key="c.id" class="flex items-center justify-between py-2 text-sm" data-testid="finished-row">
              <span>{{ metricLabel(c.metric) }} · {{ t('friends.challenge.with', { name: c.friend_name || t('friends.list.anonymous') }) }}</span>
              <span class="text-tg-hint">
                <template v-if="c.result">{{ t(`friends.challenge.result.${c.result}` as StringKey) }} · </template>{{ t('friends.challenge.score', { mine: c.my_score ?? 0, theirs: c.their_score ?? 0 }) }}
              </span>
            </li>
          </ul>
        </template>
      </div>
    </div>
  </section>
</template>
