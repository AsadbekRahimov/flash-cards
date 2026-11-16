<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import InitialAvatar from '@/components/InitialAvatar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useReferralsStore } from '@/stores/referrals'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { ReferralStatus } from '@/api/types'

/**
 * "Invite a friend" (docs/13 §7.1, docs/14 §6.7, docs/04 A.12): the hero "invite friends - get Pro", the progress to the
 * milestones (friends counted, 3 -> +7 days, 6 -> +7 days: all the numbers are the server's), the three steps of the
 * rule, the learner's invite link with Telegram's share dialog, the counts and the invited learners (first names only).
 * Back -> Profile (or Today / Billing, where `?from=` says the learner came from); the way to the friends and challenges is the row at the bottom.
 */
const auth = useAuthStore()
const referrals = useReferralsStore()
const { shareUrl, haptic } = useTelegram()
const { copy } = useCopyToClipboard()
const { t, tn, locale } = useI18n()

/** Opened from Today or Billing (`?from=`) goes back there; from the Profile (no marker) back to the Profile. */
const route = useRoute()
useBackButton({ name: route.query.from === 'today' ? 'today' : route.query.from === 'billing' ? 'billing' : 'profile' })

const failed = ref('')

const data = computed(() => referrals.data)
const message = computed(() => failed.value || referrals.errorMessage)

/** 1. send the link, 2. the friend starts studying, 3. the milestones pay Pro to the inviter. */
const steps = computed<string[]>(() => {
  const d = data.value
  if (!d) return []
  const { days, reviews_per_day: reviews, window_days: window } = d.activation
  const reward = d.milestones.map((m, index) =>
    t(index === 0 ? 'referrals.step3.first' : 'referrals.step3.more', { friends: tn('referrals.for_friends', m.friends), days: m.days }),
  )
  return [
    t('referrals.step1'),
    t(days > 1 ? 'referrals.step2.days' : 'referrals.step2.day', { days, reviews, window }),
    reward.join(', '),
  ]
})

/** The dots of the progress: one per friend up to the last milestone; a ladder too long for one line at 390 px has no dots (the milestones below stay). */
const MAX_DOTS = 8

interface Dot {
  number: number
  filled: boolean
  milestone: boolean
}

const dots = computed<Dot[]>(() => {
  const d = data.value
  if (!d) return []
  const total = Math.max(0, ...d.milestones.map((m) => m.friends))
  if (total > MAX_DOTS) return []
  const marks = new Set(d.milestones.map((m) => m.friends))
  return Array.from({ length: total }, (_, index) => ({
    number: index + 1,
    filled: index < d.counts.counted,
    milestone: marks.has(index + 1),
  }))
})

/** What the line under the dots says: the next step, or thanks once the last milestone is paid. */
const nextText = computed(() => {
  const d = data.value
  if (!d) return ''
  if (d.next === null) return t('referrals.next.done')
  const target = d.next.friends
  return tn('referrals.next', d.next.remaining, { days: d.milestones.find((m) => m.friends === target)?.days ?? d.milestone_days })
})

const STATUSES: Record<ReferralStatus, { label: StringKey; chip: string }> = {
  pending: { label: 'referrals.status.pending', chip: 'chip-neutral' },
  activated: { label: 'referrals.status.activated', chip: 'chip-accent' },
  rewarded: { label: 'referrals.status.rewarded', chip: 'chip-success' },
}

const COUNTS: { key: 'invited' | 'activated' | 'counted'; label: StringKey }[] = [
  { key: 'invited', label: 'referrals.counts.invited' },
  { key: 'activated', label: 'referrals.counts.activated' },
  { key: 'counted', label: 'referrals.counts.counted' },
]

function share(): void {
  if (!data.value?.link) return
  haptic('light')
  shareUrl(data.value.link, t('referrals.share_text'))
}

async function copyLink(): Promise<void> {
  if (!data.value?.link) return
  await copy(data.value.link)
}

async function load(): Promise<void> {
  failed.value = ''
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  await referrals.load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="referrals-view">
    <PageHeader :title="t('referrals.title')" />

    <LoadingState v-if="!data && !message" />

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <div v-else class="space-y-3">
      <section class="card" data-testid="hero">
        <div class="flex items-center gap-3">
          <span class="icon-tile tile-pink size-12 rounded-2xl"><AppIcon name="gift" :size="26" /></span>
          <h2 class="min-w-0 flex-1 text-xl leading-6 font-bold" data-testid="hero-title">{{ t('referrals.hero') }}</h2>
        </div>

        <div class="mt-4" data-testid="progress">
          <ol
            v-if="dots.length > 0"
            class="flex items-center justify-between gap-1"
            role="img"
            :aria-label="t('referrals.progress.label', { counted: data.counts.counted, total: dots.length })"
            data-testid="progress-dots"
          >
            <li
              v-for="dot in dots"
              :key="dot.number"
              class="flex size-8 shrink-0 items-center justify-center rounded-full text-[13px] leading-none font-semibold"
              :class="[
                dot.filled ? 'bg-accent text-tg-button-text' : 'bg-fill text-tg-hint',
                dot.milestone ? 'ring-2 ring-accent ring-offset-2 ring-offset-tg-section-bg' : '',
              ]"
              :data-filled="dot.filled ? 'true' : 'false'"
              :data-milestone="dot.milestone ? 'true' : 'false'"
              data-testid="progress-dot"
            >
              <AppIcon v-if="dot.filled" name="check" :size="18" />
              <span v-else aria-hidden="true">{{ dot.number }}</span>
            </li>
          </ol>

          <ul class="mt-3 space-y-1.5" data-testid="milestones">
            <li
              v-for="m in data.milestones"
              :key="m.friends"
              class="flex items-center justify-between gap-2 text-[15px] leading-5"
              :data-testid="`milestone-${m.friends}`"
            >
              <span class="min-w-0 font-semibold">
                {{ t('referrals.milestone.caption', { friends: tn('referrals.friends', m.friends), days: m.days }) }}
              </span>
              <span
                v-if="m.reached_at !== null"
                class="chip shrink-0"
                :class="m.rewarded ? 'chip-success' : 'chip-accent'"
                data-testid="milestone-done"
              >
                <AppIcon name="check" :size="14" />
                {{ t(m.rewarded ? 'referrals.milestone.done' : 'referrals.milestone.paying') }}
              </span>
            </li>
          </ul>

          <p class="mt-3 text-[15px] leading-5 text-tg-subtitle" data-testid="next-step">{{ nextText }}</p>
        </div>

        <ol class="mt-4 space-y-3" data-testid="rule">
          <li v-for="(step, index) in steps" :key="index" class="flex items-start gap-3">
            <span
              class="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-[13px] leading-none font-semibold text-accent-ink"
              aria-hidden="true"
            >
              {{ index + 1 }}
            </span>
            <span class="text-[15px] leading-6">{{ step }}</span>
          </li>
        </ol>

        <p class="mt-3 text-[13px] leading-[18px] text-tg-hint" data-testid="friend-gets">{{ t('referrals.friend_gets') }}</p>

        <template v-if="data.link">
          <p class="mt-4 text-[13px] leading-[18px] text-tg-hint">{{ t('referrals.link_label') }}</p>
          <!-- Read-only field: the link wraps by characters and is selected with one tap, so it can be copied by hand too. -->
          <div class="field mt-1.5 font-mono text-[13px] leading-[18px] break-all select-all" data-testid="link">{{ data.link }}</div>
          <div class="mt-3 space-y-2">
            <button type="button" class="btn btn-primary btn-block" data-testid="share-btn" @click="share">
              {{ t('referrals.share') }}
            </button>
            <button type="button" class="btn btn-tinted btn-block" data-testid="copy-btn" @click="copyLink">
              {{ t('referrals.copy') }}
            </button>
          </div>
        </template>
        <template v-else>
          <p class="mt-4 text-[13px] leading-[18px] text-tg-hint">{{ t('referrals.code_label') }}</p>
          <div class="field mt-1.5 font-mono text-lg select-all" data-testid="code">{{ data.code }}</div>
          <p class="mt-3 text-[15px] leading-5 text-tg-subtitle" data-testid="no-link">{{ t('referrals.no_link', { code: data.code }) }}</p>
        </template>
      </section>

      <div class="grid grid-cols-3 gap-2" data-testid="counts">
        <div v-for="count in COUNTS" :key="count.key" class="card flex flex-col items-center px-1.5 py-3 text-center">
          <div class="text-2xl leading-8 font-bold tabular-nums" :data-testid="`count-${count.key}`">{{ data.counts[count.key] }}</div>
          <div class="mt-0.5 text-xs leading-4 break-words text-tg-hint">{{ t(count.label) }}</div>
        </div>
      </div>

      <ListGroup :header="t('referrals.list.title')" :data-testid="data.referrals.length > 0 ? 'list' : undefined">
        <EmptyState v-if="data.referrals.length === 0" icon="user-plus" :text="t('referrals.list.empty')" data-testid="list-empty" />
        <ListRow
          v-for="(item, index) in data.referrals"
          :key="index"
          :title="item.first_name || t('referrals.list.anonymous')"
          :subtitle="formatDate(item.linked_at, 'dayMonthShort', locale)"
          data-testid="referral-row"
        >
          <template #leading>
            <InitialAvatar :name="item.first_name || t('referrals.list.anonymous')" size="sm" />
          </template>
          <template #trailing>
            <span class="chip shrink-0" :class="STATUSES[item.status].chip" data-testid="referral-status">
              {{ t(STATUSES[item.status].label) }}
            </span>
          </template>
        </ListRow>
      </ListGroup>

      <ListGroup>
        <ListRow :to="{ name: 'friends' }" icon="users" tile="blue" :title="t('friends.open')" data-testid="friends-link" />
      </ListGroup>
    </div>
  </div>
</template>
